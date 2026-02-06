(function($) {
    'use strict';

    $(document).ready(function() {
        var $table = $('#ehx-articles-table');
        var $searchInput = $('#ehx-search-articles');
        var $refreshBtn = $('#ehx-refresh-articles');
        var $loading = $('#ehx-articles-loading');
        var $error = $('#ehx-articles-error');
        var $success = $('#ehx-articles-success');
        var $bulkCreateBtn = $('#ehx-bulk-create-posts');
        var $selectAllBtn = $('#ehx-select-all');
        var $deselectAllBtn = $('#ehx-deselect-all');
        var $selectAllCheckbox = $('#ehx-select-all-checkbox');
        var $selectedCount = $('#ehx-selected-count');

        // Update selected count
        function updateSelectedCount() {
            var count = $('.ehx-article-checkbox:checked').length;
            $selectedCount.text(count);
            $bulkCreateBtn.prop('disabled', count === 0);
        }

        // Checkbox change handler
        $(document).on('change', '.ehx-article-checkbox', function() {
            updateSelectedCount();
        });

        // Select all checkbox
        $selectAllCheckbox.on('change', function() {
            var isChecked = $(this).is(':checked');
            $('.ehx-article-checkbox:visible').prop('checked', isChecked);
            updateSelectedCount();
        });

        // Select all button
        $selectAllBtn.on('click', function() {
            $('.ehx-article-checkbox:visible').prop('checked', true);
            $selectAllCheckbox.prop('checked', true);
            updateSelectedCount();
            $selectAllBtn.hide();
            $deselectAllBtn.show();
        });

        // Deselect all button
        $deselectAllBtn.on('click', function() {
            $('.ehx-article-checkbox').prop('checked', false);
            $selectAllCheckbox.prop('checked', false);
            updateSelectedCount();
            $selectAllBtn.show();
            $deselectAllBtn.hide();
        });

        // Search functionality
        $searchInput.on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();
            var $rows = $table.find('tbody tr');

            if (searchTerm === '') {
                $rows.show();
                return;
            }

            $rows.each(function() {
                var $row = $(this);
                var title = $row.data('title') || '';
                var category = $row.data('category') || '';
                var contributor = $row.data('contributor') || '';

                if (title.indexOf(searchTerm) !== -1 || 
                    category.indexOf(searchTerm) !== -1 || 
                    contributor.indexOf(searchTerm) !== -1) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        });

        // Sync articles (update existing, create new)
        $('#ehx-sync-articles').on('click', function() {
            var $btn = $(this);
            if (!confirm('This will sync all articles from the API. Existing posts will be updated and new articles will be created. Continue?')) {
                return;
            }

            $btn.prop('disabled', true).text(ehxArticles.strings.syncing);
            $loading.show();
            $error.hide();
            $success.hide();

            $.ajax({
                url: ehxArticles.ajax_url,
                type: 'POST',
                data: {
                    action: 'ehx_sync_articles',
                    nonce: ehxArticles.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $success.find('p').html(response.data.message);
                        $success.show();
                        
                        // Reload after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $error.find('p').text(response.data.message || 'Error syncing articles');
                        $error.show();
                    }
                },
                error: function() {
                    $error.find('p').text('Network error. Please try again.');
                    $error.show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Sync Articles');
                    $loading.hide();
                }
            });
        });

        // Refresh articles
        $refreshBtn.on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text(ehxArticles.strings.fetching);
            $loading.show();
            $error.hide();
            $success.hide();

            $.ajax({
                url: ehxArticles.ajax_url,
                type: 'POST',
                data: {
                    action: 'ehx_fetch_articles',
                    nonce: ehxArticles.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $error.find('p').text(response.data.message || 'Error fetching articles');
                        $error.show();
                    }
                },
                error: function() {
                    $error.find('p').text('Network error. Please try again.');
                    $error.show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Refresh Articles');
                    $loading.hide();
                }
            });
        });

        // Create post from article
        $(document).on('click', '.ehx-create-post', function() {
            var $btn = $(this);
            var articleId = $btn.data('article-id');
            var $row = $btn.closest('tr');

            if (!$btn.hasClass('loading')) {
                $btn.addClass('loading').prop('disabled', true);
                $error.hide();
                $success.hide();

                $.ajax({
                    url: ehxArticles.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ehx_create_post_from_article',
                        nonce: ehxArticles.nonce,
                        article_id: articleId
                    },
                    success: function(response) {
                        if (response.success) {
                            $success.find('p').html(
                                response.data.message + 
                                ' <a href="' + response.data.edit_link + '">' + 
                                'Edit Post</a>'
                            );
                            $success.show();

                            // Update the row to show "Post exists"
                            var actionsHtml = '<a href="' + response.data.edit_link + 
                                '" class="button button-small">Edit Post</a> ' +
                                '<span class="ehx-post-exists">Post exists</span>';
                            $row.find('.column-actions').html(actionsHtml);

                            // Scroll to success message
                            $('html, body').animate({
                                scrollTop: $success.offset().top - 50
                            }, 500);
                        } else {
                            $error.find('p').text(response.data.message || ehxArticles.strings.error);
                            $error.show();
                        }
                    },
                    error: function() {
                        $error.find('p').text('Network error. Please try again.');
                        $error.show();
                    },
                    complete: function() {
                        $btn.removeClass('loading').prop('disabled', false);
                    }
                });
            }
        });

        // Bulk create posts
        $bulkCreateBtn.on('click', function() {
            var $btn = $(this);
            var selectedIds = [];

            $('.ehx-article-checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length === 0) {
                $error.find('p').text('Please select at least one article.');
                $error.show();
                return;
            }

            if (!confirm('Are you sure you want to create ' + selectedIds.length + ' post(s)?')) {
                return;
            }

            $btn.prop('disabled', true).text('Creating posts...');
            $loading.show();
            $error.hide();
            $success.hide();

            $.ajax({
                url: ehxArticles.ajax_url,
                type: 'POST',
                data: {
                    action: 'ehx_bulk_create_posts',
                    nonce: ehxArticles.nonce,
                    article_ids: selectedIds
                },
                success: function(response) {
                    if (response.success) {
                        var message = response.data.message;
                        if (response.data.results && response.data.results.length > 0) {
                            message += '<br><small>Details: ';
                            var details = [];
                            response.data.results.forEach(function(result) {
                                if (result.status === 'success') {
                                    details.push(result.title + ' - Created');
                                } else if (result.status === 'skipped') {
                                    details.push(result.title + ' - Already exists');
                                } else {
                                    details.push(result.title + ' - Error');
                                }
                            });
                            message += details.join(', ') + '</small>';
                        }
                        
                        $success.find('p').html(message);
                        $success.show();

                        // Update rows to show "Post exists" for successfully created posts
                        response.data.results.forEach(function(result) {
                            if (result.status === 'success' && result.post_id) {
                                var $row = $('tr[data-article-id="' + result.article_id + '"]');
                                var actionsHtml = '<a href="' + 
                                    (result.edit_link || '#') + 
                                    '" class="button button-small">Edit Post</a> ' +
                                    '<span class="ehx-post-exists">Post exists</span>';
                                $row.find('.column-actions').html(actionsHtml);
                                $row.find('.ehx-article-checkbox').closest('th').html('');
                            }
                        });

                        // Clear selections
                        $('.ehx-article-checkbox').prop('checked', false);
                        $selectAllCheckbox.prop('checked', false);
                        updateSelectedCount();

                        // Scroll to success message
                        $('html, body').animate({
                            scrollTop: $success.offset().top - 50
                        }, 500);

                        // Reload after 3 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $error.find('p').text(response.data.message || 'Error creating posts');
                        $error.show();
                    }
                },
                error: function() {
                    $error.find('p').text('Network error. Please try again.');
                    $error.show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Create Selected Posts (' + $('.ehx-article-checkbox:checked').length + ')');
                    $loading.hide();
                }
            });
        });
    });
})(jQuery);
