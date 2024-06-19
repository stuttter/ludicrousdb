<?php

/**
 * Plugin Name:       LudicrousDB
 * Description:       An advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning.
 * Author:            Triple J Software, Inc.
 * License:           GPL v2 or later
 * Plugin URI:        https://github.com/stuttter/ludicrousdb
 * Author URI:        https://jjj.software
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ludicrousdb
 * Requires PHP:      7.4
 * Requires at least: 5.0
 * Version:           5.2.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Bail if database object is already set.
 *
 * If you've activated this plugin like any other plugin, and came looking here
 * because this plugin isn't doing anything, keep reading.
 *
 * LudicrousDB is a Drop-in plugin, and must be included extremely early in
 * WordPress, specifically before the database connection happens.
 *
 * The bit of code below prevents LudicrousDB from obliterating any existing
 * database connections. This is by design, to keep your site safe.
 *
 * Look at /drop-ins/db.php for more info.
 */
if ( isset( $GLOBALS['wpdb'] ) ) {
	return;
}

// Required files
require_once __DIR__ . '/ludicrousdb/includes/functions.php';
require_once __DIR__ . '/ludicrousdb/includes/class-ludicrousdb.php';

// Set default constants
ldb_default_constants();

// Create database object
// phpcs:ignore
$wpdb = new LudicrousDB();

// Include LudicrousDB config file if found or set
if ( defined( 'DB_CONFIG_FILE' ) && file_exists( DB_CONFIG_FILE ) ) {
	require_once DB_CONFIG_FILE;
}
