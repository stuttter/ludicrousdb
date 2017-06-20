<?php

/**
 * Plugin Name: LudicrousDB
 * Plugin URI:  https://wordpress.org/plugins/ludicrousdb/
 * Author:      John James Jacoby
 * Author URI:  https://jjj.blog
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ludicrousdb
 * Version:     3.0.0
 * Description: An advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

$wp_plugin_dir = ( defined('WP_PLUGIN_DIR') ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
$wpmu_plugin_dir = ( defined('WPMU_PLUGIN_DIR') ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

// Require the main plugin file
if ( file_exists(  $wp_plugin_dir . '/ludicrousdb/ludicrousdb.php' ) ) {
	require_once $wp_plugin_dir . '/ludicrousdb/ludicrousdb.php';
} elseif ( file_exists( $wpmu_plugin_dir . '/ludicrousdb/ludicrousdb.php' ) ) {
	require_once $wpmu_plugin_dir . '/ludicrousdb/ludicrousdb.php';
}
