<?php
/**
 * Plugin Name: CB Vendor Registry
 * Description: Vendor management system with CSV import, search, and REST API
 * Version: 1.0.0
 * Author: Ali Sher
 * Text Domain: cb-vendor-registry
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CB_VENDOR_REGISTRY_VERSION', '1.0.0');
define('CB_VENDOR_REGISTRY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CB_VENDOR_REGISTRY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CB_VENDOR_REGISTRY_SEED', 'vendor123');

// Autoloader
spl_autoload_register(function ($class_name) {
    $namespace = 'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED;
    
    if (strpos($class_name, $namespace) === 0) {
        $class_name = str_replace($namespace, '', $class_name);
        $class_name = str_replace('\\', '/', $class_name);
        $file = CB_VENDOR_REGISTRY_PLUGIN_PATH . 'includes' . $class_name . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

register_activation_hook(__FILE__, [CB\VendorRegistry\vendor123\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [CB\VendorRegistry\vendor123\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', function () {
    if (class_exists('CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\DbSchema')) {
        $services = [
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\DbSchema',
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\AdminUi',
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\Search',
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\Rest',
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\Cache',
            'CB\\VendorRegistry\\' . CB_VENDOR_REGISTRY_SEED . '\\Services\\Lock'
        ];
        
        foreach ($services as $service) {
            if (class_exists($service)) {
                new $service();
            }
        }
    }
});