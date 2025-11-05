<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Lock {
    private $prefix;

    public function __construct() {
        $this->prefix = 'cbvr_' . CB_VENDOR_REGISTRY_SEED . '_lock_';
    }

    public function acquire($lock_name, $expiration = 60) {
        $lock_key = $this->prefix . $lock_name;
        
        // create the lock
        $result = add_option($lock_key, time(), '', 'no');
        
        if ($result) {
            // Set expiration
            update_option($lock_key . '_timeout', time() + $expiration);
            return true;
        }
        
        // Check if lock has expired
        $timeout = get_option($lock_key . '_timeout', 0);
        if ($timeout && time() > $timeout) {
            // Lock expired, try to acquire it
            $this->release($lock_name);
            return add_option($lock_key, time(), '', 'no');
        }
        
        return false;
    }

    public function release($lock_name) {
        $lock_key = $this->prefix . $lock_name;
        delete_option($lock_key);
        delete_option($lock_key . '_timeout');
        return true;
    }

    public function is_locked($lock_name) {
        $lock_key = $this->prefix . $lock_name;
        
        if (!get_option($lock_key)) {
            return false;
        }
        
        // Check if lock has expired
        $timeout = get_option($lock_key . '_timeout', 0);
        if ($timeout && time() > $timeout) {
            $this->release($lock_name);
            return false;
        }
        
        return true;
    }

    public function wait_for_lock($lock_name, $timeout = 30, $retry_interval = 1) {
        $start_time = time();
        
        while ($this->is_locked($lock_name)) {
            if (time() - $start_time > $timeout) {
                return false;
            }
            sleep($retry_interval);
        }
        
        return $this->acquire($lock_name, $timeout);
    }

    // New method to clear all locks
    public function clear_all_locks() {
        global $wpdb;
        
        $lock_pattern = $this->prefix . '%';
        $timeout_pattern = $this->prefix . '%_timeout';
        
        // Delete lock options
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
            $lock_pattern,
            $timeout_pattern
        ));
        
        // Clear object cache
        wp_cache_flush();
        
        return true;
    }

    // New method to get all active locks
    public function get_active_locks() {
        global $wpdb;
        
        $lock_pattern = $this->prefix . '%';
        
        $locks = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value 
             FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE '%_timeout'",
            $lock_pattern
        ));
        
        $active_locks = [];
        foreach ($locks as $lock) {
            $lock_name = str_replace($this->prefix, '', $lock->option_name);
            $timeout = get_option($lock->option_name . '_timeout', 0);
            
            $active_locks[$lock_name] = [
                'created' => $lock->option_value,
                'timeout' => $timeout,
                'expired' => $timeout && time() > $timeout
            ];
        }
        
        return $active_locks;
    }
}