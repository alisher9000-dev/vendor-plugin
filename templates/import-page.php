<?php
if (!defined('ABSPATH'))
    exit;

global $wpdb;
$db_schema = new CB\VendorRegistry\vendor123\Services\DbSchema();
$imports_table = $db_schema->get_table_name('imports');

$stuck_imports = $wpdb->get_results(
    $wpdb->prepare("
        SELECT * FROM $imports_table 
        WHERE status IN ('pending', 'processing') 
        AND created_at < DATE_SUB(%s, INTERVAL 5 MINUTE)
        ORDER BY created_at DESC
    ", current_time('mysql'))
);

$lock_service = new CB\VendorRegistry\vendor123\Services\Lock();
$active_locks = $lock_service->get_active_locks();
?>

<div class="wrap cbvr-import-page">
    <h1>Vendor Registry Import</h1>

    <?php
    if (!current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>You do not have permission to view this page.</p></div>';
        return;
    }

    $api_data = json_decode(get_option('cb_vendor_registry_access_code', ''), true);

    if (isset($_POST['generate_api_code']) && check_admin_referer('cbvr_generate_api_code', 'cbvr_api_nonce')) {
        $new_code = wp_generate_password(12, false, false);
        $expiry_hours = intval($_POST['expiry_hours'] ?? 24);
        $expiry_time = $expiry_hours * 3600;

        $api_data = [
            'code' => $new_code,
            'created' => time(),
            'expiry_time' => $expiry_time,
        ];

        update_option('cb_vendor_registry_access_code', json_encode($api_data));

        echo '<div class="notice notice-success"><p><strong>New API Access Code Generated:</strong> ' . esc_html($new_code) . '</p></div>';
    }
    ?>

    <div class="card">
        <h2>API Access Code</h2>
        <form method="post">
            <?php wp_nonce_field('cbvr_generate_api_code', 'cbvr_api_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="expiry_hours">Expiry Duration</label></th>
                    <td>
                        <select name="expiry_hours" id="expiry_hours">
                            <option value="1">1 Hour</option>
                            <option value="6">6 Hours</option>
                            <option value="12">12 Hours</option>
                            <option value="24" selected>1 Day</option>
                            <option value="72">3 Days</option>
                            <option value="168">7 Days</option>
                        </select>
                        <p class="description">Select how long this code remains valid before expiring.</p>
                    </td>
                </tr>
            </table>

            <p><button type="submit" name="generate_api_code" class="button button-primary">Generate New Code</button>
            </p>
        </form>

        <?php if (!empty($api_data['code'])):
            $created_at = date('Y-m-d H:i:s', $api_data['created']);
            $expires_at = date('Y-m-d H:i:s', $api_data['created'] + $api_data['expiry_time']);
            $expired = time() > ($api_data['created'] + $api_data['expiry_time']);
            ?>
            <h3>Current API Code</h3>
            <table class="widefat striped">
                <tr>
                    <th>Code</th>
                    <td><code><?php echo esc_html($api_data['code']); ?></code></td>
                </tr>
                <tr>
                    <th>Created At</th>
                    <td><?php echo esc_html($created_at); ?></td>
                </tr>
                <tr>
                    <th>Expires At</th>
                    <td><?php echo esc_html($expires_at); ?>
                        <?php echo $expired ? '<span style="color:red;">(Expired)</span>' : ''; ?>
                    </td>
                </tr>
                <tr>
                    <th>Endpoint URLs</th>
                    <td>
                        <p><strong>Base Search Endpoint:</strong><br>
                            <a href="<?php echo esc_url(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search")); ?>"
                                target="_blank" rel="noopener noreferrer">
                                <code><?php echo esc_html(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search")); ?></code>
                            </a>
                        </p>

                        <p><strong>Example with Pagination:</strong><br>
                            <a href="<?php echo esc_url(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search?page=2&per_page=20")); ?>"
                                target="_blank" rel="noopener noreferrer">
                                <code><?php echo esc_html(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search?page=2&per_page=20")); ?></code>
                            </a>
                            <br><small>Use <code>page</code> and <code>per_page</code> parameters for pagination.</small>
                        </p>

                        <p><strong>Example for All Records:</strong><br>
                            <a href="<?php echo esc_url(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search?per_page=10000")); ?>"
                                target="_blank" rel="noopener noreferrer">
                                <code><?php echo esc_html(home_url("/wp-json/cb/vendor123/v1/vendors/" . $api_data['code'] . "/search?per_page=10000")); ?></code>
                            </a>
                            <br><small>Use <code>per_page=10000</code> to fetch all available records (if
                                supported).</small>
                        </p>
                    </td>
                </tr>

            </table>
        <?php else: ?>
            <p>No API code generated yet.</p>
        <?php endif; ?>
    </div>


    <?php if (!empty($active_locks)): ?>
        <div class="notice notice-warning">
            <p><strong>Active Import Locks Detected:</strong> Some import locks are active.</p>
            <p><button type="button" class="button button-secondary" id="cbvr-clear-locks">Clear All Import Locks</button>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($stuck_imports)): ?>
        <div class="notice notice-warning">
            <p><strong>Stuck Imports Detected:</strong> Some imports appear to be stuck.</p>
            <ul>
                <?php foreach ($stuck_imports as $import): ?>
                    <li>
                        <?php echo esc_html($import->filename); ?> -
                        <?php echo esc_html(date('Y-m-d H:i', strtotime($import->created_at))); ?>
                        <button type="button" class="button button-small cbvr-cancel-stuck-import"
                            data-import-id="<?php echo esc_attr($import->id); ?>">Cancel</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Cache Management</h2>
        <p>Clear all cached vendor search results to force fresh queries.</p>
        <button type="button" class="button button-secondary" id="cbvr-clear-cache">Clear Vendor Search Cache</button>
        <span id="cbvr-clear-cache-status" style="margin-left: 10px;"></span>
    </div>


    <div class="card">
        <h2>Import CSV File</h2>
        <form id="cbvr-import-form" enctype="multipart/form-data">
            <?php wp_nonce_field('cbvr_import_nonce', 'nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="csv_file">CSV File</label></th>
                    <td>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                        <p class="description">Upload a CSV file with columns: email, name, skills, rate, currency,
                            avg_rating, completed_projects, plan_code</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary" id="cbvr-start-import">Start
                    Import</button></p>
        </form>
    </div>

    <div id="cbvr-import-progress" class="card" style="display: none;">
        <h2>Import Progress</h2>
        <div class="progress-bar-container">
            <div class="progress-bar">
                <div class="progress-fill" style="width: 0%;"></div>
            </div>
            <div class="progress-text">0%</div>
        </div>
        <div class="progress-details">
            <p><strong>Status:</strong> <span id="import-status">Pending</span></p>
            <p><strong>Processed:</strong> <span id="import-processed">0</span> / <span id="import-total">0</span></p>
            <p id="import-error" style="display: none; color: #dc3232;"><strong>Error:</strong> <span
                    id="import-error-message"></span></p>
        </div>
        <div class="import-actions"><button type="button" class="button button-secondary" id="cbvr-cancel-import"
                style="display: none;">Cancel Import</button></div>
    </div>

    <div class="card">
        <h2>Recent Imports</h2>
        <?php
        $imports = $wpdb->get_results("SELECT * FROM $imports_table ORDER BY created_at DESC LIMIT 10");
        if ($imports): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Status</th>
                        <th>Rows</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $import): ?>
                        <tr>
                            <td><?php echo esc_html($import->filename); ?></td>
                            <td><span
                                    class="status-<?php echo esc_attr($import->status); ?>"><?php echo esc_html(ucfirst($import->status)); ?></span>
                            </td>
                            <td><?php echo esc_html($import->processed_rows . ' / ' . $import->total_rows); ?></td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($import->created_at))); ?></td>
                            <td>
                                <?php if (in_array($import->status, ['pending', 'processing'])): ?>
                                    <button type="button" class="button button-small cbvr-cancel-import"
                                        data-import-id="<?php echo esc_attr($import->id); ?>">Cancel</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No imports found.</p>
        <?php endif; ?>
    </div>
</div>
