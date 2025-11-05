<?php
namespace CB\VendorRegistry\vendor123;

if (!defined('ABSPATH')) {
    exit;
}

class Activator {
    public static function activate() {
        $db_schema = new Services\DbSchema();
        $db_schema->create_tables();
        
        update_option('cb_vendor_registry_schema_version', '1.0.0');
        
        // Flush rewrite rules for REST API
        flush_rewrite_rules();
    }
}