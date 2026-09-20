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
