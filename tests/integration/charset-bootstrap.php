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

if ( ! defined( 'DB_CHARSET' ) || '' === DB_CHARSET || ( defined( 'DB_COLLATE' ) && '' !== DB_COLLATE ) || defined( 'DB_CONFIG_FILE' ) ) {
	throw new RuntimeException( 'This regression requires the default empty DB_COLLATE.' );
}

if ( empty( $wpdb->charset ) ) {
	throw new RuntimeException( 'The database character set was not initialized.' );
}

$connection = array(
	'host'     => DB_HOST,
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
);

$fresh = new LudicrousDB();
$fresh->add_database( $connection );

if ( '1' !== (string) $fresh->get_var( 'SELECT 1' ) ) {
	throw new RuntimeException( 'The database connection is not usable.' );
}

$expected = $fresh->determine_charset( DB_CHARSET, defined( 'DB_COLLATE' ) ? DB_COLLATE : '' );

if ( $expected['charset'] !== $fresh->charset || $expected['collate'] !== $fresh->collate ) {
	throw new RuntimeException( 'The live connection did not resolve the WordPress charset and collation.' );
}

if ( $fresh->charset !== $fresh->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'The connection is using a different character set.' );
}

$connection_charset = $fresh->get_var( 'SELECT @@character_set_connection' );
$fresh->set_charset( $fresh->dbh, '', '' );
if ( $connection_charset !== $fresh->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'An empty charset changed the connection character set.' );
}

if ( is_multisite() && 1 > (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs}" ) ) {
	throw new RuntimeException( 'The multisite network is not readable.' );
}

$configured = new LudicrousDB( array(
	'charset' => 'latin1',
	'collate' => 'latin1_swedish_ci',
) );
$configured->add_database( $connection );

if ( '1' !== (string) $configured->get_var( 'SELECT 1' ) ) {
	throw new RuntimeException( 'The configured database connection is not usable.' );
}

if ( 'latin1' !== $configured->charset || 'latin1_swedish_ci' !== $configured->collate ) {
	throw new RuntimeException( 'Constructor-provided charset settings were overwritten.' );
}

if ( 'latin1' !== $configured->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'The configured connection did not retain its character set.' );
}

$configured_after_construction = new LudicrousDB();

$configured_after_construction->charset = 'latin1';
$configured_after_construction->collate = '';
$configured_after_construction->add_database( $connection );

if ( '1' !== (string) $configured_after_construction->get_var( 'SELECT 1' ) ) {
	throw new RuntimeException( 'The drop-in-style database connection is not usable.' );
}

if ( 'latin1' !== $configured_after_construction->charset || '' !== $configured_after_construction->collate ) {
	throw new RuntimeException( 'Drop-in-provided charset settings were overwritten.' );
}

if ( 'latin1' !== $configured_after_construction->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'An empty collation prevented the configured character set.' );
}
