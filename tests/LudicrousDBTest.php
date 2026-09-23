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
	 * Explicit constructor settings take precedence over fallback defaults.
	 */
	public function test_constructor_preserves_explicit_character_set() {
		$database = new LudicrousDB(
			array(
				'charset' => 'latin1',
				'collate' => 'latin1_swedish_ci',
			)
		);

		$this->assertSame( 'latin1', $database->charset );
		$this->assertSame( 'latin1_swedish_ci', $database->collate );
	}

	/**
	 * A constructor charset override must not retain another charset's collation.
	 */
	public function test_constructor_charset_without_collation_uses_server_default() {
		$database = new LudicrousDB( array( 'charset' => 'latin1' ) );

		$this->assertSame( 'latin1', $database->charset );
		$this->assertSame( '', $database->collate );
	}

	/**
	 * A custom charset without DB_COLLATE must not inherit the utf8mb4 collation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_charset_without_collation_constant() {
		if ( defined( 'DB_CHARSET' ) ) {
			$this->markTestSkipped( 'This isolated case requires DB_CHARSET to be absent during bootstrap.' );
		}

		define( 'DB_CHARSET', 'latin1' );

		$database = new LudicrousDB();

		$this->assertSame( 'latin1', $database->charset );
		$this->assertSame( '', $database->collate );
	}

	/**
	 * Cached links receive later charset changes without repeated SET NAMES.
	 */
	public function test_cached_connection_refreshes_changed_charset_only() {
		$database = new class() extends LudicrousDB {
			/**
			 * Number of charset updates requested.
			 *
			 * @var int
			 */
			public $set_charset_calls = 0;

			/**
			 * Whether the next charset update should fail.
			 *
			 * @var bool
			 */
			public $fail_charset = false;

			/**
			 * Number of failed links removed from the cache.
			 *
			 * @var int
			 */
			public $disconnect_calls = 0;

			/**
			 * Simulate one stale probe before a successful recovery probe.
			 *
			 * @var bool
			 */
			public $unavailable_once = false;

			/**
			 * Count recoveries that retained the same connection.
			 *
			 * @var int
			 */
			public $check_connection_calls = 0;

			/**
			 * Count requested charset updates without using the inert test handle.
			 *
			 * @param mysqli $dbh     Connection handle.
			 * @param string $charset Optional charset.
			 * @param string $collate Optional collation.
			 */
			public function set_charset( $dbh, $charset = null, $collate = null ) {
				unset( $dbh, $charset, $collate );
				++$this->set_charset_calls;
				if ( $this->fail_charset ) {
					return false;
				}
			}

			/**
			 * Keep the test independent of a live server.
			 *
			 * @param string $dbhname Connection name.
			 * @return bool
			 */
			public function should_mysql_ping( $dbhname = '' ) {
				unset( $dbhname );
				return false;
			}

			/**
			 * Treat the inert handle as available for routing assertions.
			 *
			 * @param mysqli $dbh Connection handle.
			 * @return int
			 */
			protected function get_connection_status( $dbh ) {
				unset( $dbh );
				if ( $this->unavailable_once ) {
					$this->unavailable_once = false;
					return self::CONNECTION_DEAD;
				}
				return self::CONNECTION_AVAILABLE;
			}

			/**
			 * Simulate a successful second probe on the same handle.
			 *
			 * @param bool   $allow_bail Whether bailing is allowed.
			 * @param mixed  $dbh_or_table Connection to check.
			 * @param string $query Query used for routing.
			 * @param mixed  $die_on_disconnect Historical alias.
			 * @return bool
			 */
			public function check_connection( $allow_bail = true, $dbh_or_table = false, $query = '', $die_on_disconnect = null ) {
				unset( $allow_bail, $dbh_or_table, $query, $die_on_disconnect );
				++$this->check_connection_calls;
				return true;
			}

			/**
			 * Remove a failed test handle without closing the inert MySQLi object.
			 *
			 * @param string $dbhname Connection name.
			 */
			public function disconnect( $dbhname ) {
				++$this->disconnect_calls;
				unset( $this->dbhs[ $dbhname ] );
			}

			/**
			 * Record the settings as though they were applied to the connection.
			 *
			 * @param mysqli $dbh Connection handle.
			 */
			public function mark_charset_applied( $dbh ) {
				$this->connection_charsets[ spl_object_hash( $dbh ) ] = array( $this->charset, $this->collate );
			}
		};

		$database->add_database(
			array(
				'host'     => DB_HOST,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'name'     => DB_NAME,
			)
		);

		// An inert handle is enough to exercise cached routing without a server.
		$dbh                                    = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init
		$database->dbhs['global__r']            = $dbh;
		$database->used_servers['global__r']    = array( 'name' => DB_NAME );
		$database->dbh2host['global__r']        = DB_HOST;
		$database->db_connections[0]['dbhname'] = 'global__r';

		$this->assertSame( $dbh, $database->db_connect( false, 'SELECT 1' ) );
		$this->assertSame( 1, $database->set_charset_calls );

		$database->mark_charset_applied( $dbh );
		$database->db_connect( false, 'SELECT 1' );
		$this->assertSame( 1, $database->set_charset_calls );

		$database->charset          = 'latin1';
		$database->collate          = 'latin1_swedish_ci';
		$database->unavailable_once = true;
		$this->assertSame( $dbh, $database->db_connect( false, 'SELECT 1' ) );
		$this->assertSame( 1, $database->check_connection_calls );
		$this->assertSame( 2, $database->set_charset_calls );

		$database->mark_charset_applied( $dbh );
		$database->charset = '';
		$database->collate = '';
		$database->db_connect( false, 'SELECT 1' );
		$this->assertSame( 3, $database->set_charset_calls );

		$database->fail_charset = true;
		$database->charset      = 'utf8';
		$this->assertFalse( $database->db_connect( false, 'SELECT 1' ) );
		$this->assertSame( 1, $database->disconnect_calls );
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
		$database = new LudicrousDB();

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
	 * Normal queries retain LudicrousDB's historical fatal-handling behavior.
	 */
	public function test_query_allows_connection_failure_to_bail() {
		$database = new LudicrousDB();

		$this->assertFalse( $database->query( 'SELECT * FROM wp_users' ) );
		$this->assertSame( array( true ), $database->db_connect_calls );
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
	 * Connection checks actively verify a handle instead of trusting stale state.
	 */
	public function test_check_connection_uses_an_active_probe() {
		$database                          = new LudicrousDBTestDouble();
		$statuses                          = $database->connection_statuses_for_test();
		$database->connection_probe_status = $statuses['available'];
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is required for a deterministic liveness test.
		$database->dbh = mysqli_init();

		$database->dbhs['global__r'] = $database->dbh;

		$this->assertTrue( $database->check_connection( false, $database->dbh ) );
		$this->assertSame( array( 'probe' ), $database->connection_events );
	}

	/**
	 * A failed probe removes the stale handle before attempting reconnection.
	 */
	public function test_check_connection_disconnects_before_reconnecting() {
		$database                          = new LudicrousDBTestDouble();
		$statuses                          = $database->connection_statuses_for_test();
		$database->connection_probe_status = $statuses['dead'];
		$database->reconnect_retries       = 1;
		$database->reconnect_sleep         = 0;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is required for a deterministic liveness test.
		$database->dbh = mysqli_init();

		$database->dbhs['global__r'] = $database->dbh;

		$this->assertFalse( $database->check_connection( false, $database->dbh, 'SELECT * FROM wp_posts' ) );
		$this->assertSame( array( 'probe', 'disconnect', 'reconnect' ), $database->connection_events );
		$this->assertArrayNotHasKey( 'global__r', $database->dbhs );
	}

	/**
	 * The real probe rejects an initialized handle that is not connected.
	 */
	public function test_connection_probe_rejects_an_unconnected_handle() {
		$database = new LudicrousDBTestDouble();
		$statuses = $database->connection_statuses_for_test();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle exercises the real failure path without a server dependency.
		$dbh = mysqli_init();

		$this->assertSame( $statuses['dead'], $database->get_connection_status_for_test( $dbh ) );
		$this->assertFalse( $database->is_connection_alive_for_test( $dbh ) );
	}

	/**
	 * Heartbeats probe new and idle handles without probing active traffic.
	 */
	public function test_heartbeat_probe_schedule_tracks_connection_activity() {
		$database                       = new LudicrousDBTestDouble();
		$database->check_dbh_heartbeats = true;
		$database->recheck_timeout      = 60;
		$dbhname                        = 'global__r';

		$this->assertTrue( $database->should_mysql_ping( $dbhname ) );

		$database->update_heartbeat_for_test( $dbhname );
		$this->assertFalse( $database->should_mysql_ping( $dbhname ) );

		$database->dbhname_heartbeats[ $dbhname ]['last_used'] = microtime( true ) - 61;
		$this->assertTrue( $database->should_mysql_ping( $dbhname ) );
	}

	/**
	 * A busy connection receives one grace probe before normal recovery resumes.
	 */
	public function test_busy_connection_probe_grace_is_bounded_and_resettable() {
		$database = new LudicrousDBTestDouble();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is sufficient for request-local probe state.
		$dbh = mysqli_init();

		$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
		$this->assertFalse( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
		$this->assertFalse( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
		$this->assertFalse( $database->handle_connection_probe_failure_for_test( $dbh, DB_SERVER_GONE_ERROR ) );

		$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
		$database->clear_busy_connection_probe_for_test( $dbh );
		$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
	}

	/**
	 * The boolean probe wrapper retains its bounded busy-connection behavior.
	 */
	public function test_connection_probe_wrapper_bounds_busy_connection_grace() {
		$database                          = new LudicrousDBTestDouble();
		$statuses                          = $database->connection_statuses_for_test();
		$database->connection_probe_status = $statuses['busy'];
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is sufficient because the status probe is deterministic.
		$dbh = mysqli_init();

		$this->assertTrue( $database->is_connection_alive_for_test( $dbh ) );
		$this->assertFalse( $database->is_connection_alive_for_test( $dbh ) );
		$database->clear_busy_connection_probe_for_test( $dbh );
		$this->assertTrue( $database->is_connection_alive_for_test( $dbh ) );
	}

	/**
	 * Every connection status clears an earlier busy-probe marker.
	 */
	public function test_check_connection_clears_an_existing_busy_probe_marker() {
		foreach ( array( 'available', 'busy', 'dead' ) as $status ) {
			$database                          = new LudicrousDBTestDouble();
			$statuses                          = $database->connection_statuses_for_test();
			$database->connection_probe_status = $statuses[ $status ];
			$database->reconnect_retries       = 0;
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is sufficient because the status probe is deterministic.
			$dbh = mysqli_init();

			$database->dbh               = $dbh;
			$database->dbhs['global__r'] = $dbh;
			$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
			$this->assertFalse( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );

			$database->check_connection( false, $dbh, 'SELECT 1' );

			$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
			$database->close_for_real_for_test( $dbh );
		}
	}

	/**
	 * Closing a handle clears its request-local busy-probe state.
	 */
	public function test_closing_connection_clears_busy_probe_grace() {
		$database = new LudicrousDBTestDouble();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle exercises close cleanup without a server dependency.
		$dbh = mysqli_init();

		$database->dbh = $dbh;
		$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
		$this->assertTrue( $database->close_for_real_for_test( $dbh ) );
		$this->assertTrue( $database->handle_connection_probe_failure_for_test( $dbh, 2014 ) );
	}

	/**
	 * A busy cached handle is detached without closing its active result.
	 */
	public function test_check_connection_replaces_busy_handle_without_closing_it() {
		$database                          = new LudicrousDBTestDouble();
		$statuses                          = $database->connection_statuses_for_test();
		$database->connection_probe_status = $statuses['busy'];
		$database->reconnect_retries       = 1;
		$database->reconnect_sleep         = 0;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is sufficient for deterministic cache-detachment behavior.
		$dbh = mysqli_init();

		$database->dbh                = $dbh;
		$database->dbhs['global__r']  = $dbh;
		$database->open_connections[] = 'global__r';
		$this->assertFalse( $database->check_connection( false, $dbh, 'SELECT 1' ) );
		$this->assertSame( array( 'probe', 'reconnect' ), $database->connection_events );
		$this->assertArrayNotHasKey( 'global__r', $database->dbhs );
		$this->assertSame( array(), array_values( $database->open_connections ) );
		$this->assertNull( $database->dbh );
		$this->assertSame( 0, $database->close_calls );

		$database->close_for_real_for_test( $dbh );
	}

	/**
	 * A known disconnect error forces one immediate heartbeat probe.
	 */
	public function test_heartbeat_probe_consumes_a_known_disconnect_error() {
		$database                                 = new LudicrousDBTestDouble();
		$database->check_dbh_heartbeats           = true;
		$database->recheck_timeout                = 60;
		$dbhname                                  = 'global__r';
		$database->dbhname_heartbeats[ $dbhname ] = array(
			'last_used'  => microtime( true ),
			'last_errno' => DB_SERVER_GONE_ERROR,
		);

		$this->assertTrue( $database->should_mysql_ping( $dbhname ) );
		$this->assertArrayNotHasKey( 'last_errno', $database->dbhname_heartbeats[ $dbhname ] );
		$this->assertFalse( $database->should_mysql_ping( $dbhname ) );
	}

	/**
	 * A closed mysqli object can be removed without closing it a second time.
	 */
	public function test_check_connection_safely_removes_a_closed_handle() {
		$database                    = new LudicrousDB();
		$database->reconnect_retries = 0;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- A closed handle exercises the PHP 8 error path in mysqli_close().
		$dbh = mysqli_init();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- Intentionally manufacture a stale handle for the cleanup test.
		mysqli_close( $dbh );

		$database->dbh                = $dbh;
		$database->dbhs['global__r']  = $dbh;
		$database->open_connections[] = 'global__r';

		$this->assertFalse( $database->check_connection( false, $dbh ) );
		$this->assertNull( $database->dbh );
		$this->assertArrayNotHasKey( 'global__r', $database->dbhs );
		$this->assertSame( array(), array_values( $database->open_connections ) );
	}

	/**
	 * Closing a stale handle restores the previously installed error handler.
	 */
	public function test_closing_a_stale_handle_restores_the_previous_error_handler() {
		$database        = new LudicrousDB();
		$handled_warning = false;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- A closed handle exercises the guarded mysqli_close() call.
		$dbh = mysqli_init();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- Intentionally manufacture a stale handle for the cleanup test.
		mysqli_close( $dbh );
		$database->dbh = $dbh;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The sentinel verifies that close() restores its caller's handler.
		set_error_handler(
			static function () use ( &$handled_warning ) {
				$handled_warning = true;
				return true;
			}
		);

		try {
			$this->assertFalse( $database->close( $dbh ) );
			$this->assertNull( $database->dbh );
			$this->assertFalse( $handled_warning );
			$handled_warning = false;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Exercise the sentinel handler after close() returns.
			trigger_error( 'LudicrousDB error-handler sentinel.', E_USER_WARNING );
		} finally {
			restore_error_handler();
		}

		$this->assertTrue( $handled_warning );
	}

	/**
	 * Disconnecting one routing name removes every alias of the same handle.
	 */
	public function test_disconnect_removes_aliased_handles_and_is_idempotent() {
		$database = new LudicrousDBTestDouble();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- An inert handle is sufficient because the test double records close attempts.
		$dbh = mysqli_init();

		$database->dbh                = $dbh;
		$database->dbhs['global__r']  = $dbh;
		$database->dbhs['global__w']  = $dbh;
		$database->open_connections[] = 'global__r';
		$database->open_connections[] = 'global__w';

		$database->disconnect( 'global__r' );
		$database->disconnect( 'global__r' );

		$this->assertNull( $database->dbh );
		$this->assertSame( array(), $database->dbhs );
		$this->assertSame( array(), array_values( $database->open_connections ) );
		$this->assertSame( 1, $database->close_calls );
	}

	/**
	 * Disconnecting a failed scalar entry does not evict unrelated failures.
	 */
	public function test_disconnect_does_not_alias_scalar_failure_entries() {
		$database                     = new LudicrousDBTestDouble();
		$database->dbhs['first__r']   = false;
		$database->dbhs['second__r']  = false;
		$database->open_connections[] = 'first__r';
		$database->open_connections[] = 'already-removed__r';

		$database->disconnect( 'first__r' );
		$database->disconnect( 'already-removed__r' );

		$this->assertArrayNotHasKey( 'first__r', $database->dbhs );
		$this->assertArrayHasKey( 'second__r', $database->dbhs );
		$this->assertSame( array(), array_values( $database->open_connections ) );
		$this->assertSame( 0, $database->close_calls );
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
