<?php
namespace CB\VendorRegistry\vendor123;

if (!defined('ABSPATH')) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        // Clean up temporary data
        wp_cache_flush();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
}