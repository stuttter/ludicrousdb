<?php

/**
 * Plugin Name: LudicrousDB
 * Plugin URI:  https://wordpress.org/plugins/ludicrousdb/
 * Author:      John James Jacoby
 * Author URI:  https://jjj.me/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ludicrousdb
 * Version:     3.0.0
 * Description: An advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Required files
require_once dirname( __FILE__ ) . '/ludicrousdb/includes/functions.php';
require_once dirname( __FILE__ ) . '/ludicrousdb/includes/class-ludicrousdb.php';

// Bail if database object is already set
if ( isset( $GLOBALS['wpdb'] ) ) {
	return;
}

// Set default constants
ldb_default_constants();

// No LudicrousDB config file found or set
if ( defined( 'DB_CONFIG_FILE' ) && file_exists( DB_CONFIG_FILE ) ) {
	$wpdb = new LudicrousDB();

	require_once DB_CONFIG_FILE;

// Fallback to WordPress's built-in database connection
} else {
	$GLOBALS['wpdb'] = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
}
