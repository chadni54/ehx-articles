<?php

/**
 * Admin page template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap ehx-articles-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="ehx-articles-header">
        <div class="ehx-header-left">
            <button type="button" class="button button-primary" id="ehx-sync-articles">
                <?php _e('Sync Articles', 'ehx-articles'); ?>
            </button>
            <button type="button" class="button button-secondary" id="ehx-refresh-articles">
                <?php _e('Refresh Articles', 'ehx-articles'); ?>
            </button>
            <button type="button" class="button button-primary" id="ehx-bulk-create-posts" disabled>
                <?php _e('Create Selected Posts', 'ehx-articles'); ?> (<span id="ehx-selected-count">0</span>)
            </button>
            <button type="button" class="button button-secondary" id="ehx-select-all">
                <?php _e('Select All', 'ehx-articles'); ?>
            </button>
            <button type="button" class="button button-secondary" id="ehx-deselect-all" style="display: none;">
                <?php _e('Deselect All', 'ehx-articles'); ?>
            </button>
        </div>
        <div class="ehx-search-box">
            <input type="text" id="ehx-search-articles" placeholder="<?php esc_attr_e('Search articles...', 'ehx-articles'); ?>" class="regular-text">
        </div>
    </div>

    <div id="ehx-articles-loading" class="ehx-loading" style="display: none;">
        <p><?php _e('Loading articles...', 'ehx-articles'); ?></p>
    </div>

    <div id="ehx-articles-error" class="notice notice-error" style="display: none;">
        <p></p>
    </div>

    <div id="ehx-articles-success" class="notice notice-success" style="display: none;">
        <p></p>
    </div>

    <?php if (empty($articles)) : ?>
        <div class="notice notice-warning">
            <p><?php _e('No articles found. Please check your API connection.', 'ehx-articles'); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped table-view-list" id="ehx-articles-table">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" id="ehx-select-all-checkbox">
                    </th>
                    <th class="column-title"><?php _e('Title', 'ehx-articles'); ?></th>
                    <th class="column-category"><?php _e('Category', 'ehx-articles'); ?></th>
                    <th class="column-contributor"><?php _e('Contributor', 'ehx-articles'); ?></th>
                    <th class="column-time"><?php _e('Time to Read', 'ehx-articles'); ?></th>
                    <th class="column-actions"><?php _e('Actions', 'ehx-articles'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($articles as $article) :
                    $article_id = $article['id'];
                    $title = esc_html($article['title'] ?? '');
                    $category = esc_html($article['category']['categoryName'] ?? '');
                    $contributor_name = esc_html($article['contributor']['name'] ?? '');
                    $contributor_designation = esc_html($article['contributor']['designation'] ?? '');
                    $time_to_read = intval($article['timeToRead'] ?? 0);
                    $cover_image = esc_url($article['coverImageUrl'] ?? '');

                    // Check if post already exists
                    $existing_posts = get_posts(array(
                        'meta_key' => '_ehx_article_id',
                        'meta_value' => $article_id,
                        'post_type' => 'post',
                        'posts_per_page' => 1,
                    ));
                    $post_exists = !empty($existing_posts);
                    $existing_post_id = $post_exists ? $existing_posts[0]->ID : 0;
                ?>
                    <tr data-article-id="<?php echo esc_attr($article_id); ?>" data-title="<?php echo esc_attr(strtolower($title)); ?>" data-category="<?php echo esc_attr(strtolower($category)); ?>" data-contributor="<?php echo esc_attr(strtolower($contributor_name)); ?>">
                        <th scope="row" class="check-column">
                            <?php if (!$post_exists) : ?>
                                <input type="checkbox" class="ehx-article-checkbox" value="<?php echo esc_attr($article_id); ?>">
                            <?php endif; ?>
                        </th>
                        <td class="column-title">
                            <?php if ($cover_image) : ?>
                                <img src="<?php echo $cover_image; ?>" alt="<?php echo esc_attr($article['title'] ?? ''); ?>" style="width: 50px; height: 50px; object-fit: cover; margin-right: 10px; vertical-align: middle;">
                            <?php endif; ?>
                            <strong><?php echo $title; ?></strong>
                        </td>
                        <td class="column-category"><?php echo $category; ?></td>
                        <td class="column-contributor">
                            <strong><?php echo $contributor_name; ?></strong>
                            <?php if ($contributor_designation) : ?>
                                <br><em><?php echo $contributor_designation; ?></em>
                            <?php endif; ?>
                        </td>
                        <td class="column-time"><?php echo $time_to_read; ?> <?php _e('min', 'ehx-articles'); ?></td>
                        <td class="column-actions">
                            <?php if ($post_exists) : ?>
                                <a href="<?php echo get_edit_post_link($existing_post_id); ?>" class="button button-small">
                                    <?php _e('Edit Post', 'ehx-articles'); ?>
                                </a>
                                <span class="ehx-post-exists"><?php _e('Post exists', 'ehx-articles'); ?></span>
                            <?php else : ?>
                                <button type="button" class="button button-primary button-small ehx-create-post" data-article-id="<?php echo esc_attr($article_id); ?>">
                                    <?php _e('Create Post', 'ehx-articles'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>