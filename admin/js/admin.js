/**
 * DistillPress Admin JavaScript
 *
 * Handles the admin interface functionality for DistillPress.
 *
 * @package DistillPress
 */

(function($) {
    'use strict';

    /**
     * DistillPress Admin object.
     */
    var DistillPressAdmin = {

        /**
         * Settings from meta box data attributes.
         */
        settings: {
            enableSummary: true,
            enableTeaser: true
        },

        /**
         * Initialize the admin functionality.
         */
        init: function() {
            this.initSettings();
            this.bindEvents();
            this.initSettingsPage();
            this.loadSavedData();
        },

        /**
         * Initialize settings from meta box data attributes.
         */
        initSettings: function() {
            var $metabox = $('.distillpress-metabox');
            if ($metabox.length) {
                this.settings.enableSummary = $metabox.data('enable-summary') === 1;
                this.settings.enableTeaser = $metabox.data('enable-teaser') === 1;
            }
        },

        /**
         * Load saved summary/teaser data on page load.
         */
        loadSavedData: function() {
            var $metabox = $('.distillpress-metabox');
            if (!$metabox.length) {
                return;
            }

            var savedSummary = $metabox.data('saved-summary') || '';
            var savedTeaser = $metabox.data('saved-teaser') || '';

            if (savedSummary || savedTeaser) {
                var $result = $('#distillpress-summary-result');

                if (savedSummary && this.settings.enableSummary) {
                    $result.find('.distillpress-summary-content').html(
                        '<div class="distillpress-summary">' + 
                        this.formatSummary(savedSummary) + 
                        '</div>'
                    );
                }

                if (savedTeaser && this.settings.enableTeaser) {
                    $result.find('.distillpress-teaser-content').html(
                        '<div class="distillpress-teaser">' + this.escapeHtml(savedTeaser) + '</div>'
                    );
                }

                $result.show();
            }
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Generate summary button
            $(document).on('click', '#distillpress-generate-summary', this.generateSummary.bind(this));

            // Auto-categorize button
            $(document).on('click', '#distillpress-auto-categorize', this.autoCategorize.bind(this));

            // Copy summary button
            $(document).on('click', '#distillpress-copy-summary', this.copySummary.bind(this));

            // Copy teaser button
            $(document).on('click', '#distillpress-copy-teaser', this.copyTeaser.bind(this));

            // Toggle API key visibility (POE)
            $(document).on('click', '#distillpress-toggle-api-key', this.toggleApiKey.bind(this));

            // Toggle API key visibility (Gemini)
            $(document).on('click', '#distillpress-toggle-gemini-api-key', this.toggleGeminiApiKey.bind(this));

            // Refresh models button (POE)
            $(document).on('click', '#distillpress-refresh-models', this.refreshModels.bind(this));

            // API provider selector
            $(document).on('change', '#distillpress_api_provider', this.onProviderChange.bind(this));
        },

        /**
         * Initialize settings page specific functionality.
         */
        initSettingsPage: function() {
            // Apply initial provider visibility
            this.applyProviderVisibility();

            // Auto-load models if API key exists on settings page (POE only)
            var provider = $('#distillpress_api_provider').val() || 'poe';
            if (provider === 'poe' && $('#distillpress-refresh-models').length && !$('#distillpress-refresh-models').prop('disabled')) {
                this.refreshModels();
            }
        },

        /**
         * Handle API provider change.
         */
        onProviderChange: function() {
            this.applyProviderVisibility();
        },

        /**
         * Show/hide provider-specific settings rows.
         */
        applyProviderVisibility: function() {
            var provider = $('#distillpress_api_provider').val();
            if (!provider) {
                return;
            }

            // POE fields: API key row and model row
            var $poeKeyRow = $('#distillpress_api_key').closest('tr');
            var $poeModelRow = $('#distillpress_model').closest('tr');
            // Gemini fields: API key row and model row
            var $geminiKeyRow = $('#distillpress_gemini_api_key').closest('tr');
            var $geminiModelRow = $('#distillpress_gemini_model').closest('tr');

            if (provider === 'gemini') {
                $poeKeyRow.hide();
                $poeModelRow.hide();
                $geminiKeyRow.show();
                $geminiModelRow.show();
            } else {
                $poeKeyRow.show();
                $poeModelRow.show();
                $geminiKeyRow.hide();
                $geminiModelRow.hide();
            }
        },

        /**
         * Get post content from editor.
         *
         * @return {string} Post content.
         */
        getPostContent: function() {
            var content = '';

            // Try Gutenberg editor first
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                var editor = wp.data.select('core/editor');
                if (editor) {
                    content = editor.getEditedPostContent();
                }
            }

            // Fallback to Classic Editor (TinyMCE)
            if (!content && typeof tinymce !== 'undefined') {
                var ed = tinymce.get('content');
                if (ed) {
                    content = ed.getContent();
                }
            }

            // Fallback to textarea
            if (!content) {
                content = $('#content').val() || '';
            }

            return content;
        },

        /**
         * Generate summary via AJAX.
         *
         * @param {Event} e Click event.
         */
        generateSummary: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $('#distillpress-generate-summary');
            var $result = $('#distillpress-summary-result');
            var $message = $('#distillpress-message');
            var postId = $btn.data('post-id');

            var content = this.getPostContent();

            if (!content.trim()) {
                this.showMessage($message, distillpressData.i18n.no_content, 'error');
                return;
            }

            var numPoints = parseInt($('#distillpress-num-points').val(), 10) || 3;
            var reductionPercent = parseInt($('#distillpress-reduction-percent').val(), 10) || 0;

            // Show loading state
            this.setButtonLoading($btn, true);
            $message.hide();

            $.ajax({
                url: distillpressData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'distillpress_generate_summary',
                    nonce: distillpressData.nonce,
                    content: content,
                    post_id: postId,
                    num_points: numPoints,
                    reduction_percent: reductionPercent
                },
                success: function(response) {
                    if (response.success) {
                        // Display summary if enabled and available
                        if (self.settings.enableSummary && response.data.summary) {
                            $result.find('.distillpress-summary-content').html(
                                '<div class="distillpress-summary">' + 
                                self.formatSummary(response.data.summary) + 
                                '</div>'
                            );
                        } else if (self.settings.enableSummary) {
                            $result.find('.distillpress-summary-content').html(
                                '<em>' + distillpressData.i18n.no_summary + '</em>'
                            );
                        }
                        
                        // Display teaser if enabled and available
                        if (self.settings.enableTeaser && response.data.teaser) {
                            $result.find('.distillpress-teaser-content').html(
                                '<div class="distillpress-teaser">' + self.escapeHtml(response.data.teaser) + '</div>'
                            );
                        } else if (self.settings.enableTeaser) {
                            $result.find('.distillpress-teaser-content').html(
                                '<em>' + distillpressData.i18n.no_teaser + '</em>'
                            );
                        }
                        
                        $result.show();

                        // Update button text to regenerate
                        var regenerateText = $btn.data('regenerate-text');
                        $btn.find('.distillpress-btn-text').text(regenerateText);

                        // Show appropriate success message
                        var successMsg;
                        if (self.settings.enableSummary && self.settings.enableTeaser) {
                            successMsg = distillpressData.i18n.both_generated;
                        } else if (self.settings.enableSummary) {
                            successMsg = distillpressData.i18n.summary_generated;
                        } else {
                            successMsg = distillpressData.i18n.teaser_generated;
                        }
                        self.showMessage($message, successMsg, 'success');
                    } else {
                        self.showMessage($message, response.data.message || distillpressData.i18n.error, 'error');
                    }
                },
                error: function() {
                    self.showMessage($message, distillpressData.i18n.error, 'error');
                },
                complete: function() {
                    self.setButtonLoading($btn, false);
                }
            });
        },

        /**
         * Auto-categorize via AJAX.
         *
         * @param {Event} e Click event.
         */
        autoCategorize: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $('#distillpress-auto-categorize');
            var $result = $('#distillpress-category-result');
            var $message = $('#distillpress-message');
            var postId = $btn.data('post-id');

            var content = this.getPostContent();

            if (!content.trim()) {
                this.showMessage($message, distillpressData.i18n.no_content, 'error');
                return;
            }

            var maxCategories = parseInt($('#distillpress-max-categories').val(), 10) || 3;

            // Show loading state
            this.setButtonLoading($btn, true);
            $result.hide();
            $message.hide();

            $.ajax({
                url: distillpressData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'distillpress_auto_categorize',
                    nonce: distillpressData.nonce,
                    content: content,
                    max_categories: maxCategories,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        var categoryNames = response.data.category_names;
                        var categoryIds = response.data.category_ids;

                        // Update category checkboxes in the editor
                        self.updateCategoryCheckboxes(categoryIds);

                        // Show result
                        $result.find('.distillpress-result-content').html(
                            '<p><strong>' + distillpressData.i18n.categories_selected + '</strong></p>' +
                            '<ul class="distillpress-categories-list">' +
                            categoryNames.map(function(name) {
                                return '<li>✓ ' + self.escapeHtml(name) + '</li>';
                            }).join('') +
                            '</ul>'
                        );
                        $result.show();
                        self.showMessage($message, distillpressData.i18n.categories_selected, 'success');
                    } else {
                        self.showMessage($message, response.data.message || distillpressData.i18n.no_categories, 'error');
                    }
                },
                error: function() {
                    self.showMessage($message, distillpressData.i18n.error, 'error');
                },
                complete: function() {
                    self.setButtonLoading($btn, false);
                }
            });
        },

        /**
         * Update category checkboxes in the post editor.
         *
         * @param {Array} categoryIds Array of category IDs to check.
         */
        updateCategoryCheckboxes: function(categoryIds) {
            // Classic Editor
            $('#categorychecklist input[type="checkbox"]').each(function() {
                var $checkbox = $(this);
                var catId = parseInt($checkbox.val(), 10);
                
                if (categoryIds.indexOf(catId) !== -1) {
                    $checkbox.prop('checked', true);
                }
            });

            // Gutenberg Editor - dispatch to the block editor
            if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                var editPost = wp.data.dispatch('core/editor');
                if (editPost && editPost.editPost) {
                    editPost.editPost({ categories: categoryIds });
                }
            }
        },

        /**
         * Copy summary to clipboard.
         *
         * @param {Event} e Click event.
         */
        copySummary: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var summaryText = $('#distillpress-summary-result .distillpress-summary').text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(summaryText).then(function() {
                    DistillPressAdmin.showButtonFeedback($btn, distillpressData.i18n.copied);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(summaryText).select();
                document.execCommand('copy');
                $temp.remove();
                this.showButtonFeedback($btn, distillpressData.i18n.copied);
            }
        },

        /**
         * Copy teaser to clipboard.
         *
         * @param {Event} e Click event.
         */
        copyTeaser: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var teaserText = $('#distillpress-summary-result .distillpress-teaser').text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(teaserText).then(function() {
                    DistillPressAdmin.showButtonFeedback($btn, distillpressData.i18n.copied);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(teaserText).select();
                document.execCommand('copy');
                $temp.remove();
                this.showButtonFeedback($btn, distillpressData.i18n.copied);
            }
        },

        /**
         * Toggle API key visibility.
         *
         * @param {Event} e Click event.
         */
        toggleApiKey: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var $input = $('#distillpress_api_key');

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $btn.text($btn.text().replace('Show', 'Hide'));
            } else {
                $input.attr('type', 'password');
                $btn.text($btn.text().replace('Hide', 'Show'));
            }
        },

        /**
         * Toggle Gemini API key visibility.
         *
         * @param {Event} e Click event.
         */
        toggleGeminiApiKey: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var $input = $('#distillpress_gemini_api_key');

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $btn.text($btn.text().replace('Show', 'Hide'));
            } else {
                $input.attr('type', 'password');
                $btn.text($btn.text().replace('Hide', 'Show'));
            }
        },

        /**
         * Refresh available models from API.
         *
         * @param {Event} e Click event (optional).
         */
        refreshModels: function(e) {
            if (e) {
                e.preventDefault();
            }

            var $btn = $('#distillpress-refresh-models');
            var $select = $('#distillpress_model');
            var $loading = $('#distillpress-models-loading');
            var currentValue = $select.val();

            $btn.prop('disabled', true);
            $loading.show();

            $.ajax({
                url: distillpressData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'distillpress_get_models',
                    nonce: distillpressData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.models) {
                        $select.empty();
                        
                        response.data.models.forEach(function(model) {
                            var $option = $('<option>')
                                .val(model.id)
                                .text(model.name);
                            
                            if (model.id === currentValue) {
                                $option.prop('selected', true);
                            }
                            
                            $select.append($option);
                        });

                        // If current value wasn't found, select first option
                        if (!$select.find('option:selected').length && $select.find('option').length) {
                            $select.find('option:first').prop('selected', true);
                        }
                    }
                },
                error: function() {
                    console.error('Failed to load models');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $loading.hide();
                }
            });
        },

        /**
         * Format summary text for display.
         *
         * @param {string} summary Raw summary text.
         * @return {string} Formatted HTML.
         */
        formatSummary: function(summary) {
            if (!summary) {
                return '';
            }

            // Escape HTML first
            var escaped = this.escapeHtml(summary);
            
            // Convert bullet points to list items
            var lines = escaped.split('\n').filter(function(line) {
                return line.trim().length > 0;
            });

            var html = '<ul>';
            lines.forEach(function(line) {
                // Remove bullet character if present
                line = line.replace(/^[•\-\*]\s*/, '').trim();
                if (line) {
                    html += '<li>' + line + '</li>';
                }
            });
            html += '</ul>';

            return html;
        },

        /**
         * Escape HTML entities.
         *
         * @param {string} text Text to escape.
         * @return {string} Escaped text.
         */
        escapeHtml: function(text) {
            if (!text) {
                return '';
            }
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        /**
         * Set button loading state.
         *
         * @param {jQuery} $btn Button element.
         * @param {boolean} loading Whether loading.
         */
        setButtonLoading: function($btn, loading) {
            if (loading) {
                $btn.prop('disabled', true);
                $btn.find('.distillpress-btn-text').hide();
                $btn.find('.distillpress-btn-loading').show();
            } else {
                $btn.prop('disabled', false);
                $btn.find('.distillpress-btn-text').show();
                $btn.find('.distillpress-btn-loading').hide();
            }
        },

        /**
         * Show a message.
         *
         * @param {jQuery} $container Message container.
         * @param {string} message Message text.
         * @param {string} type Message type (success/error).
         */
        showMessage: function($container, message, type) {
            $container
                .removeClass('distillpress-message-success distillpress-message-error')
                .addClass('distillpress-message-' + type)
                .text(message)
                .show();

            // Auto-hide after 5 seconds
            setTimeout(function() {
                $container.fadeOut();
            }, 5000);
        },

        /**
         * Show temporary feedback on a button.
         *
         * @param {jQuery} $btn Button element.
         * @param {string} text Feedback text.
         */
        showButtonFeedback: function($btn, text) {
            var originalText = $btn.text();
            $btn.text(text);
            setTimeout(function() {
                $btn.text(originalText);
            }, 2000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        DistillPressAdmin.init();
    });

})(jQuery);
