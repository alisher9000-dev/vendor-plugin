<?php
if (!defined('ABSPATH')) {
    exit;
}

$search_service = new CB\VendorRegistry\vendor123\Services\Search();
$skills = $search_service->get_available_skills();
$plans = $search_service->get_available_plans();

$initial_filters = [
    'skills' => isset($_GET['skills']) ? array_map('sanitize_text_field', (array)$_GET['skills']) : [],
    'min_rate' => isset($_GET['min_rate']) ? floatval($_GET['min_rate']) : 0,
    'max_rate' => isset($_GET['max_rate']) ? floatval($_GET['max_rate']) : 0,
    'plan_code' => isset($_GET['plan_code']) ? sanitize_text_field($_GET['plan_code']) : '',
    'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
    'sort' => isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'score'
];
?>

<div class="cbvr-vendor-search">
    <div class="cbvr-search-header">
        <h2>Find Vendors</h2>
        <p>Search and filter from our vendor directory</p>
    </div>

    <form id="cbvr-search-form" class="cbvr-search-form">
        <div class="search-row">
            <div class="search-field">
                <input type="text" 
                       name="search" 
                       id="cbvr-search" 
                       placeholder="Search vendors..." 
                       value="<?php echo esc_attr($initial_filters['search']); ?>">
            </div>

            <div class="search-field">
                <select name="skills[]" id="cbvr-skills" multiple data-placeholder="Select skills...">
                    <?php foreach ($skills as $skill) : ?>
                        <option value="<?php echo esc_attr($skill->normalized_name); ?>" 
                            <?php echo in_array($skill->normalized_name, $initial_filters['skills']) ? 'selected' : ''; ?>>
                            <?php echo esc_html($skill->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-field rate-field">
                <input type="number" 
                       name="min_rate" 
                       id="cbvr-min-rate" 
                       placeholder="Min rate" 
                       value="<?php echo esc_attr($initial_filters['min_rate']); ?>" 
                       min="0" step="1">
                <span class="rate-separator">to</span>
                <input type="number" 
                       name="max_rate" 
                       id="cbvr-max-rate" 
                       placeholder="Max rate" 
                       value="<?php echo esc_attr($initial_filters['max_rate']); ?>" 
                       min="0" step="1">
            </div>

            <div class="search-field">
                <select name="plan_code" id="cbvr-plan">
                    <option value="">All Plans</option>
                    <?php foreach ($plans as $plan) : ?>
                        <option value="<?php echo esc_attr($plan); ?>" 
                            <?php echo $initial_filters['plan_code'] === $plan ? 'selected' : ''; ?>>
                            <?php echo esc_html(ucfirst($plan)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-field">
                <select name="sort" id="cbvr-sort">
                    <option value="score" <?php selected($initial_filters['sort'], 'score'); ?>>Relevance</option>
                    <option value="rate_asc" <?php selected($initial_filters['sort'], 'rate_asc'); ?>>Rate: Low to High</option>
                    <option value="rate_desc" <?php selected($initial_filters['sort'], 'rate_desc'); ?>>Rate: High to Low</option>
                    <option value="rating" <?php selected($initial_filters['sort'], 'rating'); ?>>Highest Rated</option>
                    <option value="projects" <?php selected($initial_filters['sort'], 'projects'); ?>>Most Projects</option>
                    <option value="name" <?php selected($initial_filters['sort'], 'name'); ?>>Name A-Z</option>
                </select>
            </div>

            <div class="search-actions">
                <button type="submit" class="search-btn">Search</button>
                <button type="button" class="reset-btn">Reset</button>
            </div>
        </div>
    </form>

    <div id="cbvr-active-filters" class="active-filters" style="display: none;">
        <div class="active-filters-header">
            <span>Active filters:</span>
            <button type="button" class="clear-all-filters">Clear all</button>
        </div>
        <div class="filter-chips"></div>
    </div>

    <div class="cbvr-results-area">
        <div id="cbvr-loading" class="loading-state" style="display: none;">
            <div class="loading-spinner"></div>
            <p>Loading vendors...</p>
        </div>

        <div class="results-header">
            <h3 id="cbvr-results-count">Vendors</h3>
        </div>

        <div id="cbvr-vendors-grid" class="vendors-grid"></div>

        <div id="cbvr-no-results" class="no-results" style="display: none;">
            <p>No vendors found matching your criteria.</p>
            <p>Try adjusting your filters or search terms.</p>
        </div>

        <div id="cbvr-pagination" class="pagination" style="display: none;"></div>
    </div>
</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
jQuery(document).ready(function($) {
    $('#cbvr-skills').select2({
        placeholder: "Select skills...",
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });
});
</script>