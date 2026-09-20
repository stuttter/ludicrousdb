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
	 * Identifier placeholders track the installed wpdb implementation.
	 */
	public function test_identifier_placeholder_capability_comes_from_wpdb() {
		$database = new LudicrousDB();

		$this->assertTrue( $database->has_cap( 'identifier_placeholders' ) );
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
