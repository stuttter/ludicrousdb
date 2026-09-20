<?php

/**
 * LudicrousDB unit-test bootstrap.
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
$database_constants = array(
	'DB_HOST'     => 'database.example.test',
	'DB_USER'     => 'wordpress',
	'DB_PASSWORD' => 'not-a-real-password',
	'DB_NAME'     => 'wordpress',
);

foreach ( $database_constants as $constant_name => $constant_value ) {
	define( $constant_name, $constant_value );
}

/**
 * Report that the isolated test suite has no persistent object cache.
 *
 * @return bool
 */
function wp_using_ext_object_cache() {
	return false;
}

require_once __DIR__ . '/stubs/class-wpdb.php';
require_once dirname( __DIR__ ) . '/ludicrousdb/includes/functions.php';
require_once dirname( __DIR__ ) . '/ludicrousdb/includes/class-ludicrousdb.php';
require_once __DIR__ . '/stubs/class-ludicrousdbtestdouble.php';

ldb_default_constants();
