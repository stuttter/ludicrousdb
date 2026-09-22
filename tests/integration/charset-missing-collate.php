<?php
/**
 * Exercise an explicit non-utf8mb4 charset with no DB_COLLATE constant.
 *
 * Run with WP-CLI's eval-file against a WordPress site configured with
 * DB_CHARSET=latin1 and no DB_COLLATE definition.
 *
 * @package LudicrousDB
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! defined( 'DB_CHARSET' ) || 'latin1' !== DB_CHARSET || defined( 'DB_COLLATE' ) ) {
	throw new RuntimeException( 'This regression requires latin1 and no DB_COLLATE constant.' );
}

$db = new LudicrousDB();
$db->add_database( array(
	'host'     => DB_HOST,
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
) );

if ( 'latin1' !== $db->charset || '' !== $db->collate ) {
	throw new RuntimeException( 'The utf8mb4 fallback collation was paired with latin1.' );
}

if ( 'latin1' !== $db->get_var( 'SELECT @@character_set_connection' ) ) {
	throw new RuntimeException( 'The connection did not use the configured charset.' );
}
