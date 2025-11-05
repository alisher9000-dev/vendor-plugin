<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Importer {
    private $db_schema;
    private $lock;

    public function __construct() {
        $this->db_schema = new DbSchema();
        $this->lock = new Lock();
        add_action('wp_ajax_cbvr_cancel_import', [$this, 'handle_cancel_import']);
    }

    public function process_upload($file) {
        if ($this->lock->is_locked('csv_import')) {
            return new \WP_Error('import_locked', 'Another import is currently in progress');
        }

        if (!$this->lock->acquire('csv_import', 3600)) {
            return new \WP_Error('import_locked', 'Could not acquire import lock');
        }

        try {
            $file_hash = md5_file($file['tmp_name']);
            $total_rows = $this->count_csv_rows($file['tmp_name']);
            
            if ($total_rows === 0) {
                $this->lock->release('csv_import');
                return new \WP_Error('empty_file', 'CSV file is empty or invalid');
            }

            $import_id = $this->create_import_record($file['name'], $file_hash, $total_rows);
            
            if (is_wp_error($import_id)) {
                $this->lock->release('csv_import');
                return $import_id;
            }

            $upload_dir = wp_upload_dir();
            $temp_file = $upload_dir['path'] . '/cbvr_import_' . $import_id . '_' . $file_hash . '.csv';
            
            if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
                $this->delete_import_record($import_id);
                $this->lock->release('csv_import');
                return new \WP_Error('file_move', 'Could not move uploaded file');
            }

            $result = $this->process_entire_file($import_id, $temp_file, $total_rows);
            
            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            return $import_id;

        } catch (\Exception $e) {
            $this->lock->release('csv_import');
            return new \WP_Error('import_error', $e->getMessage());
        }
    }

    private function process_entire_file($import_id, $file_path, $total_rows) {
        global $wpdb;
        
        $this->update_import_status($import_id, 'processing', 0);

        try {
            if (!file_exists($file_path)) {
                throw new \Exception('CSV file not found: ' . $file_path);
            }

            $handle = fopen($file_path, 'r');
            if (!$handle) {
                throw new \Exception('Cannot open CSV file');
            }

            $header = fgetcsv($handle);
            
            if (!$this->validate_header($header)) {
                fclose($handle);
                throw new \Exception('Invalid CSV header. Expected: email,name,skills,rate,currency,avg_rating,completed_projects,plan_code');
            }

            $processed = 0;
            $batch_data = [];
            $batch_size = 100;

            while (($row = fgetcsv($handle)) !== FALSE) {
                if ($this->is_import_cancelled($import_id)) {
                    fclose($handle);
                    $this->cleanup_import($import_id, $file_path);
                    return new \WP_Error('cancelled', 'Import was cancelled');
                }
                
                $parsed_row = $this->parse_csv_row($row);
                if ($parsed_row && !empty($parsed_row['email'])) {
                    $batch_data[] = $parsed_row;
                }
                $processed++;

                if (count($batch_data) >= $batch_size || feof($handle)) {
                    if (!empty($batch_data)) {
                        $batch_result = $this->process_batch_transactionally($import_id, $batch_data, $processed);
                        if (is_wp_error($batch_result)) {
                            throw new \Exception($batch_result->get_error_message());
                        }
                    }
                    $batch_data = [];
                    $this->update_import_progress($import_id, $processed, $total_rows);
                }
            }

            fclose($handle);

            if (!empty($batch_data)) {
                $batch_result = $this->process_batch_transactionally($import_id, $batch_data, $processed);
                if (is_wp_error($batch_result)) {
                    throw new \Exception($batch_result->get_error_message());
                }
            }

            $this->update_import_status($import_id, 'completed', $processed);
            $this->lock->release('csv_import');
            $this->cleanup_file($file_path);

            return true;

        } catch (\Exception $e) {
            if (isset($handle) && $handle) {
                fclose($handle);
            }
            
            $this->update_import_status($import_id, 'failed', $processed, $e->getMessage());
            $this->lock->release('csv_import');
            $this->cleanup_file($file_path);
            
            return new \WP_Error('processing_error', $e->getMessage());
        }
    }

    private function validate_header($header) {
        $expected = ['email', 'name', 'skills', 'rate', 'currency', 'avg_rating', 'completed_projects', 'plan_code'];
        
        if (count($header) < count($expected)) {
            return false;
        }
        
        for ($i = 0; $i < min(3, count($expected)); $i++) {
            if (strtolower(trim($header[$i])) !== $expected[$i]) {
                return false;
            }
        }
        
        return true;
    }

    private function process_batch_transactionally($import_id, $batch_data, $current_count) {
        global $wpdb;
        
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($batch_data as $row_data) {
                if (!empty($row_data['email'])) {
                    $this->upsert_vendor($row_data);
                }
            }
            
            $wpdb->query('COMMIT');
            return true;
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('batch_error', 'Failed to process batch: ' . $e->getMessage());
        }
    }

    private function upsert_vendor($data) {
        global $wpdb;
        
        $vendors_table = $this->db_schema->get_table_name('vendors');
        $skills_table = $this->db_schema->get_table_name('skills');
        $vendor_skills_table = $this->db_schema->get_table_name('vendor_skills');
        
        if (empty($data['email']) || empty($data['name'])) {
            return false;
        }
        
        if (!is_email($data['email'])) {
            return false;
        }

        $vendor_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $vendors_table WHERE email = %s",
            $data['email']
        ));
        
        if ($vendor_id) {
            $wpdb->update(
                $vendors_table,
                [
                    'name' => $data['name'],
                    'rate' => $data['rate'],
                    'currency' => $data['currency'],
                    'avg_rating' => $data['avg_rating'],
                    'completed_projects' => $data['completed_projects'],
                    'plan_code' => $data['plan_code'],
                    'is_active' => 1,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $vendor_id]
            );
        } else {
            $wpdb->insert(
                $vendors_table,
                [
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'rate' => $data['rate'],
                    'currency' => $data['currency'],
                    'avg_rating' => $data['avg_rating'],
                    'completed_projects' => $data['completed_projects'],
                    'plan_code' => $data['plan_code'],
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]
            );
            $vendor_id = $wpdb->insert_id;
        }
        
        if ($vendor_id && !empty($data['skills'])) {
            $wpdb->delete($vendor_skills_table, ['vendor_id' => $vendor_id]);
            
            foreach ($data['skills'] as $skill_name) {
                if (empty(trim($skill_name))) continue;
                
                $skill_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $skills_table WHERE normalized_name = %s",
                    $skill_name
                ));
                
                if (!$skill_id) {
                    $wpdb->insert(
                        $skills_table,
                        [
                            'name' => $skill_name,
                            'normalized_name' => $skill_name,
                            'created_at' => current_time('mysql')
                        ]
                    );
                    $skill_id = $wpdb->insert_id;
                }
                
                $wpdb->insert(
                    $vendor_skills_table,
                    [
                        'vendor_id' => $vendor_id,
                        'skill_id' => $skill_id,
                        'created_at' => current_time('mysql')
                    ]
                );
            }
        }
        
        return $vendor_id;
    }

    private function create_import_record($filename, $file_hash, $total_rows) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        
        $unique_hash = $file_hash . '_' . time();
        
        $result = $wpdb->insert($imports_table, [
            'filename' => sanitize_file_name($filename),
            'file_hash' => $unique_hash,
            'total_rows' => $total_rows,
            'status' => 'pending',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);

        if (!$result) {
            return new \WP_Error('db_error', 'Failed to create import record: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    private function parse_csv_row($row) {
        if (empty($row) || count($row) < 8) {
            return null;
        }

        if (empty(trim(implode('', $row)))) {
            return null;
        }

        $parsed = [
            'email' => sanitize_email(trim($row[0] ?? '')),
            'name' => sanitize_text_field(trim($row[1] ?? '')),
            'skills' => $this->normalize_skills($row[2] ?? ''),
            'rate' => $this->parse_float($row[3] ?? 0),
            'currency' => sanitize_text_field(trim($row[4] ?? 'USD')),
            'avg_rating' => $this->parse_float($row[5] ?? 0),
            'completed_projects' => intval($row[6] ?? 0),
            'plan_code' => sanitize_text_field(trim($row[7] ?? ''))
        ];

        if (empty($parsed['email']) || !is_email($parsed['email'])) {
            return null;
        }

        if (empty($parsed['name'])) {
            return null;
        }

        return $parsed;
    }

    private function parse_float($value) {
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.]/', '', $value);
        
        return floatval($value);
    }

    private function normalize_skills($skills_string) {
        if (empty($skills_string)) {
            return [];
        }
        
        $skills = array_map('trim', explode(',', $skills_string));
        $normalized_skills = [];
        
        foreach ($skills as $skill) {
            $normalized = strtolower(sanitize_text_field($skill));
            $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
            $normalized = ucwords(trim($normalized));
            
            if (!empty($normalized)) {
                $normalized_skills[] = $normalized;
            }
        }
        
        return array_unique($normalized_skills);
    }

    private function count_csv_rows($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) return 0;

        $count = 0;
        while (fgetcsv($handle) !== FALSE) $count++;
        fclose($handle);

        return max(0, $count - 1);
    }

    private function delete_import_record($import_id) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        return $wpdb->delete($imports_table, ['id' => $import_id]);
    }

    private function update_import_progress($import_id, $processed, $total_rows) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        
        $wpdb->update(
            $imports_table,
            ['processed_rows' => $processed, 'updated_at' => current_time('mysql')],
            ['id' => $import_id]
        );
        
        wp_cache_delete($import_id, $imports_table);
    }

    private function is_import_cancelled($import_id) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $imports_table WHERE id = %d", $import_id));
        return $status === 'cancelled';
    }

    public function handle_cancel_import() {
        check_ajax_referer('cbvr_import_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions');

        $import_id = absint($_POST['import_id'] ?? 0);
        if (!$import_id) wp_send_json_error('Invalid import ID');

        $result = $this->cancel_import($import_id);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success('Import cancelled successfully');
    }

    public function cancel_import($import_id) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        
        $result = $wpdb->update(
            $imports_table,
            ['status' => 'cancelled', 'updated_at' => current_time('mysql')],
            ['id' => $import_id]
        );

        if ($result === false) return new \WP_Error('db_error', 'Failed to cancel import');

        $this->lock->release('csv_import');
        return true;
    }

    private function cleanup_import($import_id, $file_path) {
        $this->update_import_status($import_id, 'cancelled');
        $this->lock->release('csv_import');
        $this->cleanup_file($file_path);
    }

    private function cleanup_file($file_path) {
        if (file_exists($file_path)) @unlink($file_path);
    }

    private function update_import_status($import_id, $status, $processed_rows = null, $error_message = null) {
        global $wpdb;
        $imports_table = $this->db_schema->get_table_name('imports');
        
        $update_data = ['status' => $status, 'updated_at' => current_time('mysql')];
        if ($processed_rows !== null) $update_data['processed_rows'] = $processed_rows;
        if ($error_message !== null) $update_data['error_message'] = $error_message;
        
        $wpdb->update($imports_table, $update_data, ['id' => $import_id]);
    }
}