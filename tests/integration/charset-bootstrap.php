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

if ( ! defined( 'DB_CHARSET' ) || '' === DB_CHARSET || ! defined( 'DB_COLLATE' ) || '' !== DB_COLLATE ) {
	throw new RuntimeException( 'This regression requires the default empty DB_COLLATE.' );
}

if ( DB_CHARSET !== $wpdb->charset || '' !== $wpdb->collate ) {
	throw new RuntimeException( 'The default WordPress constants were not retained.' );
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

if ( DB_CHARSET !== $fresh->charset || '' !== $fresh->collate ) {
	throw new RuntimeException( 'The empty collation or configured charset was changed.' );
}

if ( $fresh->charset !== $fresh->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'The connection is using a different character set.' );
}

$connection_charset = $fresh->get_var( 'SELECT @@character_set_connection' );
$fresh->set_charset( $fresh->dbh, '', '' );
if ( $connection_charset !== $fresh->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'An empty charset changed the connection character set.' );
}

// Reinitializing must retain the configured defaults.
$fresh->disconnect( 'global__r' );
$fresh->dbh = null;
$fresh->init_charset();
$fresh->get_var( 'SELECT 1' );

if ( DB_CHARSET !== $fresh->charset || '' !== $fresh->collate ) {
	throw new RuntimeException( 'Reinitialization changed the configured charset or collation.' );
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

require_once __DIR__ . '/class-ldb-charset-probe.php';
$configured_after_construction = new LDB_Charset_Probe();

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

// Explicit settings applied after the first query must reach the existing link.
$configured_after_construction->charset = 'utf8mb4';
$configured_after_construction->collate = 'utf8mb4_unicode_ci';

if ( 'utf8mb4' !== $configured_after_construction->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'A later charset override did not reach the existing connection.' );
}

if ( 'utf8mb4_unicode_ci' !== $configured_after_construction->get_var( 'SELECT @@collation_connection' ) ) {
	throw new RuntimeException( 'A later collation override did not reach the existing connection.' );
}

$set_charset_calls = $configured_after_construction->set_charset_calls;
$configured_after_construction->get_var( 'SELECT 1' );
if ( $set_charset_calls !== $configured_after_construction->set_charset_calls ) {
	throw new RuntimeException( 'An unchanged connection repeated SET NAMES.' );
}

// A table can have its own collation without changing the connection setting.
$dbh = $configured_after_construction->dbh;
if ( ! mysqli_query( $dbh, 'CREATE TEMPORARY TABLE ldb_charset_probe (value varchar(10)) CHARACTER SET latin1 COLLATE latin1_swedish_ci' ) ) {
	throw new RuntimeException( 'Could not create the table-collation probe.' );
}

$result = mysqli_query( $dbh, 'SHOW FULL COLUMNS FROM ldb_charset_probe' );
$column = $result ? mysqli_fetch_assoc( $result ) : false;
if ( $result ) {
	mysqli_free_result( $result );
}

if ( ! $column || 'latin1_swedish_ci' !== $column['Collation'] ) {
	throw new RuntimeException( 'The table did not retain its declared collation.' );
}

$result  = mysqli_query( $dbh, 'SELECT @@character_set_connection, @@collation_connection' );
$session = $result ? mysqli_fetch_row( $result ) : false;
if ( $result ) {
	mysqli_free_result( $result );
}

if ( ! $session || array( 'utf8mb4', 'utf8mb4_unicode_ci' ) !== $session ) {
	throw new RuntimeException( 'The table collation changed the connection charset or collation.' );
}

mysqli_query( $dbh, 'DROP TEMPORARY TABLE ldb_charset_probe' );

// Explicit set_charset() calls must not be undone by the cached-link check.
$configured_after_construction->set_charset( $dbh, 'latin1', 'latin1_swedish_ci' );
if ( 'latin1' !== $configured_after_construction->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'A cached query undid an explicit set_charset() call.' );
}

// Set both host variables to exercise separate read and write servers.
$primary_host = getenv( 'LDB_TEST_PRIMARY_HOST' );
$replica_host = getenv( 'LDB_TEST_REPLICA_HOST' );

if ( (bool) $primary_host !== (bool) $replica_host ) {
	throw new RuntimeException( 'Set both database hosts to run the multi-server regression.' );
}

if ( $primary_host && $primary_host === $replica_host ) {
	throw new RuntimeException( 'Use distinct database hosts for the multi-server regression.' );
}

if ( $primary_host && $replica_host ) {
	$routed = new LudicrousDB();
	$routed->add_database( array_merge( $connection, array(
		'host'  => $replica_host,
		'read'  => 1,
		'write' => 0,
	) ) );
	$routed->add_database( array_merge( $connection, array(
		'host'  => $primary_host,
		'read'  => 0,
		'write' => 1,
	) ) );

	$routed->get_var( 'SELECT 1' );
	$routed->query( 'SET @ldb_charset_probe = 1' );

	if ( ! isset( $routed->dbhs['global__r'], $routed->dbhs['global__w'] ) ) {
		throw new RuntimeException( 'Separate read and write connections were not opened.' );
	}

	// An override after both links are open must reach each cached connection.
	$routed->charset = 'latin1';
	$routed->collate = 'latin1_swedish_ci';

	$routed->send_reads_to_primaries = array();
	$routed->get_var( 'SELECT 1' );
	$routed->query( 'SET @ldb_charset_probe = 2' );

	foreach ( array( 'global__r', 'global__w' ) as $name ) {
		$result = mysqli_query( $routed->dbhs[ $name ], 'SELECT @@character_set_connection, @@collation_connection' );
		$row    = mysqli_fetch_row( $result );
		mysqli_free_result( $result );

		if ( array( 'latin1', 'latin1_swedish_ci' ) !== $row ) {
			throw new RuntimeException( 'A later override did not reach a routed connection.' );
		}
	}

	$routed->charset = DB_CHARSET;
	$routed->collate = '';

	$routed->send_reads_to_primaries = array();
	$routed->get_var( 'SELECT 1' );
	$routed->query( 'SET @ldb_charset_probe = 3' );

	if ( $routed->dbhs['global__r'] === $routed->dbhs['global__w'] ) {
		throw new RuntimeException( 'Read and write queries reused one database connection.' );
	}

	foreach ( array( 'global__r', 'global__w' ) as $name ) {
		$dbh    = $routed->dbhs[ $name ];
		$result = mysqli_query( $dbh, 'SELECT @@character_set_connection, @@collation_connection' );
		$row    = mysqli_fetch_row( $result );
		mysqli_free_result( $result );

		if ( DB_CHARSET !== $row[0] ) {
			throw new RuntimeException( 'A routed connection did not retain the configured charset.' );
		}

		$charset = mysqli_real_escape_string( $dbh, DB_CHARSET );
		$result  = mysqli_query( $dbh, "SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = '{$charset}'" );
		$default = mysqli_fetch_row( $result );
		mysqli_free_result( $result );

		if ( ! $default || $default[0] !== $row[1] ) {
			throw new RuntimeException( 'An empty DB_COLLATE did not use the server default.' );
		}
	}
}
