<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class AdminUi
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_cbvr_import_csv', [$this, 'handle_csv_import']);
        add_action('wp_ajax_cbvr_get_import_status', [$this, 'get_import_status']);
        add_action('wp_ajax_cbvr_cancel_import', [$this, 'handle_cancel_import']);
        add_action('wp_ajax_cbvr_clear_import_lock', [$this, 'handle_clear_import_lock']);
        add_action('wp_ajax_cbvr_force_process', [$this, 'handle_force_process']);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Vendor Registry Import', 'cb-vendor-registry'),
            __('Vendor Registry', 'cb-vendor-registry'),
            'manage_options',                
            'cb-vendor-registry-import',       
            [$this, 'render_import_page'],      
            'dashicons-database-import',        
            70                          
        );
    }


    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'tools_page_cb-vendor-registry-import') {
            return;
        }

        wp_enqueue_script(
            'cbvr-admin',
            CB_VENDOR_REGISTRY_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            CB_VENDOR_REGISTRY_VERSION,
            true
        );

        wp_enqueue_style(
            'cbvr-admin',
            CB_VENDOR_REGISTRY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            CB_VENDOR_REGISTRY_VERSION
        );

        wp_localize_script('cbvr-admin', 'cbvr_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cbvr_import_nonce'),
            'importing' => __('Importing...', 'cb-vendor-registry'),
            'completed' => __('Completed', 'cb-vendor-registry'),
            'failed' => __('Failed', 'cb-vendor-registry')
        ]);
    }

    public function render_import_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'cb-vendor-registry'));
        }

        // Check for active locks
        $lock_service = new Lock();
        $active_locks = $lock_service->get_active_locks();

        include CB_VENDOR_REGISTRY_PLUGIN_PATH . 'templates/import-page.php';
    }

    public function handle_csv_import()
    {
        check_ajax_referer('cbvr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }

        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload error: ' . $file['error']);
        }

        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            wp_send_json_error('Only CSV files are allowed');
        }

        $importer = new Importer();
        $result = $importer->process_upload($file);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'import_id' => $result,
            'message' => 'Import started successfully'
        ]);
    }

    public function get_import_status()
    {
        check_ajax_referer('cbvr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $import_id = absint($_POST['import_id'] ?? 0);

        if (!$import_id) {
            wp_send_json_error('Invalid import ID');
        }

        global $wpdb;
        $db_schema = new DbSchema();
        $imports_table = $db_schema->get_table_name('imports');

        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT status, processed_rows, total_rows, error_message 
             FROM $imports_table 
             WHERE id = %d",
            $import_id
        ));

        if (!$import) {
            wp_send_json_error('Import not found');
        }

        wp_send_json_success([
            'status' => $import->status,
            'processed' => $import->processed_rows,
            'total' => $import->total_rows,
            'error' => $import->error_message,
            'percentage' => $import->total_rows > 0 ?
                round(($import->processed_rows / $import->total_rows) * 100) : 0
        ]);
    }

    public function handle_cancel_import()
    {
        check_ajax_referer('cbvr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $import_id = absint($_POST['import_id'] ?? 0);

        if (!$import_id) {
            wp_send_json_error('Invalid import ID');
        }

        $importer = new Importer();
        $result = $importer->cancel_import($import_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('Import cancelled successfully');
    }

    // New method to clear import lock
    public function handle_clear_import_lock()
    {
        check_ajax_referer('cbvr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $lock_service = new Lock();
        $result = $lock_service->clear_all_locks();

        if ($result) {
            wp_send_json_success('All import locks cleared successfully');
        } else {
            wp_send_json_error('Failed to clear import locks');
        }
    }

    public function handle_force_process()
    {
        check_ajax_referer('cbvr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $importer = new Importer();
        $importer->process_pending_batches();

        wp_send_json_success('Import processing triggered');
    }
}