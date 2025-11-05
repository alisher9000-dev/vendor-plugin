<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Search {
    private $db_schema;
    private $cache;

    public function __construct() {
        $this->db_schema = new DbSchema();
        $this->cache = new Cache();
        
        add_shortcode('cbvr_vendor_search', [$this, 'render_search_shortcode']);
        add_action('wp_ajax_cbvr_search_vendors', [$this, 'handle_ajax_search']);
        add_action('wp_ajax_nopriv_cbvr_search_vendors', [$this, 'handle_ajax_search']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    public function enqueue_frontend_scripts() {
        if (is_singular() && has_shortcode(get_post()->post_content, 'cbvr_vendor_search')) {
            // We're using CDN for Select2, so no need to enqueue WordPress versions
            
            wp_enqueue_script(
                'cbvr-frontend',
                CB_VENDOR_REGISTRY_PLUGIN_URL . 'assets/js/frontend.js',
                ['jquery'],
                CB_VENDOR_REGISTRY_VERSION,
                true
            );

            wp_enqueue_style(
                'cbvr-frontend',
                CB_VENDOR_REGISTRY_PLUGIN_URL . 'assets/css/frontend.css',
                [],
                CB_VENDOR_REGISTRY_VERSION
            );

            wp_localize_script('cbvr-frontend', 'cbvr_frontend', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cbvr_search_nonce')
            ]);
        }
    }

    public function render_search_shortcode($atts) {
        $atts = shortcode_atts([
            'per_page' => 9
        ], $atts);

        ob_start();
        include CB_VENDOR_REGISTRY_PLUGIN_PATH . 'templates/vendor-search.php';
        return ob_get_clean();
    }

    public function handle_ajax_search() {
        check_ajax_referer('cbvr_search_nonce', 'nonce');

        $filters = [
            'skills' => isset($_POST['skills']) ? array_map('sanitize_text_field', (array)$_POST['skills']) : [],
            'min_rate' => isset($_POST['min_rate']) ? floatval($_POST['min_rate']) : 0,
            'max_rate' => isset($_POST['max_rate']) ? floatval($_POST['max_rate']) : 0,
            'plan_code' => isset($_POST['plan_code']) ? sanitize_text_field($_POST['plan_code']) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'sort' => isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'score',
            'page' => isset($_POST['page']) ? absint($_POST['page']) : 1,
            'per_page' => isset($_POST['per_page']) ? absint($_POST['per_page']) : 9
        ];

        $results = $this->search_vendors($filters);
        
        wp_send_json_success($results);
    }

    public function search_vendors($filters) {
        $cache_key = $this->generate_cache_key($filters);
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $db_schema = new DbSchema();

        $vendors_table = $db_schema->get_table_name('vendors');
        $skills_table = $db_schema->get_table_name('skills');
        $vendor_skills_table = $db_schema->get_table_name('vendor_skills');

        // Base query with joins
        $query = "
            SELECT DISTINCT v.*,
            GROUP_CONCAT(DISTINCT s.name) as skill_names,
            (
                (v.avg_rating * 0.4) + 
                (v.completed_projects * 0.0001) +
                (CASE WHEN v.plan_code IN ('premium', 'enterprise') THEN 0.2 ELSE 0 END)
            ) as score
            FROM $vendors_table v
            LEFT JOIN $vendor_skills_table vs ON v.id = vs.vendor_id
            LEFT JOIN $skills_table s ON vs.skill_id = s.id
            WHERE v.is_active = 1
        ";

        $where_clauses = [];
        $params = [];

        // Skills filter
        if (!empty($filters['skills'])) {
            $placeholders = implode(',', array_fill(0, count($filters['skills']), '%s'));
            $where_clauses[] = "s.normalized_name IN ($placeholders)";
            $params = array_merge($params, $filters['skills']);
        }

        // Rate filter
        if ($filters['min_rate'] > 0) {
            $where_clauses[] = "v.rate >= %f";
            $params[] = $filters['min_rate'];
        }

        if ($filters['max_rate'] > 0 && $filters['max_rate'] > $filters['min_rate']) {
            $where_clauses[] = "v.rate <= %f";
            $params[] = $filters['max_rate'];
        }

        // Plan filter
        if (!empty($filters['plan_code'])) {
            $where_clauses[] = "v.plan_code = %s";
            $params[] = $filters['plan_code'];
        }

        // Text search
        if (!empty($filters['search'])) {
            $where_clauses[] = "(v.name LIKE %s OR v.email LIKE %s OR s.name LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // Add WHERE clauses
        if (!empty($where_clauses)) {
            $query .= " AND " . implode(" AND ", $where_clauses);
        }

        // Group and sort
        $query .= " GROUP BY v.id";

        // Sorting
        $sort_order = 'DESC';
        switch ($filters['sort']) {
            case 'rate_asc':
                $order_by = 'v.rate';
                $sort_order = 'ASC';
                break;
            case 'rate_desc':
                $order_by = 'v.rate';
                break;
            case 'rating':
                $order_by = 'v.avg_rating';
                break;
            case 'projects':
                $order_by = 'v.completed_projects';
                break;
            case 'name':
                $order_by = 'v.name';
                $sort_order = 'ASC';
                break;
            default: // score
                $order_by = 'score';
                break;
        }

        $query .= " ORDER BY $order_by $sort_order";

        // Count total results - FIXED VERSION
        $count_query = "
            SELECT COUNT(*) as total_count 
            FROM (
                SELECT DISTINCT v.id
                FROM $vendors_table v
                LEFT JOIN $vendor_skills_table vs ON v.id = vs.vendor_id
                LEFT JOIN $skills_table s ON vs.skill_id = s.id
                WHERE v.is_active = 1
        ";

        $count_params = [];

        // Add the same WHERE clauses for count query
        if (!empty($where_clauses)) {
            $count_query .= " AND " . implode(" AND ", $where_clauses);
            $count_params = $params;
        }

        $count_query .= " ) as vendor_ids";

        // Execute count query
        $total_results = $wpdb->get_var($wpdb->prepare($count_query, $count_params));
        $total_results = $total_results ? intval($total_results) : 0;

        // Pagination
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $query .= " LIMIT %d, %d";
        $params[] = $offset;
        $params[] = $filters['per_page'];

        // Execute query
        $vendors = $wpdb->get_results($wpdb->prepare($query, $params));

        // Format results
        $formatted_results = [];
        foreach ($vendors as $vendor) {
            $formatted_results[] = [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'email' => $this->mask_email($vendor->email),
                'skills' => $vendor->skill_names ? explode(',', $vendor->skill_names) : [],
                'rate' => floatval($vendor->rate),
                'currency' => $vendor->currency,
                'avg_rating' => floatval($vendor->avg_rating),
                'completed_projects' => intval($vendor->completed_projects),
                'plan_code' => $vendor->plan_code,
                'score' => floatval($vendor->score)
            ];
        }

        $result = [
            'vendors' => $formatted_results,
            'pagination' => [
                'current_page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total_results' => $total_results,
                'total_pages' => $total_results > 0 ? ceil($total_results / $filters['per_page']) : 0
            ],
            'filters' => $filters
        ];

        // Cache for 10 minutes
        $this->cache->set($cache_key, $result, 600);

        return $result;
    }

    private function generate_cache_key($filters) {
        $key_data = array_merge($filters, [
            'cache_version' => '1.0'
        ]);
        return 'cbvr_search_' . md5(serialize($key_data));
    }

    private function mask_email($email) {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $username = $parts[0];
        $domain = $parts[1];

        if (strlen($username) <= 1) {
            $masked_username = $username . '***';
        } else {
            $masked_username = $username[0] . '***' . substr($username, -1);
        }

        return $masked_username . '@' . $domain;
    }

    public function get_available_skills() {
        global $wpdb;
        $db_schema = new DbSchema();
        $skills_table = $db_schema->get_table_name('skills');

        return $wpdb->get_results(
            "SELECT DISTINCT name, normalized_name 
             FROM $skills_table 
             ORDER BY name ASC"
        );
    }

    public function get_available_plans() {
        global $wpdb;
        $db_schema = new DbSchema();
        $vendors_table = $db_schema->get_table_name('vendors');

        return $wpdb->get_col(
            "SELECT DISTINCT plan_code 
             FROM $vendors_table 
             WHERE plan_code IS NOT NULL AND plan_code != '' 
             ORDER BY plan_code ASC"
        );
    }
}