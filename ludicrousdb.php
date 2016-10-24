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

// Bail if database object is already set
if ( isset( $GLOBALS['wpdb'] ) ) {
	return;
}

// Required files
require_once dirname( __FILE__ ) . '/ludicrousdb/includes/functions.php';
require_once dirname( __FILE__ ) . '/ludicrousdb/includes/class-ludicrousdb.php';

// Set default constants
ldb_default_constants();

// Create database object
$wpdb = new LudicrousDB();

// Include LudicrousDB config file if found or set
if ( defined( 'DB_CONFIG_FILE' ) && file_exists( DB_CONFIG_FILE ) ) {
	require_once DB_CONFIG_FILE;
}
