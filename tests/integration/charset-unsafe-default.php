<?php
/**
 * Reject an empty charset when the server default is unsafe for addslashes().
 *
 * Run with WP-CLI's eval-file against a server whose default charset is GBK.
 * Set LDB_TEST_UNSAFE_HOST to that server's host name.
 *
 * @package LudicrousDB
 */

defined( 'ABSPATH' ) || exit( 1 );

/**
 * Use an exception to assert the charset rejection without ending WP-CLI.
 *
 * @return string
 */
function ldb_unsafe_charset_die_handler() {
	return 'ldb_unsafe_charset_throw';
}

/**
 * Convert the expected wp_die() call to an exception.
 *
 * @param string $message Failure message.
 * @throws RuntimeException When the charset is rejected.
 */
function ldb_unsafe_charset_throw( $message ) {
	if ( false === strpos( (string) $message, 'gbk charset isn\'t supported' ) ) {
		throw new RuntimeException( 'An unexpected database failure occurred.' );
	}
	throw new RuntimeException( 'Unsafe charset was rejected.' );
}

$host = getenv( 'LDB_TEST_UNSAFE_HOST' );
if ( ! $host ) {
	throw new RuntimeException( 'Set LDB_TEST_UNSAFE_HOST to the GBK-default server.' );
}

add_filter( 'wp_die_handler', 'ldb_unsafe_charset_die_handler', PHP_INT_MAX );

$db = new LudicrousDB( array(
	'charset' => '',
	'collate' => '',
) );
$db->add_database( array(
	'host'     => $host,
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
) );

try {
	$db->get_var( 'SELECT 1' );
} catch ( RuntimeException $error ) {
	if ( 'Unsafe charset was rejected.' === $error->getMessage() ) {
		return;
	}
	throw $error;
}

throw new RuntimeException( 'An unsafe server-default charset was accepted.' );
