<?php
/**
 * Exercise the drop-in with WordPress's default empty DB_COLLATE.
 *
 * Run with WP-CLI's eval-file command against an installed WordPress site.
 *
 * @package LudicrousDB
 */

defined( 'ABSPATH' ) || exit( 1 );

global $wpdb;

if ( ! $wpdb instanceof LudicrousDB ) {
	throw new RuntimeException( 'LudicrousDB did not load.' );
}

if ( '' !== DB_COLLATE ) {
	throw new RuntimeException( 'This regression requires the default empty DB_COLLATE.' );
}

if ( empty( $wpdb->charset ) ) {
	throw new RuntimeException( 'The database character set was not initialized.' );
}

if ( '1' !== $wpdb->get_var( 'SELECT 1' ) ) {
	throw new RuntimeException( 'The database connection is not usable.' );
}

if ( is_multisite() && 1 > (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_blogs' ) ) {
	throw new RuntimeException( 'The multisite network is not readable.' );
}
