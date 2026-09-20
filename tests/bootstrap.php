<?php

/**
 * LudicrousDB unit-test bootstrap.
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );

require_once __DIR__ . '/stubs/class-wpdb.php';
require_once dirname( __DIR__ ) . '/ludicrousdb/includes/functions.php';
require_once dirname( __DIR__ ) . '/ludicrousdb/includes/class-ludicrousdb.php';

ldb_default_constants();
