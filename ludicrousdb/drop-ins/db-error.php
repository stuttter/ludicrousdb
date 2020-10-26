<?php

/**
 * Plugin Name: LudicrousDB (Error)
 * Plugin URI:  https://github.com/stuttter/ludicrousdb
 * Author:      JJJ & Friends
 * Author URI:  https://github.com/stuttter/ludicrousdb/graphs/contributors
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ludicrousdb
 * Version:     5.0.0
 * Description: An advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning
 */

/**
 * LudicrousDB fatal database error file
 *
 * This file should be copied to WP_CONTENT_DIR/db-error.php and modified to
 * better match your website.
 *
 * See README.md for documentation.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Prevent caching
status_header( 500 ); // Error
nocache_headers();    // No cache

// Set content type & charset to be generic & friendly
header( 'Content-Type: text/html; charset=utf-8' );

/** Start Editing */

?><!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml"
<?php
if ( is_rtl() ) {
	echo ' dir="rtl"';
}
?>
>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title><?php esc_html_e( 'Database Error', 'ludicrousdb' ); ?></title>
</head>
<body>
<h1><?php esc_html_e( 'Error establishing a database connection', 'ludicrousdb' ); ?></h1>
</body>
</html>
<?php

/** Stop Editing **/

// Prevent additional output
die();
