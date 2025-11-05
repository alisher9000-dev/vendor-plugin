(function($) {
    'use strict';

    class CBVRFrontend {
        constructor() {
            this.currentPage = 1;
            this.currentFilters = {};
            this.isLoading = false;
            this.xhr = null;
            this.select2Available = false;
            
            this.init();
        }

        init() {
            this.checkSelect2();
            this.bindEvents();
            this.loadInitialResults();
        }

        checkSelect2() {
            // Check if Select2 is available (loaded via CDN)
            this.select2Available = typeof $.fn.select2 !== 'undefined';
            
            if (!this.select2Available) {
                console.warn('Select2 not available. Using native multi-select.');
            }
        }

        bindEvents() {
            // Search form submission only
            $('#cbvr-search-form').on('submit', (e) => this.handleSearch(e));
            
            // Reset filters
            $('.reset-btn, .clear-all-filters').on('click', () => this.resetFilters());
            
            // Skills select change (only for reset functionality)
            $('#cbvr-skills').on('change', () => {
                // Only update active filters display, don't search
                this.updateActiveFiltersDisplay();
            });
            
            // Pagination
            $(document).on('click', '.page-btn', (e) => this.handlePagination(e));
            
            // Remove filter chips
            $(document).on('click', '.remove-chip', (e) => {
                e.preventDefault();
                this.removeFilter($(e.target).closest('.filter-chip').data('type'), $(e.target).closest('.filter-chip').data('value'));
            });
        }

        handleSearch(e) {
            e.preventDefault();
            this.currentPage = 1;
            this.performSearch();
        }

        resetFilters() {
            console.log('Resetting filters...');
            
            // Reset form fields to proper defaults
            $('#cbvr-search').val('');
            $('#cbvr-min-rate').val('');
            $('#cbvr-max-rate').val('');
            $('#cbvr-plan').val('');
            $('#cbvr-sort').val('score'); // Reset to default sort
            
            // Reset skills select - IMPORTANT: Use proper Select2 reset
            if (this.select2Available) {
                $('#cbvr-skills').val(null).trigger('change.select2');
            } else {
                $('#cbvr-skills').val([]);
                $('#cbvr-skills').trigger('change');
            }
            
            // Force a small delay to ensure Select2 is reset
            setTimeout(() => {
                // Clear URL parameters completely
                this.currentPage = 1;
                window.history.replaceState(null, '', window.location.pathname);
                
                // Hide active filters immediately
                $('#cbvr-active-filters').hide();
                
                console.log('Performing search after reset...');
                // Perform search with empty filters to show all vendors
                this.performSearch();
            }, 100);
        }

        // New method to update active filters display without searching
        updateActiveFiltersDisplay() {
            const filters = this.getFormData();
            this.updateActiveFiltersUI(filters);
        }

        getFormData() {
            let skillsValue = [];
            
            if (this.select2Available) {
                // Get Select2 value and ensure it's an array
                const select2Val = $('#cbvr-skills').val();
                skillsValue = Array.isArray(select2Val) ? select2Val : (select2Val ? [select2Val] : []);
                
                // Filter out any null/empty values
                skillsValue = skillsValue.filter(skill => skill && skill.trim() !== '');
            } else {
                // Fallback for native multi-select
                skillsValue = $('#cbvr-skills').find('option:selected').map(function() {
                    return this.value;
                }).get().filter(skill => skill && skill.trim() !== '');
            }

            const formData = {
                skills: skillsValue,
                min_rate: $('#cbvr-min-rate').val() ? parseFloat($('#cbvr-min-rate').val()) : 0,
                max_rate: $('#cbvr-max-rate').val() ? parseFloat($('#cbvr-max-rate').val()) : 0,
                plan_code: $('#cbvr-plan').val() || '',
                search: $('#cbvr-search').val() || '',
                sort: $('#cbvr-sort').val() || 'score',
                page: this.currentPage,
                per_page: 9
            };

            // Clean up empty values for proper filtering
            if (formData.min_rate === 0) formData.min_rate = 0;
            if (formData.max_rate === 0) formData.max_rate = 0;

            console.log('Form data:', formData);
            return formData;
        }

        performSearch() {
            if (this.isLoading) {
                if (this.xhr) {
                    this.xhr.abort();
                }
            }

            const filters = this.getFormData();
            this.currentFilters = filters;
            
            this.showLoading();
            this.updateURL();
            
            console.log('Sending search request with filters:', filters);
            
            this.xhr = $.ajax({
                url: cbvr_frontend.ajax_url,
                type: 'POST',
                data: {
                    action: 'cbvr_search_vendors',
                    nonce: cbvr_frontend.nonce,
                    ...filters
                },
                dataType: 'json',
                timeout: 10000
            })
            .done((response) => {
                console.log('Search response:', response);
                if (response.success) {
                    this.displayResults(response.data);
                } else {
                    this.displayError('Search failed. Please try again.');
                }
            })
            .fail((xhr, status, error) => {
                console.error('Search failed:', status, error);
                if (status !== 'abort') {
                    this.displayError('Network error. Please check your connection.');
                }
            })
            .always(() => {
                this.hideLoading();
                this.xhr = null;
            });
        }

        displayResults(data) {
            console.log('Displaying results:', data);
            this.updateActiveFiltersUI(data.filters);
            this.updateResultsCount(data.pagination);
            this.renderVendors(data.vendors);
            this.renderPagination(data.pagination);
            
            if (data.vendors.length === 0) {
                this.showNoResults();
            } else {
                this.hideNoResults();
            }
        }

        updateActiveFiltersUI(filters) {
            const chipsContainer = $('.filter-chips');
            chipsContainer.empty();
            
            let hasActiveFilters = false;
            
            console.log('Updating active filters UI with:', filters);
            
            // Skills chips - only show if there are actual skills selected
            if (filters.skills && filters.skills.length > 0) {
                const validSkills = filters.skills.filter(skill => skill && skill.trim() !== '');
                if (validSkills.length > 0) {
                    validSkills.forEach(skill => {
                        chipsContainer.append(this.createFilterChip('skill', skill, skill));
                    });
                    hasActiveFilters = true;
                }
            }
            
            // Rate range chip - only show if at least one rate is set
            const hasMinRate = filters.min_rate > 0;
            const hasMaxRate = filters.max_rate > 0;
            if (hasMinRate || hasMaxRate) {
                const rateText = `Rate: $${filters.min_rate || 0} - $${filters.max_rate || 'Any'}`;
                chipsContainer.append(this.createFilterChip('rate', 'rate', rateText));
                hasActiveFilters = true;
            }
            
            // Plan chip - only show if a plan is selected
            if (filters.plan_code && filters.plan_code !== '') {
                chipsContainer.append(this.createFilterChip('plan', filters.plan_code, `Plan: ${filters.plan_code}`));
                hasActiveFilters = true;
            }
            
            // Search term chip - only show if there's a search term
            if (filters.search && filters.search.trim() !== '') {
                chipsContainer.append(this.createFilterChip('search', filters.search, `Search: "${filters.search}"`));
                hasActiveFilters = true;
            }
            
            // Sort chip - only show if not default sort
            if (filters.sort && filters.sort !== 'score') {
                const sortText = this.getSortDisplayText(filters.sort);
                chipsContainer.append(this.createFilterChip('sort', filters.sort, `Sort: ${sortText}`));
                hasActiveFilters = true;
            }
            
            console.log('Has active filters:', hasActiveFilters);
            // Show/hide active filters section
            $('#cbvr-active-filters').toggle(hasActiveFilters);
        }

        getSortDisplayText(sortValue) {
            const sortMap = {
                'score': 'Relevance',
                'rate_asc': 'Rate: Low to High',
                'rate_desc': 'Rate: High to Low',
                'rating': 'Highest Rated',
                'projects': 'Most Projects',
                'name': 'Name A-Z'
            };
            return sortMap[sortValue] || sortValue;
        }

        createFilterChip(type, value, text) {
            return $(`
                <div class="filter-chip" data-type="${type}" data-value="${value}">
                    ${this.escapeHtml(text)}
                    <button type="button" class="remove-chip" aria-label="Remove filter">×</button>
                </div>
            `);
        }

        removeFilter(type, value) {
            console.log('Removing filter:', type, value);
            switch (type) {
                case 'skill':
                    if (this.select2Available) {
                        const currentSkills = $('#cbvr-skills').val() || [];
                        const newSkills = currentSkills.filter(skill => skill !== value);
                        $('#cbvr-skills').val(newSkills.length ? newSkills : null).trigger('change.select2');
                    } else {
                        $('#cbvr-skills option[value="' + value + '"]').prop('selected', false);
                        $('#cbvr-skills').trigger('change');
                    }
                    break;
                case 'rate':
                    $('#cbvr-min-rate').val('');
                    $('#cbvr-max-rate').val('');
                    break;
                case 'plan':
                    $('#cbvr-plan').val('');
                    break;
                case 'search':
                    $('#cbvr-search').val('');
                    break;
                case 'sort':
                    $('#cbvr-sort').val('score');
                    break;
            }
            
            // Small delay to ensure UI updates before searching
            setTimeout(() => {
                this.performSearch();
            }, 50);
        }

        updateResultsCount(pagination) {
            let countText = 'Vendors';
            if (pagination.total_results > 0) {
                countText = `${pagination.total_results} vendor${pagination.total_results !== 1 ? 's' : ''} found`;
                
                if (pagination.total_results > pagination.per_page) {
                    const start = ((pagination.current_page - 1) * pagination.per_page) + 1;
                    const end = Math.min(start + pagination.per_page - 1, pagination.total_results);
                    countText += ` (showing ${start}-${end})`;
                }
            } else {
                countText = 'No vendors found';
            }
            $('#cbvr-results-count').text(countText);
        }

        renderVendors(vendors) {
            const container = $('#cbvr-vendors-grid');
            container.empty();
            
            if (vendors.length === 0) {
                return;
            }
            
            vendors.forEach(vendor => {
                container.append(this.createVendorCard(vendor));
            });
        }

        createVendorCard(vendor) {
            const skillsHtml = vendor.skills.length > 0 ? 
                vendor.skills.map(skill => `<span class="skill-tag">${this.escapeHtml(skill)}</span>`).join('') :
                '<span class="skill-tag">No skills listed</span>';
            
            return $(`
                <div class="vendor-card">
                    <div class="vendor-header">
                        <h3 class="vendor-name">${this.escapeHtml(vendor.name)}</h3>
                        <div class="vendor-rating">
                            ⭐ ${vendor.avg_rating.toFixed(1)}
                            <span class="rating-count">(${vendor.completed_projects})</span>
                        </div>
                    </div>
                    
                    <div class="vendor-details">
                        <div class="vendor-detail">
                            <strong>Email:</strong>
                            <span>${this.escapeHtml(vendor.email)}</span>
                        </div>
                        <div class="vendor-detail">
                            <strong>Rate:</strong>
                            <span>${vendor.currency} ${vendor.rate.toFixed(2)}/hour</span>
                        </div>
                        <div class="vendor-detail">
                            <strong>Plan:</strong>
                            <span>${vendor.plan_code ? this.escapeHtml(vendor.plan_code) : 'None'}</span>
                        </div>
                    </div>
                    
                    <div class="vendor-skills">
                        <div class="skills-label">Skills:</div>
                        <div class="skills-list">${skillsHtml}</div>
                    </div>
                </div>
            `);
        }

        renderPagination(pagination) {
            const container = $('#cbvr-pagination');
            
            if (pagination.total_pages <= 1) {
                container.hide();
                return;
            }
            
            let html = '';
            
            // Previous button
            if (pagination.current_page > 1) {
                html += `<button class="page-btn" data-page="${pagination.current_page - 1}">← Previous</button>`;
            } else {
                html += `<button class="page-btn" disabled>← Previous</button>`;
            }
            
            // Page numbers (simplified - show first, last, and current with ellipsis)
            const showPages = this.getVisiblePages(pagination.current_page, pagination.total_pages);
            
            showPages.forEach(page => {
                if (page === '...') {
                    html += `<span class="page-ellipsis">...</span>`;
                } else {
                    const activeClass = page === pagination.current_page ? ' active' : '';
                    html += `<button class="page-btn${activeClass}" data-page="${page}">${page}</button>`;
                }
            });
            
            // Next button
            if (pagination.current_page < pagination.total_pages) {
                html += `<button class="page-btn" data-page="${pagination.current_page + 1}">Next →</button>`;
            } else {
                html += `<button class="page-btn" disabled>Next →</button>`;
            }
            
            container.html(html).show();
        }

        getVisiblePages(current, total) {
            if (total <= 7) {
                return Array.from({length: total}, (_, i) => i + 1);
            }
            
            if (current <= 4) {
                return [1, 2, 3, 4, 5, '...', total];
            }
            
            if (current >= total - 3) {
                return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
            }
            
            return [1, '...', current - 1, current, current + 1, '...', total];
        }

        handlePagination(e) {
            e.preventDefault();
            const page = parseInt($(e.target).data('page'));
            if (page && page !== this.currentPage) {
                this.currentPage = page;
                this.performSearch();
                $('html, body').animate({ scrollTop: $('.cbvr-results-area').offset().top - 20 }, 300);
            }
        }

        updateURL() {
            const filters = this.getFormData();
            const params = new URLSearchParams();
            
            console.log('Updating URL with filters:', filters);
            
            // Only add parameters that have values
            if (filters.skills.length > 0) {
                params.set('skills', filters.skills.join(','));
            }
            if (filters.min_rate > 0) params.set('min_rate', filters.min_rate);
            if (filters.max_rate > 0) params.set('max_rate', filters.max_rate);
            if (filters.plan_code && filters.plan_code !== '') params.set('plan_code', filters.plan_code);
            if (filters.search && filters.search !== '') params.set('search', filters.search);
            if (filters.sort !== 'score') params.set('sort', filters.sort);
            if (this.currentPage > 1) params.set('page', this.currentPage);
            
            const newUrl = params.toString() ? `${window.location.pathname}?${params}` : window.location.pathname;
            console.log('New URL:', newUrl);
            window.history.replaceState(null, '', newUrl);
        }

        loadInitialResults() {
            // Check if we have URL parameters and load results
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.toString()) {
                console.log('Loading initial results from URL parameters');
                this.currentPage = parseInt(urlParams.get('page')) || 1;
                this.performSearch();
            } else {
                console.log('Loading initial results (no filters)');
                // Load all vendors initially (no filters)
                this.performSearch();
            }
        }

        showLoading() {
            this.isLoading = true;
            $('#cbvr-loading').show();
            $('#cbvr-vendors-grid').hide();
            $('#cbvr-pagination').hide();
            $('#cbvr-no-results').hide();
        }

        hideLoading() {
            this.isLoading = false;
            $('#cbvr-loading').hide();
            $('#cbvr-vendors-grid').show();
        }

        showNoResults() {
            $('#cbvr-no-results').show();
            $('#cbvr-vendors-grid').hide();
            $('#cbvr-pagination').hide();
        }

        hideNoResults() {
            $('#cbvr-no-results').hide();
            $('#cbvr-vendors-grid').show();
        }

        displayError(message) {
            // Simple error display
            console.error('Search error:', message);
            $('#cbvr-vendors-grid').html(`<div class="no-results"><p>${message}</p></div>`);
        }

        escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new CBVRFrontend();
    });

})(jQuery);