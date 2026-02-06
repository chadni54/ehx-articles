<?php

/**
 * Plugin Name: EHx Articles
 * Plugin URI: https://wordpress.org/plugins/ehx-articles
 * Description: Fetch and manage articles from external API, create WordPress posts from articles.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: EH Studio
 * Author URI: https://eh.studio
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ehx-articles
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EHXArticles_VERSION', '1.0.0');
define('EHXArticles_FILE', __FILE__);
define('EHXArticles_PATH', plugin_dir_path(__FILE__));
define('EHXArticles_URL', plugin_dir_url(__FILE__));
define('EHXArticles_BASENAME', plugin_basename(__FILE__));

// Include admin class
require_once EHXArticles_PATH . 'includes/class-ehx-articles-admin.php';

// Global instance
$GLOBALS['ehx_articles_admin'] = null;

/**
 * Initialize the plugin
 */
function ehx_articles_init()
{
    if (is_admin()) {
        $GLOBALS['ehx_articles_admin'] = new EHX_Articles_Admin();
    } else {
        // Initialize for cron even if not admin
        $GLOBALS['ehx_articles_admin'] = new EHX_Articles_Admin();
    }
}

// Initialize the plugin
add_action('plugins_loaded', 'ehx_articles_init');

// Register activation/deactivation hooks for cron
register_activation_hook(__FILE__, 'ehx_articles_activate');
register_deactivation_hook(__FILE__, 'ehx_articles_deactivate');


//  Activate plugin - schedule cron jobs

function ehx_articles_activate()
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
 * Deactivate plugin - unschedule cron jobs
 */
function ehx_articles_deactivate()
{
    $timestamp = wp_next_scheduled('ehx_articles_daily_fetch');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ehx_articles_daily_fetch');
    }

    $timestamp = wp_next_scheduled('ehx_articles_daily_create_posts');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ehx_articles_daily_create_posts');
    }
}
