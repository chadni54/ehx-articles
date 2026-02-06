<?php

class EHX_Articles_Admin
{

    private $api_url = 'http://18.134.174.216:3000/api/v1/articles/public/articles';
    private $api_key = 'ext_articles_9f3bA72KxP1LmQe8RZcH4WJdM0YVNaS';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ehx_create_post_from_article', array($this, 'ajax_create_post_from_article'));
        add_action('wp_ajax_ehx_fetch_articles', array($this, 'ajax_fetch_articles'));
        add_action('wp_ajax_ehx_bulk_create_posts', array($this, 'ajax_bulk_create_posts'));
        add_action('wp_ajax_ehx_sync_articles', array($this, 'ajax_sync_articles'));
        add_action('wp_ajax_ehx_create_posts_manual', array($this, 'ajax_create_posts_manual'));

        // Schedule daily auto article fetch
        add_action('ehx_articles_daily_fetch', array($this, 'daily_auto_fetch_articles'));

        // Schedule daily auto post creation
        add_action('ehx_articles_daily_create_posts', array($this, 'daily_auto_create_posts'));

        // Schedule cron on init if not already scheduled
        add_action('init', array($this, 'schedule_daily_cron'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            __('EHx Articles', 'ehx-articles'),
            __('EHx Articles', 'ehx-articles'),
            'manage_options',
            'ehx-articles',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        if ($hook !== 'settings_page_ehx-articles') {
            return;
        }

        wp_enqueue_style(
            'ehx-articles-admin',
            EHXArticles_URL . 'assets/css/admin.css',
            array(),
            EHXArticles_VERSION
        );

        wp_enqueue_script(
            'ehx-articles-admin',
            EHXArticles_URL . 'assets/js/admin.js',
            array('jquery'),
            EHXArticles_VERSION,
            true
        );

        wp_localize_script('ehx-articles-admin', 'ehxArticles', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ehx_articles_nonce'),
            'strings' => array(
                'creating' => __('Creating post...', 'ehx-articles'),
                'success' => __('Post created successfully!', 'ehx-articles'),
                'error' => __('Error creating post.', 'ehx-articles'),
                'fetching' => __('Fetching articles...', 'ehx-articles'),
                'syncing' => __('Syncing articles...', 'ehx-articles'),
            )
        ));
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        $articles = $this->fetch_articles();
        include EHXArticles_PATH . 'includes/admin-page.php';
    }

    /**
     * Fetch articles from API
     */
    private function fetch_articles()
    {
        $transient_key = 'ehx_articles_cache';
        $articles = get_transient($transient_key);

        if (false === $articles) {
            $articles = $this->force_fetch_articles();
            // Cache for 5 minutes
            if (!empty($articles)) {
                set_transient($transient_key, $articles, 5 * MINUTE_IN_SECONDS);
            }
        }

        return $articles;
    }

    /**
     * Force fetch articles from API (bypass cache)
     */
    private function force_fetch_articles()
    {
        $response = wp_remote_get($this->api_url, array(
            'headers' => array(
                'accept' => '/',
                'x-api-key' => $this->api_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['success']) && $data['success'] && isset($data['data']['articles'])) {
            return $data['data']['articles'];
        }

        return array();
    }

    /**
     * AJAX handler to fetch articles
     */
    public function ajax_fetch_articles()
    {
        check_ajax_referer('ehx_articles_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ehx-articles')));
        }

        // Clear cache and fetch fresh articles
        delete_transient('ehx_articles_cache');
        $articles = $this->force_fetch_articles();

        // Store last fetch time
        update_option('ehx_articles_last_fetch', current_time('mysql'));

        wp_send_json_success(array('articles' => $articles));
    }

    /**
     * AJAX handler to create WordPress post from article
     */
    public function ajax_create_post_from_article()
    {
        check_ajax_referer('ehx_articles_nonce', 'nonce');

        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ehx-articles')));
        }

        $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;

        if (!$article_id) {
            wp_send_json_error(array('message' => __('Invalid article ID', 'ehx-articles')));
        }

        // Fetch articles to get the specific one
        $articles = $this->fetch_articles();
        $article = null;

        foreach ($articles as $art) {
            if ($art['id'] == $article_id) {
                $article = $art;
                break;
            }
        }

        if (!$article) {
            wp_send_json_error(array('message' => __('Article not found', 'ehx-articles')));
        }

        // Check if post already exists
        $existing_posts = get_posts(array(
            'meta_key' => '_ehx_article_id',
            'meta_value' => $article_id,
            'post_type' => 'post',
            'posts_per_page' => 1,
        ));

        if (!empty($existing_posts)) {
            wp_send_json_error(array('message' => __('Post already exists for this article', 'ehx-articles')));
        }

        // Create post content
        $post_content = $this->format_post_content($article);

        // Get contributor information
        $contributor_name = sanitize_text_field($article['contributor']['name'] ?? '');
        $contributor_id = isset($article['contributor']['id']) ? intval($article['contributor']['id']) : 0;

        if (empty($contributor_name)) {
            wp_send_json_error(array('message' => __('Contributor name is required', 'ehx-articles')));
        }

        // Use contributor ID + name to ensure unique author for each contributor
        $author_id = $this->get_or_create_author($contributor_name, $contributor_id);

        // Verify author was created/found
        if (empty($author_id) || !is_numeric($author_id)) {
            wp_send_json_error(array('message' => __('Failed to get or create author user', 'ehx-articles')));
        }

        // Verify the author's display name matches contributor name
        $author_user = get_user_by('ID', $author_id);
        if ($author_user && $author_user->display_name !== $contributor_name) {
            wp_update_user(array(
                'ID' => $author_id,
                'display_name' => $contributor_name,
                'nickname' => $contributor_name,
            ));
        }

        // Create WordPress post
        $post_data = array(
            'post_title' => sanitize_text_field($article['title']),
            'post_content' => $post_content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => intval($author_id),
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => $post_id->get_error_message()));
        }

        // Double-check and update author if needed
        $created_post = get_post($post_id);
        if ($created_post && $created_post->post_author != $author_id) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_author' => $author_id,
            ));
        }

        // Save article metadata
        update_post_meta($post_id, '_ehx_article_id', $article_id);
        update_post_meta($post_id, '_ehx_category_name', sanitize_text_field($article['category']['categoryName'] ?? ''));
        update_post_meta($post_id, '_ehx_contributor_name', sanitize_text_field($article['contributor']['name'] ?? ''));
        $designation = sanitize_text_field($article['contributor']['designation'] ?? '');
        $time_to_read = intval($article['timeToRead'] ?? 0);
        update_post_meta($post_id, '_ehx_contributor_designation', $designation);
        update_post_meta($post_id, '_ehx_time_to_read', $time_to_read);
        update_post_meta($post_id, '_ehx_cover_image_url', esc_url_raw($article['coverImageUrl'] ?? ''));

        // Also save into normal custom fields so they appear in the default "Custom Fields" meta box
        // These will use the simple keys: designation, timeToRead
        if (!empty($designation)) {
            update_post_meta($post_id, 'designation', $designation);
        }
        if (!empty($time_to_read)) {
            update_post_meta($post_id, 'timeToRead', $time_to_read);
        }

        // Set featured image if cover image exists
        if (!empty($article['coverImageUrl'])) {
            $this->set_featured_image($post_id, $article['coverImageUrl']);
        }

        // Set category
        if (!empty($article['category']['categoryName'])) {
            $category_name = sanitize_text_field($article['category']['categoryName']);
            $term = get_term_by('name', $category_name, 'category');
            if (!$term) {
                $term = wp_insert_term($category_name, 'category');
                if (!is_wp_error($term)) {
                    wp_set_post_categories($post_id, array($term['term_id']));
                }
            } else {
                wp_set_post_categories($post_id, array($term->term_id));
            }
        }

        // Get author name for confirmation
        $author = get_user_by('ID', $author_id);
        $author_name = $author ? $author->display_name : $contributor_name;

        wp_send_json_success(array(
            'message' => sprintf(__('Post created successfully! Author: %s', 'ehx-articles'), $author_name),
            'post_id' => $post_id,
            'author_name' => $author_name,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
        ));
    }

    /**
     * Format article content for WordPress post
     */
    private function format_post_content($article)
    {
        $content = $article['articleDescription'] ?? '';

        // Add contributor information at the top
        if (!empty($article['contributor'])) {
            $contributor = $article['contributor'];
            $contributor_html = '<div class="ehx-article-contributor" style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 5px;">';

            if (!empty($contributor['profileImageUrl'])) {
                $contributor_html .= '<img src="' . esc_url($contributor['profileImageUrl']) . '" alt="' . esc_attr($contributor['name']) . '" style="width: 60px; height: 60px; border-radius: 50%; float: left; margin-right: 15px;">';
            }

            $contributor_html .= '<div>';
            $contributor_html .= '<strong>' . esc_html($contributor['name']) . '</strong>';

            if (!empty($contributor['designation'])) {
                $contributor_html .= '<br><em>' . esc_html($contributor['designation']) . '</em>';
            }

            $contributor_html .= '</div>';
            $contributor_html .= '<div style="clear: both;"></div>';
            $contributor_html .= '</div>';

            $content = $contributor_html . $content;
        }

        // Add time to read at the bottom
        if (!empty($article['timeToRead'])) {
            $content .= '<p><em>Reading time: ' . intval($article['timeToRead']) . ' minutes</em></p>';
        }

        return $content;
    }

    /**
     * Get or create WordPress user from contributor name
     * This ensures the post author displays as the contributor name
     * Each contributor gets their own unique user
     */
    private function get_or_create_author($contributor_name, $contributor_id = 0)
    {
        if (empty($contributor_name)) {
            // Return current user ID if no contributor name
            return get_current_user_id();
        }

        // Sanitize contributor name
        $contributor_name = trim($contributor_name);

        // First, try to find user by contributor ID in user meta (most reliable)
        if ($contributor_id > 0) {
            $users = get_users(array(
                'meta_key' => '_ehx_contributor_id',
                'meta_value' => $contributor_id,
                'number' => 1,
            ));

            if (!empty($users)) {
                $user = $users[0];
                // Update display name to ensure it matches contributor name
                if ($user->display_name !== $contributor_name) {
                    wp_update_user(array(
                        'ID' => $user->ID,
                        'display_name' => $contributor_name,
                        'nickname' => $contributor_name,
                    ));
                }
                return $user->ID;
            }
        }

        // Try to find existing user by display name (for backward compatibility)
        $user = get_user_by('display_name', $contributor_name);

        // If not found, try by login (sanitized username)
        if (!$user) {
            $sanitized_username = sanitize_user($contributor_name, true);
            if (!empty($sanitized_username)) {
                $user = get_user_by('login', $sanitized_username);
            }
        }

        // If user exists and has no contributor ID, update it
        if ($user && isset($user->ID)) {
            // Update display name to ensure it matches contributor name
            if ($user->display_name !== $contributor_name) {
                wp_update_user(array(
                    'ID' => $user->ID,
                    'display_name' => $contributor_name,
                    'nickname' => $contributor_name,
                ));
            }
            // Save contributor ID for future lookups
            if ($contributor_id > 0) {
                update_user_meta($user->ID, '_ehx_contributor_id', $contributor_id);
            }
            return $user->ID;
        }

        // Create new user with contributor name
        $username = sanitize_user($contributor_name, true);

        // If username is empty after sanitization, create a fallback
        if (empty($username)) {
            $username = 'contributor_' . time() . '_' . rand(1000, 9999);
        }

        // Make sure username is unique
        $original_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
            if ($counter > 100) {
                // Prevent infinite loop
                $username = 'contributor_' . time() . '_' . rand(10000, 99999);
                break;
            }
        }

        // Generate email from username
        $email_base = strtolower(str_replace(array(' ', '_', '-'), '.', $username));
        $email = $email_base . '@example.com';

        // Make sure email is unique
        $original_email = $email;
        $counter = 1;
        while (email_exists($email)) {
            $email = str_replace('@example.com', $counter . '@example.com', $original_email);
            $counter++;
            if ($counter > 100) {
                $email = 'contributor.' . time() . '@example.com';
                break;
            }
        }

        // Validate email format
        if (!is_email($email)) {
            $email = 'contributor.' . time() . '@example.com';
        }

        // Create the user
        $password = wp_generate_password(16, false);
        $user_data = array(
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'display_name' => $contributor_name, // This is what will show in the Author column
            'nickname' => $contributor_name,
            'first_name' => $contributor_name,
            'role' => 'author',
        );

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            // Log error for debugging
            error_log('EHX Articles: Failed to create user "' . $contributor_name . '" - ' . $user_id->get_error_message());
            // If user creation fails, return current user ID
            return get_current_user_id();
        }

        // Verify user was created
        $created_user = get_user_by('ID', $user_id);
        if (!$created_user) {
            error_log('EHX Articles: User ID created but user not found: ' . $user_id);
            return get_current_user_id();
        }

        // Ensure display_name is set to contributor name (this is what shows in Author column)
        if ($created_user->display_name !== $contributor_name) {
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $contributor_name,
                'nickname' => $contributor_name,
                'first_name' => $contributor_name,
            ));
        }

        // Save contributor ID for future lookups (ensures each contributor gets unique user)
        if ($contributor_id > 0) {
            update_user_meta($user_id, '_ehx_contributor_id', $contributor_id);
        }

        return $user_id;
    }

    /**
     * Set featured image from URL
     */
    private function set_featured_image($post_id, $image_url)
    {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );

        $id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        set_post_thumbnail($post_id, $id);
        return true;
    }

    /**
     * Schedule daily cron jobs
     */
    public function schedule_daily_cron()
    {
        // Schedule daily article fetch at 2 AM
        if (!wp_next_scheduled('ehx_articles_daily_fetch')) {
            wp_schedule_event(time(), 'daily', 'ehx_articles_daily_fetch');
        }

        // Schedule daily post creation at 3 AM (after articles are fetched)
        if (!wp_next_scheduled('ehx_articles_daily_create_posts')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ehx_articles_daily_create_posts');
        }
    }

    /**
     * Daily auto fetch articles from API
     */
    public function daily_auto_fetch_articles()
    {
        // Clear cache and fetch fresh articles
        delete_transient('ehx_articles_cache');
        $articles = $this->force_fetch_articles();

        // Store last fetch time
        update_option('ehx_articles_last_fetch', current_time('mysql'));

        error_log(sprintf(
            'EHX Articles: Daily auto-fetch completed. Articles fetched: %d',
            count($articles)
        ));

        // After fetching articles, automatically create/update posts
        // This ensures posts are created right after articles are fetched
        $this->daily_auto_create_posts();
    }

    /**
     * Daily auto create posts from articles
     */
    public function daily_auto_create_posts()
    {
        $result = $this->sync_articles();

        // Store last post creation time
        update_option('ehx_articles_last_post_creation', current_time('mysql'));

        error_log(sprintf(
            'EHX Articles: Daily auto-post creation completed. Created: %d, Updated: %d, Errors: %d',
            $result['created'],
            $result['updated'],
            $result['errors']
        ));
    }

    /**
     * Sync articles - update existing posts and create new ones
     */
    public function sync_articles()
    {
        $articles = $this->force_fetch_articles();
        $created_count = 0;
        $updated_count = 0;
        $error_count = 0;

        foreach ($articles as $article) {
            $article_id = $article['id'] ?? 0;

            if (!$article_id) {
                $error_count++;
                continue;
            }

            // Check if post already exists
            $existing_posts = get_posts(array(
                'meta_key' => '_ehx_article_id',
                'meta_value' => $article_id,
                'post_type' => 'post',
                'posts_per_page' => 1,
            ));

            if (!empty($existing_posts)) {
                // Update existing post
                $result = $this->update_post_from_article($existing_posts[0]->ID, $article);
                if ($result && !is_wp_error($result)) {
                    $updated_count++;
                } else {
                    $error_count++;
                }
            } else {
                // Create new post
                $result = $this->create_post_from_article($article);
                if ($result && !is_wp_error($result)) {
                    $created_count++;
                } else {
                    $error_count++;
                }
            }
        }

        // Clear cache after sync
        delete_transient('ehx_articles_cache');

        return array(
            'created' => $created_count,
            'updated' => $updated_count,
            'errors' => $error_count,
            'total' => count($articles)
        );
    }

    /**
     * Update existing post from article data
     */
    private function update_post_from_article($post_id, $article)
    {
        if (!$post_id || empty($article)) {
            return false;
        }

        // Create post content
        $post_content = $this->format_post_content($article);

        // Get contributor information
        $contributor_name = sanitize_text_field($article['contributor']['name'] ?? '');
        $contributor_id = isset($article['contributor']['id']) ? intval($article['contributor']['id']) : 0;

        if (empty($contributor_name)) {
            return false;
        }

        // Get or create author
        $author_id = $this->get_or_create_author($contributor_name, $contributor_id);

        if (empty($author_id) || !is_numeric($author_id)) {
            return false;
        }

        // Verify the author's display name matches contributor name
        $author_user = get_user_by('ID', $author_id);
        if ($author_user && $author_user->display_name !== $contributor_name) {
            wp_update_user(array(
                'ID' => $author_id,
                'display_name' => $contributor_name,
                'nickname' => $contributor_name,
            ));
        }

        // Update WordPress post
        $post_data = array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field($article['title']),
            'post_content' => $post_content,
            'post_author' => intval($author_id),
        );

        $result = wp_update_post($post_data);

        if (is_wp_error($result)) {
            return false;
        }

        // Update article metadata
        update_post_meta($post_id, '_ehx_category_name', sanitize_text_field($article['category']['categoryName'] ?? ''));
        update_post_meta($post_id, '_ehx_contributor_name', sanitize_text_field($article['contributor']['name'] ?? ''));
        $designation = sanitize_text_field($article['contributor']['designation'] ?? '');
        $time_to_read = intval($article['timeToRead'] ?? 0);
        update_post_meta($post_id, '_ehx_contributor_designation', $designation);
        update_post_meta($post_id, '_ehx_time_to_read', $time_to_read);
        update_post_meta($post_id, '_ehx_cover_image_url', esc_url_raw($article['coverImageUrl'] ?? ''));

        // Also keep normal custom fields in sync
        if (!empty($designation)) {
            update_post_meta($post_id, 'designation', $designation);
        }
        if (!empty($time_to_read)) {
            update_post_meta($post_id, 'timeToRead', $time_to_read);
        }

        // Update featured image if cover image exists and is different
        if (!empty($article['coverImageUrl'])) {
            $current_thumbnail = get_post_thumbnail_id($post_id);
            if (!$current_thumbnail) {
                // Only set if no featured image exists
                $this->set_featured_image($post_id, $article['coverImageUrl']);
            }
        }

        // Update category
        if (!empty($article['category']['categoryName'])) {
            $category_name = sanitize_text_field($article['category']['categoryName']);
            $term = get_term_by('name', $category_name, 'category');
            if (!$term) {
                $term = wp_insert_term($category_name, 'category');
                if (!is_wp_error($term)) {
                    wp_set_post_categories($post_id, array($term['term_id']));
                }
            } else {
                wp_set_post_categories($post_id, array($term->term_id));
            }
        }

        return $post_id;
    }

    /**
     * Create post from article (extracted for reuse)
     */
    private function create_post_from_article($article)
    {
        $article_id = $article['id'] ?? 0;

        if (!$article_id) {
            return false;
        }

        // Check if post already exists
        $existing_posts = get_posts(array(
            'meta_key' => '_ehx_article_id',
            'meta_value' => $article_id,
            'post_type' => 'post',
            'posts_per_page' => 1,
        ));

        if (!empty($existing_posts)) {
            return false; // Post already exists
        }

        // Create post content
        $post_content = $this->format_post_content($article);

        // Get contributor information
        $contributor_name = sanitize_text_field($article['contributor']['name'] ?? '');
        $contributor_id = isset($article['contributor']['id']) ? intval($article['contributor']['id']) : 0;

        if (empty($contributor_name)) {
            return false;
        }

        // Get or create author
        $author_id = $this->get_or_create_author($contributor_name, $contributor_id);

        if (empty($author_id) || !is_numeric($author_id)) {
            return false;
        }

        // Verify the author's display name matches contributor name
        $author_user = get_user_by('ID', $author_id);
        if ($author_user && $author_user->display_name !== $contributor_name) {
            wp_update_user(array(
                'ID' => $author_id,
                'display_name' => $contributor_name,
                'nickname' => $contributor_name,
            ));
        }

        // Create WordPress post
        $post_data = array(
            'post_title' => sanitize_text_field($article['title']),
            'post_content' => $post_content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => intval($author_id),
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return false;
        }

        // Double-check and update author if needed
        $created_post = get_post($post_id);
        if ($created_post && $created_post->post_author != $author_id) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_author' => $author_id,
            ));
        }

        // Save article metadata
        update_post_meta($post_id, '_ehx_article_id', $article_id);
        update_post_meta($post_id, '_ehx_category_name', sanitize_text_field($article['category']['categoryName'] ?? ''));
        update_post_meta($post_id, '_ehx_contributor_name', sanitize_text_field($article['contributor']['name'] ?? ''));
        $designation = sanitize_text_field($article['contributor']['designation'] ?? '');
        $time_to_read = intval($article['timeToRead'] ?? 0);
        update_post_meta($post_id, '_ehx_contributor_designation', $designation);
        update_post_meta($post_id, '_ehx_time_to_read', $time_to_read);
        update_post_meta($post_id, '_ehx_cover_image_url', esc_url_raw($article['coverImageUrl'] ?? ''));

        // Also save into normal custom fields for visibility in the meta box
        if (!empty($designation)) {
            update_post_meta($post_id, 'designation', $designation);
        }
        if (!empty($time_to_read)) {
            update_post_meta($post_id, 'timeToRead', $time_to_read);
        }

        // Set featured image if cover image exists
        if (!empty($article['coverImageUrl'])) {
            $this->set_featured_image($post_id, $article['coverImageUrl']);
        }

        // Set category
        if (!empty($article['category']['categoryName'])) {
            $category_name = sanitize_text_field($article['category']['categoryName']);
            $term = get_term_by('name', $category_name, 'category');
            if (!$term) {
                $term = wp_insert_term($category_name, 'category');
                if (!is_wp_error($term)) {
                    wp_set_post_categories($post_id, array($term['term_id']));
                }
            } else {
                wp_set_post_categories($post_id, array($term->term_id));
            }
        }

        return $post_id;
    }

    /**
     * AJAX handler for bulk post creation
     */
    public function ajax_bulk_create_posts()
    {
        check_ajax_referer('ehx_articles_nonce', 'nonce');

        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ehx-articles')));
        }

        $article_ids = isset($_POST['article_ids']) ? array_map('intval', $_POST['article_ids']) : array();

        if (empty($article_ids)) {
            wp_send_json_error(array('message' => __('No articles selected', 'ehx-articles')));
        }

        // Fetch all articles
        $articles = $this->fetch_articles();
        $articles_map = array();
        foreach ($articles as $article) {
            $articles_map[$article['id']] = $article;
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $results = array();

        foreach ($article_ids as $article_id) {
            if (!isset($articles_map[$article_id])) {
                $errors++;
                $results[] = array(
                    'article_id' => $article_id,
                    'status' => 'error',
                    'message' => __('Article not found', 'ehx-articles')
                );
                continue;
            }

            $article = $articles_map[$article_id];

            // Check if post already exists
            $existing_posts = get_posts(array(
                'meta_key' => '_ehx_article_id',
                'meta_value' => $article_id,
                'post_type' => 'post',
                'posts_per_page' => 1,
            ));

            if (!empty($existing_posts)) {
                $skipped++;
                $results[] = array(
                    'article_id' => $article_id,
                    'status' => 'skipped',
                    'message' => __('Post already exists', 'ehx-articles')
                );
                continue;
            }

            // Create post
            $post_id = $this->create_post_from_article($article);

            if ($post_id && !is_wp_error($post_id)) {
                $created++;
                $results[] = array(
                    'article_id' => $article_id,
                    'status' => 'success',
                    'post_id' => $post_id,
                    'title' => $article['title'],
                    'message' => __('Post created successfully', 'ehx-articles')
                );
            } else {
                $errors++;
                $results[] = array(
                    'article_id' => $article_id,
                    'status' => 'error',
                    'message' => __('Failed to create post', 'ehx-articles')
                );
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Bulk creation completed! Created: %d, Skipped: %d, Errors: %d', 'ehx-articles'),
                $created,
                $skipped,
                $errors
            ),
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'results' => $results
        ));
    }

    /**
     * AJAX handler to sync articles (update existing, create new)
     */
    public function ajax_sync_articles()
    {
        check_ajax_referer('ehx_articles_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ehx-articles')));
        }

        $result = $this->sync_articles();

        // Update last post creation time
        update_option('ehx_articles_last_post_creation', current_time('mysql'));

        wp_send_json_success(array(
            'message' => sprintf(
                __('Sync completed! Created: %d, Updated: %d, Errors: %d', 'ehx-articles'),
                $result['created'],
                $result['updated'],
                $result['errors']
            ),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'errors' => $result['errors'],
            'total' => $result['total']
        ));
    }

    /**
     * AJAX handler to manually create posts from fetched articles
     */
    public function ajax_create_posts_manual()
    {
        check_ajax_referer('ehx_articles_nonce', 'nonce');

        if (!current_user_can('publish_posts')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ehx-articles')));
        }

        $result = $this->sync_articles();

        // Update last post creation time
        update_option('ehx_articles_last_post_creation', current_time('mysql'));

        wp_send_json_success(array(
            'message' => sprintf(
                __('Posts created/updated! Created: %d, Updated: %d, Errors: %d', 'ehx-articles'),
                $result['created'],
                $result['updated'],
                $result['errors']
            ),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'errors' => $result['errors'],
            'total' => $result['total']
        ));
    }

    /**
     * Get next scheduled fetch time
     */
    public function get_next_fetch_time()
    {
        $timestamp = wp_next_scheduled('ehx_articles_daily_fetch');
        if ($timestamp) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }
        return __('Not scheduled', 'ehx-articles');
    }

    /**
     * Get next scheduled post creation time
     */
    public function get_next_post_creation_time()
    {
        $timestamp = wp_next_scheduled('ehx_articles_daily_create_posts');
        if ($timestamp) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }
        return __('Not scheduled', 'ehx-articles');
    }

    /**
     * Get last fetch time
     */
    public function get_last_fetch_time()
    {
        $time = get_option('ehx_articles_last_fetch');
        if ($time) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($time));
        }
        return __('Never', 'ehx-articles');
    }

    /**
     * Get last post creation time
     */
    public function get_last_post_creation_time()
    {
        $time = get_option('ehx_articles_last_post_creation');
        if ($time) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($time));
        }
        return __('Never', 'ehx-articles');
    }
}
