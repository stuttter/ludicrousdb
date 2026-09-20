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
};

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
if ( ! ( $healthy instanceof mysqli ) || ! $database->probe( $healthy ) ) {
	throw new RuntimeException( 'LudicrousDB did not establish a healthy database connection.' );
}

// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- Intentionally force the reconnect path against the live database.
mysqli_close( $healthy );
if ( $database->probe( $healthy ) ) {
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
