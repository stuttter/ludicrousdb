<?php
/**
 * Exercise LudicrousDB connection probes against the real WordPress database.
 *
 * @package LudicrousDB
 */

defined( 'ABSPATH' ) || exit( 1 );

$plugin_root = WP_PLUGIN_DIR . '/ludicrousdb';

require_once $plugin_root . '/ludicrousdb/includes/functions.php';
require_once $plugin_root . '/ludicrousdb/includes/class-ludicrousdb.php';

if ( ! defined( 'DB_SERVER_GONE_ERROR' ) ) {
	ldb_default_constants();
}

// phpcs:ignore PHPCompatibility.Classes.NewAnonymousClasses.Found -- LudicrousDB requires PHP 7.4 or newer.
$database = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends LudicrousDB {
	/**
	 * Probe a database handle.
	 *
	 * @param mysqli|resource $dbh Database handle.
	 * @return bool Whether the handle responded.
	 */
	public function probe( $dbh ) {
		return $this->is_connection_alive( $dbh );
	}

	/**
	 * Return the current connection status.
	 *
	 * @param mysqli|resource $dbh Database handle.
	 * @return int One of the connection status constants.
	 */
	public function status( $dbh ) {
		return $this->get_connection_status( $dbh );
	}

	/**
	 * Return the connection status constants used by this probe.
	 *
	 * @return array{dead: int, available: int, busy: int}
	 */
	public function statuses() {
		return array(
			'dead'      => self::CONNECTION_DEAD,
			'available' => self::CONNECTION_AVAILABLE,
			'busy'      => self::CONNECTION_BUSY,
		);
	}
};

$statuses = $database->statuses();

$database->suppress_errors = true;
$database->recheck_timeout = 0;
$database->add_database(
	array(
		'host'     => DB_HOST,
		'user'     => DB_USER,
		'password' => DB_PASSWORD,
		'name'     => DB_NAME,
	)
);

$healthy = $database->db_connect( false, 'SELECT 1' );
if ( ! ( $healthy instanceof mysqli ) || $statuses['available'] !== $database->status( $healthy ) || ! $database->probe( $healthy ) ) {
	throw new RuntimeException( 'LudicrousDB did not establish a healthy database connection.' );
}

// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- Intentionally force the reconnect path against the live database.
mysqli_close( $healthy );
if ( $statuses['dead'] !== $database->status( $healthy ) || $database->probe( $healthy ) ) {
	throw new RuntimeException( 'LudicrousDB accepted a closed database connection.' );
}

$replacement = $database->db_connect( false, 'SELECT 1' );
if ( ! ( $replacement instanceof mysqli ) || $replacement === $healthy ) {
	throw new RuntimeException( 'LudicrousDB did not replace the closed database connection.' );
}
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- A direct query proves the replacement handle is usable.
if ( false === mysqli_query( $replacement, 'SELECT 1' ) ) {
	throw new RuntimeException( 'The replacement database connection is not usable.' );
}

// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- An unbuffered result characterizes a live but temporarily busy connection.
$unbuffered_result = mysqli_query( $replacement, 'SELECT 1 UNION ALL SELECT 2', MYSQLI_USE_RESULT );
if ( ! ( $unbuffered_result instanceof mysqli_result ) ) {
	throw new RuntimeException( 'LudicrousDB could not create an unbuffered result.' );
}
if ( $statuses['busy'] !== $database->status( $replacement ) || ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'LudicrousDB treated an unbuffered live connection as disconnected.' );
}
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Confirm the probe observed the expected busy-connection client error.
if ( 2014 !== mysqli_errno( $replacement ) ) {
	throw new RuntimeException( 'The unbuffered probe did not exercise MySQL commands-out-of-sync handling.' );
}
if ( $database->probe( $replacement ) ) {
	throw new RuntimeException( 'LudicrousDB repeatedly accepted an unbuffered busy connection.' );
}
$unbuffered_result->free();
if ( ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'The connection was not usable after freeing an unbuffered result.' );
}

// A cached busy handle must be replaced without closing its still-active result.
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Manufacture a live result while exercising cached-connection replacement.
$preserved_result = mysqli_query( $replacement, 'SELECT 1 UNION ALL SELECT 2', MYSQLI_USE_RESULT );
if ( ! ( $preserved_result instanceof mysqli_result ) ) {
	throw new RuntimeException( 'LudicrousDB could not create a result for replacement testing.' );
}
$preserved_handle_id = spl_object_id( $replacement );
unset( $replacement );
if ( ! $database->check_connection( false, $database->dbh, 'SELECT 1' ) ) {
	throw new RuntimeException( 'LudicrousDB could not replace a busy cached connection.' );
}
$replacement = $database->dbh;
if ( ! ( $replacement instanceof mysqli ) || spl_object_id( $replacement ) === $preserved_handle_id ) {
	throw new RuntimeException( 'LudicrousDB reused the busy cached connection.' );
}
$preserved_rows = 0;

while ( true ) {
	$preserved_row = $preserved_result->fetch_assoc();
	if ( null === $preserved_row ) {
		break;
	}
	if ( false === $preserved_row ) {
		throw new RuntimeException( 'The detached busy connection did not preserve its active result.' );
	}

	++$preserved_rows;
}
$preserved_result->free();
if ( 2 !== $preserved_rows ) {
	throw new RuntimeException( 'Replacing the cached connection interrupted its active result.' );
}
if ( ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'The replacement for a busy cached connection is not usable.' );
}

// Strict MySQLi reporting exercises the exception surface used outside WordPress defaults.
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- Temporarily exercise the supported strict-reporting exception path.
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$strict_result = null;
try {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Manufacture a strict-reporting commands-out-of-sync exception.
	$strict_result = mysqli_query( $replacement, 'SELECT 1 UNION ALL SELECT 2', MYSQLI_USE_RESULT );
	if ( ! ( $strict_result instanceof mysqli_result ) ) {
		throw new RuntimeException( 'LudicrousDB could not create a strict-reporting result.' );
	}
	if ( ! $database->probe( $replacement ) || $database->probe( $replacement ) ) {
		throw new RuntimeException( 'Strict MySQLi reporting did not preserve the bounded busy-probe grace.' );
	}
} finally {
	if ( $strict_result instanceof mysqli_result ) {
		$strict_result->free();
	}
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- Restore WordPress's normal MySQLi reporting mode.
	mysqli_report( MYSQLI_REPORT_OFF );
}
if ( ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'The connection did not recover after the strict-reporting result was freed.' );
}

// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_multi_query -- Pending results characterize another live but temporarily busy connection.
if ( ! mysqli_multi_query( $replacement, 'SELECT 1; SELECT 2' ) ) {
	throw new RuntimeException( 'LudicrousDB could not create pending multi-query results.' );
}
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_store_result -- Leave a later result pending while the handle is probed.
$first_result = mysqli_store_result( $replacement );
if ( $first_result instanceof mysqli_result ) {
	$first_result->free();
}
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_more_results -- Confirm that the connection has a pending result before probing it.
if ( ! mysqli_more_results( $replacement ) || $statuses['busy'] !== $database->status( $replacement ) || ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'LudicrousDB treated a connection with pending results as disconnected.' );
}
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Confirm the probe observed the expected busy-connection client error.
if ( 2014 !== mysqli_errno( $replacement ) ) {
	throw new RuntimeException( 'The pending-result probe did not exercise MySQL commands-out-of-sync handling.' );
}
if ( $database->probe( $replacement ) ) {
	throw new RuntimeException( 'LudicrousDB repeatedly accepted a connection with pending results.' );
}

// Drain the deliberately pending results, keeping an advance error distinct from completion.
// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_more_results -- The loop intentionally drains every pending result.
while ( mysqli_more_results( $replacement ) ) {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_next_result -- Advance through the deliberately pending results.
	if ( ! mysqli_next_result( $replacement ) ) {
		throw new RuntimeException( 'LudicrousDB could not advance through pending multi-query results.' );
	}

	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_store_result -- Free each deliberately pending result.
	$pending_result = mysqli_store_result( $replacement );
	if ( $pending_result instanceof mysqli_result ) {
		$pending_result->free();
	}
}
if ( ! $database->probe( $replacement ) ) {
	throw new RuntimeException( 'The connection was not usable after draining pending results.' );
}

$dbhname = false;
foreach ( $database->dbhs as $candidate => $dbh ) {
	if ( $replacement === $dbh ) {
		$dbhname = $candidate;
		break;
	}
}
if ( false === $dbhname ) {
	throw new RuntimeException( 'The replacement database connection was not cached.' );
}

$alias                        = $dbhname . '_alias';
$database->dbhs[ $alias ]     = $replacement;
$database->open_connections[] = $alias;
$database->disconnect( $dbhname );

if ( isset( $database->dbhs[ $dbhname ] ) || isset( $database->dbhs[ $alias ] ) ) {
	throw new RuntimeException( 'Disconnect left a cached alias for the closed handle.' );
}
if ( null !== $database->dbh ) {
	throw new RuntimeException( 'Disconnect left the closed handle active.' );
}
