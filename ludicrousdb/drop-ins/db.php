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

// Require the main plugin file
if ( file_exists( WP_CONTENT_DIR . '/plugins/ludicrousdb/ludicrousdb.php' ) ) {
	require_once WP_CONTENT_DIR . '/plugins/ludicrousdb/ludicrousdb.php';
} elseif ( file_exists( WP_CONTENT_DIR . '/mu-plugins/ludicrousdb/ludicrousdb.php' ) ) {
	require_once WP_CONTENT_DIR . '/mu-plugins/ludicrousdb/ludicrousdb.php';
}
