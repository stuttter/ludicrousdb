<?php

use PHPUnit\Framework\TestCase;

/**
 * Characterize configuration and routing behavior that must remain compatible.
 */
final class LudicrousDBTest extends TestCase {
	/**
	 * The constructor retains LudicrousDB's safe defaults.
	 */
	public function test_constructor_uses_safe_character_set_defaults() {
		$database = new LudicrousDB();

		$this->assertSame( 'utf8mb4', $database->charset );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $database->collate );
	}

	/**
	 * The wpdb-style constructor assigns all four connection properties.
	 */
	public function test_constructor_accepts_wpdb_connection_arguments() {
		$database = new LudicrousDB( 'database-user', 'password', 'database', 'database.example.test' );

		$this->assertSame( 'database-user', $database->dbuser );
		$this->assertSame( 'password', $database->dbpassword );
		$this->assertSame( 'database', $database->dbname );
		$this->assertSame( 'database.example.test', $database->dbhost );
	}

	/**
	 * Historical public property names remain readable and writable.
	 */
	public function test_renamed_properties_remain_compatible() {
		$database = new LudicrousDB();

		$database->allow_bail       = true;
		$database->ignore_slave_lag = true;
		$database->srtm             = true;

		$this->assertTrue( $database->allow_bail );
		$this->assertTrue( $database->ignore_slave_lag );
		$this->assertTrue( $database->srtm );
		$this->assertTrue( $database->die_on_disconnect );
		$this->assertTrue( $database->send_reads_to_primaries );
	}

	/**
	 * Public method names match wpdb while retaining LudicrousDB aliases.
	 *
	 * @param string $method     Method name.
	 * @param array  $parameters Expected parameter names.
	 *
	 * @dataProvider method_parameter_provider
	 */
	public function test_public_method_parameter_names( $method, $parameters ) {
		$reflection = new ReflectionMethod( LudicrousDB::class, $method );
		$actual     = array_map(
			function ( ReflectionParameter $parameter ) {
				return $parameter->getName();
			},
			$reflection->getParameters()
		);

		$this->assertSame( $parameters, $actual );
	}

	/**
	 * Public method parameter contracts.
	 *
	 * @return array
	 */
	public function method_parameter_provider() {
		return array(
			'db_connect'       => array( 'db_connect', array( 'allow_bail', 'query' ) ),
			'select'           => array( 'select', array( 'db', 'dbh', 'dbh_or_table' ) ),
			'_real_escape'     => array( '_real_escape', array( 'data', 'to_escape' ) ),
			'check_connection' => array( 'check_connection', array( 'allow_bail', 'dbh_or_table', 'query', 'die_on_disconnect' ) ),
		);
	}

	/**
	 * Both current and historical named arguments escape identically.
	 */
	public function test_real_escape_named_argument_compatibility() {
		$database = new LudicrousDB();

		$this->assertSame(
			"O\\'Reilly",
			call_user_func_array( array( $database, '_real_escape' ), array( 'data' => "O'Reilly" ) )
		);
		$this->assertSame(
			"O\\'Reilly",
			call_user_func_array( array( $database, '_real_escape' ), array( 'to_escape' => "O'Reilly" ) )
		);
	}

	/**
	 * Current and historical select arguments fail safely without a handle.
	 */
	public function test_select_argument_compatibility_without_a_connection() {
		$database = new LudicrousDB();

		$this->assertFalse( $database->select( DB_NAME ) );
		$this->assertFalse(
			call_user_func_array(
				array( $database, 'select' ),
				array(
					'db'           => DB_NAME,
					'dbh'          => null,
					'dbh_or_table' => false,
				)
			)
		);
	}

	/**
	 * Current db_connect arguments reach wpdb without changing the query.
	 */
	public function test_db_connect_supports_current_calling_convention() {
		$database = new LudicrousDB();

		$this->assertFalse( $database->db_connect( false, 'SELECT * FROM wp_posts' ) );
		$this->assertSame( array( false ), $database->db_connect_calls );
		$this->assertSame( 'wp_posts', $database->last_table );
	}

	/**
	 * A query in the first argument retains LudicrousDB's historical behavior.
	 */
	public function test_db_connect_supports_historical_query_argument() {
		$database                    = new LudicrousDB();
		$database->die_on_disconnect = true;

		$this->assertFalse( $database->db_connect( 'SELECT * FROM wp_users' ) );
		$this->assertSame( array( true ), $database->db_connect_calls );
		$this->assertSame( 'wp_users', $database->last_table );
	}

	/**
	 * A connection requested before a query exists uses a harmless probe query.
	 */
	public function test_db_connect_without_query_uses_no_table_fallback() {
		$database = new LudicrousDB();

		$this->assertFalse( $database->db_connect( false ) );
		$this->assertSame( 'no-table', $database->last_table );
	}

	/**
	 * Callers can suppress a fatal error when routing cannot select a dataset.
	 */
	public function test_db_connect_honors_allow_bail() {
		$database = new LudicrousDB();
		$database->add_callback(
			function () {
				return '';
			}
		);

		$this->assertFalse( $database->db_connect( false, 'SELECT * FROM wp_posts' ) );
		$this->assertSame( array(), $database->bail_calls );

		$this->assertFalse( $database->db_connect( true, 'SELECT * FROM wp_posts' ) );
		$this->assertCount( 1, $database->bail_calls );
		$this->assertStringContainsString( 'Unable to determine which dataset', $database->bail_calls[0][0] );
	}

	/**
	 * Current and historical reconnect flags both suppress fatal handling.
	 */
	public function test_check_connection_argument_compatibility() {
		$database                    = new LudicrousDB();
		$database->reconnect_retries = 0;

		$this->assertFalse( $database->check_connection( false ) );
		$this->assertFalse(
			call_user_func_array(
				array( $database, 'check_connection' ),
				array(
					'allow_bail'        => true,
					'dbh_or_table'      => false,
					'query'             => 'SELECT 1',
					'die_on_disconnect' => false,
				)
			)
		);
	}

	/**
	 * Fractional reconnect delays are measured in seconds, not truncated.
	 */
	public function test_check_connection_honors_fractional_reconnect_sleep() {
		$database                    = new LudicrousDB();
		$database->reconnect_retries = 2;
		$database->reconnect_sleep   = 0.02;
		$start                       = microtime( true );

		$this->assertFalse( $database->check_connection( false ) );

		$elapsed = microtime( true ) - $start;
		$this->assertGreaterThanOrEqual( 0.03, $elapsed );
		$this->assertLessThan( 1.0, $elapsed );
		$this->assertSame( array( false, false ), $database->db_connect_calls );
	}

	/**
	 * Identifier placeholders track the installed wpdb implementation.
	 */
	public function test_identifier_placeholder_capability_comes_from_wpdb() {
		$database = new LudicrousDB();

		$this->assertTrue( $database->has_cap( 'identifier_placeholders' ) );
	}

	/**
	 * Connection state used by routing callbacks remains publicly readable.
	 *
	 * @param string $property Property name.
	 *
	 * @dataProvider callback_state_property_provider
	 */
	public function test_callback_state_properties_are_public( $property ) {
		$reflection = new ReflectionProperty( LudicrousDB::class, $property );

		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Callback-facing state properties.
	 *
	 * @return array
	 */
	public function callback_state_property_provider() {
		return array(
			'unique_servers'  => array( 'unique_servers' ),
			'callback_result' => array( 'callback_result' ),
			'table'           => array( 'table' ),
			'lag_threshold'   => array( 'lag_threshold' ),
			'dbhname'         => array( 'dbhname' ),
			'dataset'         => array( 'dataset' ),
			'current_host'    => array( 'current_host' ),
			'last_connection' => array( 'last_connection' ),
			'lag_cache_key'   => array( 'lag_cache_key' ),
		);
	}

	/**
	 * Database hosts are normalized without losing ports, sockets, or IPv6.
	 *
	 * @param string $host     Configured host.
	 * @param int    $port     Separately configured port.
	 * @param array  $expected Expected normalized values.
	 *
	 * @dataProvider database_host_provider
	 */
	public function test_database_host_normalization( $host, $port, $expected ) {
		$database = new LudicrousDBTestDouble();

		$this->assertSame( $expected, $database->parse_database_host_for_test( $host, $port ) );
	}

	/**
	 * Representative TCP, socket, and IPv6 host formats.
	 *
	 * @return array
	 */
	public function database_host_provider() {
		return array(
			'separate port'       => array(
				'database.example.test',
				3307,
				array( 'database.example.test', 3307, '', false ),
			),
			'embedded port'       => array(
				'database.example.test:3308',
				3307,
				array( 'database.example.test', 3308, '', false ),
			),
			'socket'              => array(
				'localhost:/var/run/mysql/mysql.sock',
				3307,
				array( 'localhost', 3307, '/var/run/mysql/mysql.sock', false ),
			),
			'port and socket'     => array(
				'localhost:3308:/var/run/mysql/mysql.sock',
				3307,
				array( 'localhost', 3308, '/var/run/mysql/mysql.sock', false ),
			),
			'socket without host' => array(
				':/var/run/mysql/mysql.sock',
				3307,
				array( '', 3307, '/var/run/mysql/mysql.sock', false ),
			),
			'IPv6'                => array(
				'[::1]:3308',
				3307,
				array( '::1', 3308, '', true ),
			),
			'IPv6 with socket'    => array(
				'[::1]:3308:/var/run/mysql/mysql.sock',
				3307,
				array( '::1', 3308, '/var/run/mysql/mysql.sock', true ),
			),
		);
	}

	/**
	 * Socket paths are part of health-check cache identity.
	 */
	public function test_socket_cache_keys_do_not_collide() {
		$database  = new LudicrousDBTestDouble();
		$cache_key = $database->tcp_cache_key_for_test( 'localhost', 3306, '/run/mysql-a.sock' );

		$this->assertNotSame(
			$cache_key,
			$database->tcp_cache_key_for_test( 'localhost', 3306, '/run/mysql-b.sock' )
		);
		$this->assertSame(
			array( 'localhost', 3306, '/run/mysql-a.sock', false ),
			$database->parse_database_host_for_test( $cache_key, 0 )
		);
	}

	/**
	 * Disabling health checks also bypasses socket probes.
	 */
	public function test_disabled_tcp_responsiveness_skips_socket_probe() {
		$database                           = new LudicrousDB();
		$database->check_tcp_responsiveness = false;

		$this->assertTrue(
			$database->check_tcp_responsiveness( 'localhost:/does/not/exist.sock', 3306, 0.01 )
		);
	}

	/**
	 * Unix socket endpoints are probed with their native socket path.
	 */
	public function test_tcp_responsiveness_supports_unix_sockets() {
		$socket_path = '/tmp/ldb-' . uniqid() . '.sock';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported socket types must skip cleanly.
		$server = @stream_socket_server( 'unix://' . $socket_path, $errno, $errstr );

		if ( false === $server ) {
			$this->markTestSkipped( "Unable to create a Unix socket: {$errstr} ({$errno})" );
		}

		try {
			$database = new LudicrousDB();
			$this->assertTrue( $database->check_tcp_responsiveness( 'localhost', 3306, 0.1, $socket_path ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is a socket resource.
			fclose( $server );
			if ( file_exists( $socket_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the test's temporary socket only.
				unlink( $socket_path );
			}
		}
	}

	/**
	 * IPv6 endpoints retain their address when opened as TCP sockets.
	 */
	public function test_tcp_responsiveness_supports_ipv6() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- IPv6 can be unavailable in supported environments.
		$server = @stream_socket_server( 'tcp://[::1]:0', $errno, $errstr );

		if ( false === $server ) {
			$this->markTestSkipped( "IPv6 loopback is unavailable: {$errstr} ({$errno})" );
		}

		try {
			$address  = stream_socket_get_name( $server, false );
			$port     = (int) substr( $address, strrpos( $address, ':' ) + 1 );
			$database = new LudicrousDB();

			$this->assertTrue( $database->check_tcp_responsiveness( '[::1]', $port, 0.1 ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is a socket resource.
			fclose( $server );
		}
	}

	/**
	 * Server definitions retain their configured priority groups.
	 */
	public function test_database_configuration_preserves_read_and_write_priorities() {
		$database = new LudicrousDB();
		$database->add_database(
			array(
				'dataset'  => 'network',
				'host'     => 'database.example.test',
				'name'     => 'wordpress',
				'user'     => 'wordpress',
				'password' => 'not-a-real-password',
				'read'     => 2,
				'write'    => 1,
			)
		);

		$this->assertSame(
			'database.example.test',
			$database->ludicrous_servers['network']['read'][2][0]['host']
		);
		$this->assertSame(
			'database.example.test',
			$database->ludicrous_servers['network']['write'][1][0]['host']
		);
	}

	/**
	 * Read-only and mutating statements route consistently.
	 *
	 * @param string $query    SQL statement.
	 * @param bool   $is_write Expected classification.
	 *
	 * @dataProvider query_routing_provider
	 */
	public function test_query_routing_classification( $query, $is_write ) {
		$database = new LudicrousDB();

		$this->assertSame( $is_write, $database->is_write_query( $query ) );
	}

	/**
	 * Provide representative read and write statements.
	 *
	 * @return array
	 */
	public function query_routing_provider() {
		return array(
			'select'                => array( 'SELECT * FROM wp_posts', false ),
			'select for update'     => array( 'SELECT * FROM wp_posts FOR UPDATE', true ),
			'common table select'   => array( '( SELECT * FROM wp_posts )', false ),
			'insert'                => array( 'INSERT INTO wp_posts VALUES ( 1 )', true ),
			'session configuration' => array( 'SET SESSION sql_mode = ""', true ),
		);
	}
}
