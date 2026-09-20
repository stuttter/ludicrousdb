<?php

/**
 * Expose protected host helpers for isolated compatibility tests.
 */
class LudicrousDBTestDouble extends LudicrousDB {
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
}
