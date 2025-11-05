<?php
namespace CB\VendorRegistry\vendor123\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Rest
{
    private $search;
    private $cache;

    public function __construct()
    {
        $this->search = new Search();
        $this->cache = new Cache();

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route("cb/" . CB_VENDOR_REGISTRY_SEED . "/v1", '/vendors/(?P<code>[a-zA-Z0-9]+)/search', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle_search_request'],
                'permission_callback' => '__return_true',
                'args' => $this->get_search_args(),
            ]
        ]);
    }

    public function handle_search_request($request)
    {
        $code = sanitize_text_field($request->get_param('code'));
        $stored_data = json_decode(get_option('cb_vendor_registry_access_code', ''), true);

        if (empty($stored_data['code']) || $stored_data['code'] !== $code) {
            return new \WP_REST_Response(['error' => 'Invalid access code'], 403);
        }

        // Expiry check
        $expiry_time = intval($stored_data['expiry_time'] ?? 0);
        $created_at = intval($stored_data['created'] ?? 0);

        if (time() > ($created_at + $expiry_time)) {
            return new \WP_REST_Response(['error' => 'Access code expired'], 403);
        }

        $filters = [
            'skills' => $request->get_param('skills'),
            'min_rate' => $request->get_param('min_rate'),
            'max_rate' => $request->get_param('max_rate'),
            'plan_code' => $request->get_param('plan_code'),
            'search' => $request->get_param('search'),
            'sort' => $request->get_param('sort'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page')
        ];

        $results = $this->search->search_vendors($filters);
        return new \WP_REST_Response($results, 200);
    }


    private function get_search_args()
    {
        return [
            'skills' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'sanitize_callback' => function ($skills) {
                    return array_map('sanitize_text_field', (array) $skills);
                },
                'default' => []
            ],
            'min_rate' => [
                'type' => 'number',
                'sanitize_callback' => function ($value, $request = null, $param = null) {
                    return floatval($value);
                },
                'default' => 0
            ],
            'max_rate' => [
                'type' => 'number',
                'sanitize_callback' => function ($value, $request = null, $param = null) {
                    return floatval($value);
                },
                'default' => 0
            ],

            'plan_code' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ],
            'sort' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'enum' => ['score', 'rate_asc', 'rate_desc', 'rating', 'projects', 'name'],
                'default' => 'score'
            ],
            'page' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'minimum' => 1,
                'default' => 1
            ],
            'per_page' => [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'minimum' => 1,
                'maximum' => 10000,
                'default' => 10
            ],

        ];
    }


}