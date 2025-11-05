<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class DbSchema
{
    private $charset_collate;
    private $table_prefix;

    public function __construct()
    {
        global $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
        $this->table_prefix = $wpdb->prefix . 'cbvr_' . CB_VENDOR_REGISTRY_SEED . '_';
    }

    public function create_tables()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create_vendors_table();
        $this->create_skills_table();
        $this->create_vendor_skills_table();
        $this->create_plans_table();
        $this->create_subscriptions_table();
        $this->create_imports_table();
    }

    private function create_vendors_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'vendors';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            rate DECIMAL(10,2) UNSIGNED NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            avg_rating DECIMAL(3,2) UNSIGNED DEFAULT 0.00,
            completed_projects INT UNSIGNED DEFAULT 0,
            plan_code VARCHAR(50) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY idx_email_active (email, is_active),
            KEY idx_rating (avg_rating),
            KEY idx_rate (rate),
            KEY idx_plan_code (plan_code),
            KEY idx_completed_projects (completed_projects)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    private function create_skills_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'skills';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY normalized_name (normalized_name),
            KEY idx_name (name)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    private function create_vendor_skills_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'vendor_skills';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT(20) UNSIGNED NOT NULL,
            skill_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vendor_skill (vendor_id, skill_id),
            KEY idx_vendor_id (vendor_id),
            KEY idx_skill_id (skill_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    private function create_plans_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'plans';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            features TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY idx_active (is_active)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    private function create_subscriptions_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'subscriptions';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT(20) UNSIGNED NOT NULL,
            plan_code VARCHAR(50) NOT NULL,
            starts_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ends_at DATETIME NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_vendor_id (vendor_id),
            KEY idx_plan_code (plan_code),
            KEY idx_active (is_active),
            KEY idx_dates (starts_at, ends_at)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    private function create_imports_table()
    {
        global $wpdb;

        $table_name = $this->table_prefix . 'imports';

        $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL,
        file_hash VARCHAR(100) NOT NULL,
        total_rows INT UNSIGNED DEFAULT 0,
        processed_rows INT UNSIGNED DEFAULT 0,
        status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
        error_message TEXT,
        created_by BIGINT(20) UNSIGNED NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY file_hash (file_hash),
        KEY idx_status (status),
        KEY idx_created_at (created_at)
    ) {$this->charset_collate};";

        dbDelta($sql);
    }

    public function get_table_name($table)
    {
        return $this->table_prefix . $table;
    }
}