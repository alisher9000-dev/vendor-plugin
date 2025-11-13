(function ($) {
    'use strict';

    class CBVRAdmin {
        constructor() {
            this.importInProgress = false;
            this.importCheckInterval = null;
            this.currentImportId = null;

            this.init();
        }

        init() {
            this.bindEvents();
        }

        bindEvents() {
            // Import form submission
            $('#cbvr-import-form').on('submit', (e) => this.handleImportSubmit(e));

            // Cancel import buttons
            $(document).on('click', '.cbvr-cancel-import, .cbvr-cancel-stuck-import', (e) => this.handleCancelImport(e));
            $('#cbvr-cancel-import').on('click', () => this.cancelCurrentImport());

            // Clear locks button
            $('#cbvr-clear-locks').on('click', () => this.clearImportLocks());

            // Reset form when file input changes
            $('#csv_file').on('change', () => this.resetImportProgress());

            $('#cbvr-clear-cache').on('click', () => {
                const $status = $('#cbvr-clear-cache-status');
                $status.text('Clearing cache...');
                $.post(cbvr_admin.ajax_url,
                    { action: 'cbvr_clear_search_cache', nonce: cbvr_admin.nonce },
                    function (response) {
                        if (response.success) {
                            $status.text('Cache cleared successfully!');
                        } else {
                            $status.text('Failed to clear cache.');
                        }
                    });
            });
        }

        handleImportSubmit(e) {
            e.preventDefault();

            if (this.importInProgress) {
                alert(cbvr_admin.importing);
                return;
            }

            const fileInput = $('#csv_file')[0];
            if (!fileInput.files.length) {
                alert('Please select a CSV file.');
                return;
            }

            this.startImport(fileInput.files[0]);
        }

        startImport(file) {
            const formData = new FormData();
            formData.append('action', 'cbvr_import_csv');
            formData.append('nonce', cbvr_admin.nonce);
            formData.append('csv_file', file);

            this.importInProgress = true;
            this.updateImportButton(true);
            this.showImportProgress();

            $.ajax({
                url: cbvr_admin.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 300000 // 5 minutes timeout for large files
            })
                .done((response) => {
                    if (response.success) {
                        this.currentImportId = response.data.import_id;
                        // Start checking progress immediately
                        this.startProgressChecking();
                    } else {
                        this.handleImportError(response.data);
                        this.hideImportProgress();
                    }
                })
                .fail((xhr, status, error) => {
                    this.handleImportError('Network error: ' + error);
                    this.hideImportProgress();
                })
                .always(() => {
                    this.importInProgress = false;
                    this.updateImportButton(false);
                });
        }

        startProgressChecking() {
            // Check more frequently for better UX
            this.importCheckInterval = setInterval(() => {
                this.checkImportProgress();
            }, 1000); // Check every second
        }

        checkImportProgress() {
            if (!this.currentImportId) {
                this.stopProgressChecking();
                return;
            }

            $.ajax({
                url: cbvr_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'cbvr_get_import_status',
                    nonce: cbvr_admin.nonce,
                    import_id: this.currentImportId
                },
                dataType: 'json',
                timeout: 10000
            })
                .done((response) => {
                    if (response.success) {
                        this.updateProgressDisplay(response.data);

                        // Stop checking if import is complete
                        if (response.data.status === 'completed' ||
                            response.data.status === 'failed' ||
                            response.data.status === 'cancelled') {
                            this.stopProgressChecking();

                            if (response.data.status === 'failed') {
                                this.showImportError(response.data.error);
                            }

                            if (response.data.status === 'cancelled') {
                                this.showImportMessage('Import cancelled');
                            }

                            // Hide cancel button for finished imports
                            $('#cbvr-cancel-import').hide();

                            // Reload page after 3 seconds to show final state
                            setTimeout(() => {
                                location.reload();
                            }, 3000);
                        }
                    } else {
                        this.stopProgressChecking();
                        this.handleImportError(response.data);
                    }
                })
                .fail(() => {
                    // Don't stop on network errors, just retry
                    console.log('Progress check failed, retrying...');
                });
        }

        stopProgressChecking() {
            if (this.importCheckInterval) {
                clearInterval(this.importCheckInterval);
                this.importCheckInterval = null;
            }
        }

        updateProgressDisplay(data) {
            const percentage = data.percentage || 0;

            // Update progress bar smoothly
            $('.progress-fill').css('width', percentage + '%');
            $('.progress-text').text(percentage + '%');

            // Update status text
            $('#import-status').text(this.getStatusText(data.status));
            $('#import-processed').text(data.processed);
            $('#import-total').text(data.total);

            // Show/hide error
            if (data.error) {
                $('#import-error-message').text(data.error);
                $('#import-error').show();
            } else {
                $('#import-error').hide();
            }

            // Show/hide cancel button
            if (data.status === 'pending' || data.status === 'processing') {
                $('#cbvr-cancel-import').show();
            } else {
                $('#cbvr-cancel-import').hide();
            }
        }

        getStatusText(status) {
            const statusMap = {
                'pending': 'Starting...',
                'processing': 'Processing...',
                'completed': 'Completed',
                'failed': 'Failed',
                'cancelled': 'Cancelled'
            };
            return statusMap[status] || status;
        }

        showImportProgress() {
            $('#cbvr-import-progress').show();
            this.resetImportProgress();
        }

        hideImportProgress() {
            $('#cbvr-import-progress').hide();
        }

        resetImportProgress() {
            $('.progress-fill').css('width', '0%');
            $('.progress-text').text('0%');
            $('#import-status').text('Starting...');
            $('#import-processed').text('0');
            $('#import-total').text('0');
            $('#import-error').hide();
            $('#cbvr-cancel-import').hide();
        }

        updateImportButton(importing) {
            const button = $('#cbvr-start-import');
            button.prop('disabled', importing);
            button.text(importing ? 'Importing...' : 'Start Import');
        }

        clearImportLocks() {
            if (!confirm('Are you sure you want to clear all import locks? This may allow stuck imports to be restarted.')) {
                return;
            }

            $.ajax({
                url: cbvr_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'cbvr_clear_import_lock',
                    nonce: cbvr_admin.nonce
                },
                dataType: 'json'
            })
                .done((response) => {
                    if (response.success) {
                        alert('Import locks cleared successfully! The page will now reload.');
                        location.reload();
                    } else {
                        alert('Failed to clear import locks: ' + response.data);
                    }
                })
                .fail(() => {
                    alert('Network error while clearing import locks');
                });
        }

        handleCancelImport(e) {
            e.preventDefault();
            const importId = $(e.target).data('import-id');

            if (!importId) {
                return;
            }

            if (!confirm('Are you sure you want to cancel this import?')) {
                return;
            }

            this.cancelImport(importId, $(e.target));
        }

        cancelCurrentImport() {
            if (!this.currentImportId) {
                return;
            }

            if (!confirm('Are you sure you want to cancel this import?')) {
                return;
            }

            this.cancelImport(this.currentImportId);
        }

        cancelImport(importId, button = null) {
            $.ajax({
                url: cbvr_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'cbvr_cancel_import',
                    nonce: cbvr_admin.nonce,
                    import_id: importId
                },
                dataType: 'json'
            })
                .done((response) => {
                    if (response.success) {
                        this.showImportMessage('Import cancelled successfully');

                        if (button) {
                            button.closest('tr').find('td:nth-child(2)').html('<span class="status-cancelled">Cancelled</span>');
                            button.remove();
                        }

                        if (importId === this.currentImportId) {
                            this.stopProgressChecking();
                            $('#cbvr-cancel-import').hide();
                            $('#import-status').text('Cancelled');

                            // Reload page after 2 seconds
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        } else {
                            // Reload page to refresh the list
                            location.reload();
                        }
                    } else {
                        alert('Failed to cancel import: ' + response.data);
                    }
                })
                .fail(() => {
                    alert('Network error while cancelling import');
                });
        }

        handleImportError(error) {
            console.error('Import error:', error);
            alert('Import failed: ' + (error || 'Unknown error'));
        }

        showImportError(error) {
            $('#import-error-message').text(error);
            $('#import-error').show();
        }

        showImportMessage(message) {
            // Simple message display
            console.log('Import message:', message);
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new CBVRAdmin();
    });

})(jQuery);
