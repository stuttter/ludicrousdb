<?php
/**
 * Minimal LudicrousDB configuration for the maintenance regression workflow.
 *
 * @package LudicrousDB
 */

defined( 'ABSPATH' ) || exit;

$wpdb->add_database( array(
	'host'     => DB_HOST,
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
) );
