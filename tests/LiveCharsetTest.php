<?php
/**
 * Live MySQLi charset regressions.
 *
 * @package LudicrousDB
 */

use PHPUnit\Framework\TestCase;

/**
 * Characterize charset behavior against a real MySQLi connection.
 */
final class LiveCharsetTest extends TestCase {
	/**
	 * An empty setting may retain a safe session, but must reject unsafe SQL charsets.
	 *
	 * @group live
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @throws RuntimeException When the live session is unsafe.
	 */
	public function test_empty_charset_validates_client_and_server_session() {
		$host = getenv( 'LDB_TEST_DB_HOST' );
		if ( false === $host || '' === $host ) {
			$this->markTestSkipped( 'Set LDB_TEST_DB_HOST to run the live charset test.' );
		}

		if ( ! function_exists( 'wp_die' ) ) {
			/**
			 * Turn a security rejection into a testable exception.
			 *
			 * @param string $message Error message.
			 * @throws RuntimeException Always.
			 */
			function wp_die( $message ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Keep the raw security reason for the test assertion.
				throw new RuntimeException( $message );
			}
		}

		$parts = explode( ':', $host );
		$port  = isset( $parts[1] ) ? (int) $parts[1] : 3306;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect -- The live test deliberately exercises a real MySQLi session.
		$dbh = mysqli_connect( $parts[0], getenv( 'LDB_TEST_DB_USER' ), getenv( 'LDB_TEST_DB_PASSWORD' ), getenv( 'LDB_TEST_DB_NAME' ), $port );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- This integration test requires a real MySQLi handle.
		$this->assertInstanceOf( mysqli::class, $dbh );

		// phpcs:ignore PHPCompatibility.Classes.NewAnonymousClasses.Found -- LudicrousDB requires PHP 7.4 or newer.
		$database = new class() extends LudicrousDB {
			/**
			 * Quote the two static SET NAMES arguments without a WordPress bootstrap.
			 *
			 * @param string $query SQL with placeholders.
			 * @param mixed  ...$arguments Placeholder values.
			 * @return string
			 */
			public function prepare( $query, ...$arguments ) {
				foreach ( $arguments as $argument ) {
					$query = preg_replace( '/%s/', "'" . addslashes( $argument ) . "'", $query, 1 );
				}
				return $query;
			}

			/**
			 * Keep a healthy cached connection from entering the recovery path.
			 *
			 * @param string $dbhname Connection name.
			 * @return bool
			 */
			public function should_mysql_ping( $dbhname = '' ) {
				unset( $dbhname );
				return false;
			}

			/**
			 * Supply wpdb's timer hook for the isolated bootstrap.
			 */
			public function timer_start() {}

			/**
			 * Supply wpdb's elapsed-time hook for the isolated bootstrap.
			 *
			 * @return float
			 */
			public function timer_stop() {
				return 0.0;
			}

			/**
			 * The live test's database is already known to be reachable.
			 *
			 * @param string $host Host name.
			 * @param int    $port Port number.
			 * @param float  $float_timeout Timeout in seconds.
			 * @param string $socket Optional socket path.
			 * @return bool
			 */
			public function check_tcp_responsiveness( $host, $port, $float_timeout, $socket = '' ) {
				unset( $host, $port, $float_timeout, $socket );
				return true;
			}

			/**
			 * SQL-mode filtering belongs to WordPress, not this isolated test.
			 *
			 * @param array $modes Optional SQL modes.
			 * @param mixed $dbh_or_table Optional connection.
			 */
			public function set_sql_mode( $modes = array(), $dbh_or_table = false ) {
				unset( $modes, $dbh_or_table );
			}
		};

		$database->set_charset( $dbh );
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_character_set_name -- Assert the client library's actual charset.
		$this->assertSame( 'utf8mb4', mysqli_character_set_name( $dbh ) );

		$database->charset = '';
		$database->collate = '';
		$database->set_charset( $dbh );
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_character_set_name -- Empty settings must preserve the safe client charset.
		$this->assertSame( 'utf8mb4', mysqli_character_set_name( $dbh ) );

		// An explicit per-connection override must survive reuse until the
		// object's own charset settings change.
		$database->set_charset( $dbh, 'latin1', 'latin1_swedish_ci' );
		$database->add_database(
			array(
				'host'     => $host,
				'user'     => getenv( 'LDB_TEST_DB_USER' ),
				'password' => getenv( 'LDB_TEST_DB_PASSWORD' ),
				'name'     => getenv( 'LDB_TEST_DB_NAME' ),
			)
		);
		$database->dbhs['global__r']            = $dbh;
		$database->used_servers['global__r']    = array( 'name' => getenv( 'LDB_TEST_DB_NAME' ) );
		$database->dbh2host['global__r']        = $host;
		$database->db_connections[0]['dbhname'] = 'global__r';
		$this->assertSame( $dbh, $database->db_connect( false, 'SELECT 1' ) );
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_character_set_name -- Cached routing must preserve explicit per-link settings.
		$this->assertSame( 'latin1', mysqli_character_set_name( $dbh ) );

		$database->charset = 'utf8mb4';
		$database->collate = 'utf8mb4_unicode_520_ci';
		$database->db_connect( false, 'SELECT 1' );
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_character_set_name -- Changed object defaults must refresh the cached link.
		$this->assertSame( 'utf8mb4', mysqli_character_set_name( $dbh ) );

		// Empty settings need one session check when they change, not another
		// SELECT every time a normal query reuses the cached connection.
		$database->charset = '';
		$database->collate = '';
		$this->assertSame( $dbh, $database->db_connect( false, 'SELECT 1' ) );
		$get_select_count = static function () use ( $dbh ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Read the server's per-session SELECT counter without incrementing it.
			$result = mysqli_query( $dbh, "SHOW SESSION STATUS LIKE 'Com_select'" );
			$row    = mysqli_fetch_row( $result );
			mysqli_free_result( $result );
			return (int) $row[1];
		};

		$selects_before = $get_select_count();
		for ( $i = 0; $i < 3; ++$i ) {
			$this->assertSame( $dbh, $database->db_connect( false, 'SELECT 1' ) );
		}
		$this->assertSame( $selects_before, $get_select_count(), 'Cached reuse must not issue another charset validation SELECT.' );

		// A pending result must survive while routing moves to a fresh handle.
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Manufacture a busy link for the charset refresh path.
		$busy_result = mysqli_query( $dbh, 'SELECT 1 UNION ALL SELECT 2', MYSQLI_USE_RESULT );
		$this->assertInstanceOf( mysqli_result::class, $busy_result );
		$this->assertFalse( $database->set_charset( $dbh ) );
		$database->charset = 'latin1';
		$database->collate = 'latin1_swedish_ci';

		$replacement = $database->db_connect( false, 'SELECT 1' );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- The recovery path must return a fresh MySQLi handle.
		$this->assertInstanceOf( mysqli::class, $replacement );
		$this->assertNotSame( $dbh, $replacement );
		$this->assertSame( array( '1' ), $busy_result->fetch_row() );
		$busy_result->free();

		$dbh = $replacement;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_character_set_name -- The replacement link receives the changed charset.
		$this->assertSame( 'latin1', mysqli_character_set_name( $dbh ) );

		$database->charset = '';
		$database->collate = '';

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Reproduce an unsafe server-side change invisible to mysqli_character_set_name().
		$this->assertTrue( mysqli_query( $dbh, 'SET NAMES gbk' ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'connection charset is not supported' );
		$database->set_charset( $dbh );
	}
}
