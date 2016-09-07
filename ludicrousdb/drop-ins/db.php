<?php

/**
 * Plugin Name: LudicrousDB
 * Plugin URI:  https://github.com/stuttter/ludicrousdb
 * Author:      John James Jacoby
 * Author URI:  https://github.com/stuttter/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Description: An advanced database class that supports replication, failover, load balancing, and partitioning.
 * Version:     3.0.0
 * Text Domain: ludicrousdb
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Require the main plugin file
if ( file_exists( WP_CONTENT_DIR . '/plugins/ludicrousdb/ludicrousdb.php' ) ) {
	require_once WP_CONTENT_DIR . '/plugins/ludicrousdb/ludicrousdb.php';
} elseif ( file_exists( WP_CONTENT_DIR . '/mu-plugins/ludicrousdb/ludicrousdb.php' ) ) {
	require_once WP_CONTENT_DIR . '/mu-plugins/ludicrousdb/ludicrousdb.php';
}
