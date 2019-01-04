<?php

/**
 * Plugin Name: LudicrousDB
 * Plugin URI:  https://github.com/stuttter/ludicrousdb
 * Author:      John James Jacoby
 * Author URI:  https://jjj.blog
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ludicrousdb
 * Version:     4.1.0
 * Description: An advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Custom directory name
$ldb_dirname     = defined( 'LDB_DIRNAME'     ) ? LDB_DIRNAME     : 'ludicrousdb';

// Supported plugin directories
$wp_plugin_dir   = defined( 'WP_PLUGIN_DIR'   ) ? WP_PLUGIN_DIR   : WP_CONTENT_DIR . '/plugins';
$wpmu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$wpdb_plugin_dir = defined( 'WPDB_PLUGIN_DIR' ) ? WPDB_PLUGIN_DIR : WP_CONTENT_DIR . '/db-plugins';

// Check /plugins/
if ( file_exists( "{$wp_plugin_dir}/{$ldb_dirname}/ludicrousdb.php" ) ) {
	require_once "{$wp_plugin_dir}/{$ldb_dirname}/ludicrousdb.php";

// Check /mu-plugins/
} elseif ( file_exists( "{$wpmu_plugin_dir}/{$ldb_dirname}/ludicrousdb.php" ) ) {
	require_once "{$wpmu_plugin_dir}/{$ldb_dirname}/ludicrousdb.php";

// Check /db-plugins/
} elseif ( file_exists( "{$wpdb_plugin_dir}/{$ldb_dirname}/ludicrousdb.php" ) ) {
	require_once "{$wpdb_plugin_dir}/{$ldb_dirname}/ludicrousdb.php";
}
