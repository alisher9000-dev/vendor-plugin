<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Cache {
    private $prefix;

    public function __construct() {
        $this->prefix = 'cbvr_' . CB_VENDOR_REGISTRY_SEED . '_';
    }

    public function get($key) {
        $transient_key = $this->prefix . $key;
        $cached = get_transient($transient_key);
        
        return $cached !== false ? $cached : false;
    }

    public function set($key, $data, $expiration = 3600) {
        $transient_key = $this->prefix . $key;
        return set_transient($transient_key, $data, $expiration);
    }

    public function delete($key) {
        $transient_key = $this->prefix . $key;
        return delete_transient($transient_key);
    }

    public function flush() {
        global $wpdb;
        
        $pattern = '_transient_' . $this->prefix . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
            $pattern
        ));
        
        $pattern = '_transient_timeout_' . $this->prefix . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
            $pattern
        ));
        
        wp_cache_flush();
    }
}