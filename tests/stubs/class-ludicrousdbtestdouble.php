<?php

/**
 * Expose protected host helpers for isolated compatibility tests.
 */
class LudicrousDBTestDouble extends LudicrousDB {
	/**
	 * Result returned by the connection probe, or null to use the real probe.
	 *
	 * @var bool|null
	 */
	public $connection_probe_result = null;

	/**
	 * Ordered connection events observed by the test double.
	 *
	 * @var array
	 */
	public $connection_events = array();

	/**
	 * Number of close attempts made by connection-cleanup tests.
	 *
	 * @var int
	 */
	public $close_calls = 0;

	/**
	 * Expose host normalization.
	 *
	 * @param string $host Database host.
	 * @param int    $port Database port.
	 * @return array{0: string, 1: int, 2: string, 3: bool}
	 */
	public function parse_database_host_for_test( $host, $port ) {
		return $this->parse_database_host( $host, $port );
	}

	/**
	 * Expose TCP cache identity.
	 *
	 * @param string $host   Database host.
	 * @param int    $port   Database port.
	 * @param string $socket Socket path.
	 * @return string
	 */
	public function tcp_cache_key_for_test( $host, $port, $socket ) {
		return $this->tcp_get_cache_key( $host, $port, $socket );
	}

	/**
	 * Return a deterministic connection probe result when configured.
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return bool
	 */
	protected function is_connection_alive( $dbh ) {
		if ( null !== $this->connection_probe_result ) {
			$this->connection_events[] = 'probe';

			return $this->connection_probe_result;
		}

		return parent::is_connection_alive( $dbh );
	}

	/**
	 * Exercise the real connection probe from tests.
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return bool Whether the connection responded.
	 */
	public function is_connection_alive_for_test( $dbh ) {
		return parent::is_connection_alive( $dbh );
	}

	/**
	 * Record connection activity through the production heartbeat path.
	 *
	 * @param string|object $dbhname_or_dbh Database handle name or object.
	 */
	public function update_heartbeat_for_test( $dbhname_or_dbh ) {
		$this->update_heartbeat( $dbhname_or_dbh );
	}

	/**
	 * Exercise busy-probe recovery state without a live database server.
	 *
	 * @param mysqli|resource $dbh   Database connection.
	 * @param int             $errno MySQL client error number.
	 * @return bool Whether the handle receives its grace probe.
	 */
	public function handle_connection_probe_failure_for_test( $dbh, $errno ) {
		return $this->handle_connection_probe_failure( $dbh, $errno );
	}

	/**
	 * Clear busy-probe recovery state without a live database server.
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	public function clear_busy_connection_probe_for_test( $dbh ) {
		$this->clear_busy_connection_probe( $dbh );
	}

	/**
	 * Exercise the production close path from tests.
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return bool Whether the handle was closed.
	 */
	public function close_for_real_for_test( $dbh ) {
		return parent::close( $dbh );
	}

	/**
	 * Record stale-handle removal without closing the test handle.
	 *
	 * @param string $dbhname Database handle name.
	 */
	public function disconnect( $dbhname ) {
		if ( null !== $this->connection_probe_result ) {
			$this->connection_events[] = 'disconnect';
			unset( $this->dbhs[ $dbhname ] );

			return;
		}

		parent::disconnect( $dbhname );
	}

	/**
	 * Record close attempts without calling mysqli_close() on inert test handles.
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Database handle or table name.
	 * @return bool True when the close attempt was recorded.
	 */
	public function close( $dbh_or_table = false ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- The override intentionally records the call without closing the supplied handle.
		++$this->close_calls;

		return true;
	}

	/**
	 * Record reconnect attempts made after a failed probe.
	 *
	 * @param bool   $allow_bail Whether fatal handling is allowed.
	 * @param string $query      Query that requested the connection.
	 * @return bool|mysqli|resource
	 */
	public function db_connect( $allow_bail = true, $query = '' ) {
		if ( null !== $this->connection_probe_result ) {
			$this->connection_events[] = 'reconnect';

			return false;
		}

		return parent::db_connect( $allow_bail, $query );
	}
}
