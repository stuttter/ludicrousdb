<?php

/**
 * LudicrousDB Class
 *
 * This PHPCS error is always a false positive in this file:
 * phpcs:disable WordPress.DB.RestrictedFunctions
 *
 * @package Plugins/LudicrousDB/Class
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * The main LudicrousDB class, which extends wpdb
 *
 * @since 1.0.0
 */
class LudicrousDB extends wpdb {
	/**
	 * Connection probe status: the handle cannot be reused.
	 *
	 * @since 5.4.0
	 *
	 * @var int
	 */
	protected const CONNECTION_DEAD = 0;

	/**
	 * Connection probe status: the handle is immediately reusable.
	 *
	 * @since 5.4.0
	 *
	 * @var int
	 */
	protected const CONNECTION_AVAILABLE = 1;

	/**
	 * Connection probe status: the handle is live but has pending results.
	 *
	 * @since 5.4.0
	 *
	 * @var int
	 */
	protected const CONNECTION_BUSY = 2;

	/**
	 * MySQL client error for a command issued while results are still pending.
	 *
	 * @since 5.4.0
	 *
	 * @var int
	 */
	private const MYSQL_COMMANDS_OUT_OF_SYNC = 2014;

	/**
	 * Handles that received one commands-out-of-sync grace probe.
	 *
	 * @since 5.4.0
	 *
	 * @var array<string, bool|WeakReference<mysqli>>
	 */
	private $busy_connection_probes = array();

	/**
	 * Busy handles detached from routing while their active results finish.
	 *
	 * @since 5.4.0
	 *
	 * @var array<string, mysqli|resource>
	 */
	private $detached_busy_connections = array();

	/**
	 * The last table that was queried.
	 *
	 * @var string Default empty string.
	 */
	public $last_table = '';

	/**
	 * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
	 * is sent and the MySQL result resource stored here. The next query
	 * for FOUND_ROWS() will retrieve this. We do this to prevent any
	 * intervening queries from making FOUND_ROWS() inaccessible. You may
	 * prevent this by adding "NO_SELECT_FOUND_ROWS" in a comment.
	 *
	 * @var resource Default null.
	 */
	public $last_found_rows_result = null;

	/**
	 * Whether to store queries in an array. Useful for debugging and profiling.
	 *
	 * @var bool Default false.
	 */
	public $save_queries = false;

	/**
	 * Database handle.
	 *
	 * The current MySQL link resource.
	 *
	 * @var mysqli|false|null Default null.
	 */
	public $dbh = null;

	/**
	 * Database handles.
	 *
	 * Associative array (dbhname => dbh) of established MySQL connections.
	 *
	 * @var array
	 */
	public $dbhs = array();

	/**
	 * Database servers.
	 *
	 * Multi-dimensional array (dataset => servers) of datasets and servers.
	 *
	 * @var array Default empty array.
	 */
	public $ludicrous_servers = array();

	/**
	 * Database tables.
	 *
	 * Optional directory of tables and their datasets.
	 *
	 * @var array Default empty array.
	 */
	public $ludicrous_tables = array();

	/**
	 * Callbacks.
	 *
	 * Optional directory of callbacks to determine datasets from queries.
	 *
	 * @var array Default empty array.
	 */
	public $ludicrous_callbacks = array();

	/**
	 * Custom callback to save debug info in $this->queries.
	 *
	 * @var callable Default null.
	 */
	public $save_query_callback = null;

	/**
	 * Whether to pass "p:" into mysqli_real_connect() to force a
	 * persistent connection.
	 *
	 * @var bool Default false.
	 */
	public $persistent = false;

	/**
	 * Kill the application if a database connection fails.
	 *
	 * @var bool Default false.
	 */
	public $die_on_disconnect = false;

	/**
	 * The maximum number of db links to keep open. The least-recently used
	 * link will be closed when the number of links exceeds this.
	 *
	 * @var int Default 10.
	 */
	public $max_connections = 10;

	/**
	 * Whether to check with fsockopen prior to mysqli_real_connect.
	 *
	 * @var bool Default true.
	 */
	public $check_tcp_responsiveness = true;

	/**
	 * The amount of time to wait before trying again to ping mysql server.
	 *
	 * @var float Default 0.1.
	 */
	public $recheck_timeout = 0.1;

	/**
	 * The number of times to retry reconnecting before dying
	 *
	 * @var int Default 3.
	 */
	public $reconnect_retries = 3;

	/**
	 * The amount of time to wait before trying again to connect to a mysql server.
	 *
	 * @var float Default 1.
	 */
	public $reconnect_sleep = 1.0;

	/**
	 * Whether to check for heartbeats.
	 *
	 * @var bool Default true.
	 */
	public $check_dbh_heartbeats = true;

	/**
	 * Keeps track of the dbhname usage and errors.
	 *
	 * @var array Default empty array.
	 */
	public $dbhname_heartbeats = array();

	/**
	 * The tables that have been written to.
	 *
	 * Disables replica connections if explicitly true.
	 *
	 * @var array|bool Default empty array.
	 */
	public $send_reads_to_primaries = array();

	/**
	 * The log of db connections made and the time each one took.
	 *
	 * @var array Default empty array.
	 */
	public $db_connections = array();

	/**
	 * Object charset and collation when each MySQLi connection was last configured.
	 *
	 * @var array<string, array{string, string}>
	 */
	protected $connection_charsets = array();

	/**
	 * The list of unclosed connections sorted by LRU.
	 *
	 * @var array Default empty array.
	 */
	public $open_connections = array();

	/**
	 * Lookup array (dbhname => host:port).
	 *
	 * @var array Default empty array.
	 */
	public $dbh2host = array();

	/**
	 * The last server used and the database name selected.
	 *
	 * @var array Default empty array.
	 */
	public $last_used_server = array();

	/**
	 * Lookup array (dbhname => (server, db name) ) for re-selecting the db
	 * when a link is re-used.
	 *
	 * @var array Default empty array.
	 */
	public $used_servers = array();

	/**
	 * Whether to save debug_backtrace in save_query_callback. You may wish
	 * to disable this, e.g. when tracing out-of-memory problems.
	 *
	 * @var bool Default true.
	 */
	public $save_backtrace = true;

	/**
	 * The default database attributes that are used when
	 *
	 * @var array Default database values.
	 */
	public $database_defaults = array(
		'dataset'       => 'global',
		'write'         => 1,
		'read'          => 1,
		'timeout'       => 0.2,
		'port'          => 3306,
		'lag_threshold' => null,
	);

	/**
	 * Name of object TCP cache group.
	 *
	 * @var string Default 'ludicrousdb'.
	 */
	public $tcp_cache_group = 'ludicrousdb';

	/**
	 * The amount of time to wait before trying again to ping a server.
	 *
	 * @var float Default 0.2 seconds (I.E. 200ms).
	 */
	public $tcp_timeout = 0.2;

	/**
	 * In memory cache for TCP connected status.
	 *
	 * @var array Default empty array.
	 */
	private $tcp_cache = array();

	/**
	 * Whether to ignore replica lag.
	 *
	 * @var bool Default false.
	 */
	private $ignore_replica_lag = false;

	/**
	 * Number of unique servers.
	 *
	 * @var null|int Default null. Might be zero or more.
	 */
	public $unique_servers = null;

	/**
	 * Result of the last callback run.
	 *
	 * @var mixed Default null.
	 */
	public $callback_result = null;

	/**
	 * The current table being queried.
	 *
	 * @var string|null Default null.
	 */
	public $table = null;

	/**
	 * The lag threshold for replica servers.
	 *
	 * @var float|null Default null.
	 */
	public $lag_threshold = null;

	/**
	 * The current database handle name.
	 *
	 * @var string|null Default null.
	 */
	public $dbhname = null;

	/**
	 * The current dataset being queried.
	 *
	 * @var string|null Default null.
	 */
	public $dataset = null;

	/**
	 * The current host being connected to.
	 *
	 * @var string|null Default null.
	 */
	public $current_host = null;

	/**
	 * The last database connection information.
	 *
	 * @var array|null Default null.
	 */
	public $last_connection = null;

	/**
	 * The cache key for lag information.
	 *
	 * @var string|null Default null.
	 */
	public $lag_cache_key = null;

	/**
	 * Array of renamed class variables.
	 *
	 * @since 5.2.0
	 *
	 * @var array Default key-value array of old and new class variables.
	 */
	private static $renamed_vars = array(
		'ignore_slave_lag' => 'ignore_replica_lag',
		'srtm'             => 'send_reads_to_primaries',
		'allow_bail'       => 'die_on_disconnect',
	);

	/**
	 * Array of binary blob database column types.
	 *
	 * @since 5.2.0
	 *
	 * @var array Default array of binary blob column types.
	 */
	private static $bin_blobs = array(
		'BINARY',
		'VARBINARY',
		'TINYBLOB',
		'MEDIUMBLOB',
		'BLOB',
		'LONGBLOB',
	);

	/**
	 * Array of allowed character sets.
	 *
	 * @since 5.2.0
	 *
	 * @var array Default array of allowed character sets.
	 */
	private static $allowed_charsets = array(
		'utf8',
		'utf8mb4',
		'latin1',
	);

	/**
	 * Gets ready to make database connections
	 *
	 * @since 1.0.0
	 * @since 5.2.0 Matched parameters to parent wpdb class
	 *
	 * @param array|string $dbuser     New class variables, or Database user.
	 * @param string       $dbpassword Database password.
	 * @param string       $dbname     Database name.
	 * @param string       $dbhost     Database host.
	 */
	public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {

		// Show errors if debug-display mode is enabled
		if ( $this->is_debug_display() ) {
			$this->show_errors();
		}

		// Start the TCP cache
		$this->tcp_cache_start();

		// Initialize defaults before applying explicit constructor settings.
		$this->init_charset();

		// Prepare class vars
		$this->prepare_class_vars( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	/**
	 * Magic method to correctly get renamed attributes.
	 *
	 * @since 5.2.0
	 *
	 * @param  string $name The key to set.
	 * @return mixed
	 */
	public function __get( $name ) {

		// Check if old var is in $class_vars_renamed
		if ( isset( self::$renamed_vars[ $name ] ) ) {
			$name = self::$renamed_vars[ $name ];

			// Renamed properties may be private to this class.
			return $this->{$name};
		}

		return parent::__get( $name );
	}

	/**
	 * Magic method to correctly set renamed attributes.
	 *
	 * @since 5.2.0
	 *
	 * @param string $name  The key to set.
	 * @param mixed  $value The value to set.
	 */
	public function __set( $name, $value ) {

		// Check if old var is in $class_vars_renamed
		if ( isset( self::$renamed_vars[ $name ] ) ) {
			$name = self::$renamed_vars[ $name ];

			// Renamed properties may be private to this class.
			$this->{$name} = $value;

			return;
		}

		parent::__set( $name, $value );
	}

	/**
	 * Prepare class vars from constructor.
	 *
	 * @since 5.2.0
	 *
	 * @param array|string $dbuser     New class variables, or Database user.
	 * @param string       $dbpassword Database password.
	 * @param string       $dbname     Database name.
	 * @param string       $dbhost     Database host.
	 */
	protected function prepare_class_vars( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {

		// Bail if first method parameter is empty
		if ( empty( $dbuser ) ) {
			return;
		}

		// Default class vars
		$class_vars = array();

		// Custom class vars via array of arguments
		if ( is_array( $dbuser ) ) {
			$class_vars = $dbuser;

			// WPDB style parameter pattern
		} elseif ( is_string( $dbuser ) ) {

			// Only compact if all params are not empty
			if ( ! empty( $dbpassword ) && ! empty( $dbname ) && ! empty( $dbhost ) ) {
				$class_vars = compact( 'dbuser', 'dbpassword', 'dbname', 'dbhost' );
			}
		}

		// Only set vars if there are vars to set
		if ( ! empty( $class_vars ) ) {
			$this->set_class_vars( $class_vars );
		}
	}

	/**
	 * Sets class vars from an array of arguments.
	 *
	 * @since 5.2.0
	 * @param array $args Array of variables to set.
	 */
	protected function set_class_vars( $args = array() ) {

		// Bail if empty arguments
		if ( empty( $args ) ) {
			return;
		}

		// Get class vars as array of keys
		$class_vars     = get_class_vars( __CLASS__ );
		$class_var_keys = array_keys( $class_vars );

		/**
		 * Explicit backwards compatibility for passing default_lag_threshold
		 * in as a class argument.
		 *
		 * @since 5.2.0
		 */
		if (
			isset( $args['default_lag_threshold'] )
			&&
			! isset( $args['database_defaults']['lag_threshold'] )
		) {
			$this->database_defaults['lag_threshold'] = $args['default_lag_threshold'];
		}

		// Create reverse lookup for renamed vars (new name => old name) for performance
		// $renamed_vars has old names as keys and new names as values
		$new_to_old = array_flip( self::$renamed_vars );

		// Loop through class vars and override if set in $args
		foreach ( $class_var_keys as $var ) {

			// Check if old var is in $args
			if (
				isset( $new_to_old[ $var ] )
				&&
				isset( $args[ $new_to_old[ $var ] ] )
			) {
				$this->{$var} = $args[ $new_to_old[ $var ] ];
			}

			// Check if current var is in $args
			if ( isset( $args[ $var ] ) ) {
				$this->{$var} = $args[ $var ];
			}
		}
	}

	/**
	 * Sets $this->charset and $this->collate
	 *
	 * @since 1.0.0
	 */
	public function init_charset() {

		// Defaults
		$charset = 'utf8mb4';
		$collate = 'utf8mb4_unicode_520_ci';

		// Use constant if defined
		if ( defined( 'DB_COLLATE' ) ) {
			$collate = DB_COLLATE;
		}

		// Use constant if defined
		if ( defined( 'DB_CHARSET' ) ) {
			$charset = DB_CHARSET;

			// Do not pair a custom charset with the utf8mb4 fallback collation.
			if ( ! defined( 'DB_COLLATE' ) && ( ! is_string( $charset ) || 'utf8mb4' !== strtolower( $charset ) ) ) {
				$collate = '';
			}
		}

		// Determine charset and collate
		$charset_collate = $this->determine_charset( $charset, $collate );

		// Set charset and collate
		$this->charset = $charset_collate['charset'];
		$this->collate = $charset_collate['collate'];
	}

	/**
	 * Add the connection parameters for a database
	 *
	 * @since 1.0.0
	 *
	 * @param array $db Default to empty array.
	 */
	public function add_database( array $db = array() ) {

		// Merge using defaults
		$db = array_merge( $this->database_defaults, $db );

		// Break these apart to make code easier to understand below
		$dataset = $db['dataset'];
		$write   = $db['write'];
		$read    = $db['read'];

		// We do not include the dataset in the array. It's used as a key.
		unset( $db['dataset'] );

		// Maybe add database to array of write's
		if ( ! empty( $write ) ) {
			$this->ludicrous_servers[ $dataset ]['write'][ $write ][] = $db;
		}

		// Maybe add database to array of read's
		if ( ! empty( $read ) ) {
			$this->ludicrous_servers[ $dataset ]['read'][ $read ][] = $db;
		}
	}

	/**
	 * Specify the dataset where a table is found
	 *
	 * @since 1.0.0
	 *
	 * @param string $dataset  Database.
	 * @param string $table    Table name.
	 */
	public function add_table( $dataset, $table ) {
		$this->ludicrous_tables[ $table ] = $dataset;
	}

	/**
	 * Add a callback to a group of callbacks
	 *
	 * The default group is 'dataset', used to examine queries & determine dataset
	 *
	 * @since 1.0.0
	 *
	 * @param function $callback Callback on a dataset.
	 * @param string   $group    Key name of dataset.
	 */
	public function add_callback( $callback, $group = 'dataset' ) {
		$this->ludicrous_callbacks[ $group ][] = $callback;
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 *
	 * Statements are considered read-only when:
	 * 1. not including UPDATE nor other "may-be-write" strings
	 * 2. begin with SELECT etc.
	 *
	 * @since 1.0.0
	 *
	 * @param string $q Query.
	 *
	 * @return bool
	 */
	public function is_write_query( $q = '' ) {

		// Trim potential whitespace or subquery chars
		$q = ltrim( $q, "\r\n\t (" );

		// Possible writes
		if ( preg_match( '/(?:^|\s)(?:ALTER|CREATE|ANALYZE|CHECK|OPTIMIZE|REPAIR|CALL|DELETE|DROP|INSERT|LOAD|REPLACE|UPDATE|FOR\s+UPDATE|SET|RENAME\s+TABLE|[a-z]+_LOCKS?\()(?:\s|$)/i', $q ) ) {
			return true;
		}

		// Not possible non-writes (phew!)
		return ! preg_match( '/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)(?:\s|$)/i', $q );
	}

	/**
	 * Is the primary database dead?
	 *
	 * @since 5.2.0
	 *
	 * @return bool True if primary database is dead, false otherwise.
	 */
	public function is_primary_dead() {
		return (
			defined( 'PRIMARY_DB_DEAD' )
			||
			defined( 'MASTER_DB_DEAD' )
		);
	}

	/**
	 * Is debug mode enabled?
	 *
	 * @since 5.2.0
	 */
	public function is_debug() {
		return (
			( defined( 'LDB_DEBUG' ) && LDB_DEBUG )
			||
			( defined( 'WP_DEBUG' ) && WP_DEBUG )
		);
	}

	/**
	 * Is debug display mode enabled?
	 *
	 * @since 5.2.0
	 */
	public function is_debug_display() {
		return (
			$this->is_debug()
			&&
			( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY )
		);
	}

	/**
	 * Are queries being saved?
	 *
	 * @since 5.2.0
	 */
	public function is_saving_queries() {
		return (
			( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
		);
	}

	/**
	 * Set a flag to prevent reading from replicas, which might be lagging
	 * after a write.
	 *
	 * @since 5.2.0
	 */
	public function send_reads_to_primaries() {
		$this->send_reads_to_primaries = true;
	}

	/**
	 * Callbacks are executed in the order in which they are registered until one
	 * of them returns something other than null
	 *
	 * @since 1.0.0
	 * @param string $group Group, key name in array.
	 * @param array  $args   Args passed to callback. Default to null.
	 */
	public function run_callbacks( $group = '', $args = null ) {

		// Bail if no callbacks for group
		if (
			empty( $group )
			||
			! isset( $this->ludicrous_callbacks[ $group ] )
			||
			! is_array( $this->ludicrous_callbacks[ $group ] )
		) {
			return;
		}

		// Prepare args
		if ( ! isset( $args ) ) {
			$args = array( &$this );
		} elseif ( is_array( $args ) ) {
			$args[] = &$this;
		} else {
			$args = array( $args, &$this );
		}

		// Loop through callbacks
		foreach ( $this->ludicrous_callbacks[ $group ] as $func ) {

			// Run callback
			$result = call_user_func_array( $func, $args );

			// Return result if not null
			if ( isset( $result ) ) {
				return $result;
			}
		}
	}

	/**
	 * Figure out which db server should handle the query, and connect to it.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|string $allow_bail Optional. Allows the function to bail. A query string preserves
	 *                                the historical calling convention. Default true.
	 * @param string      $query      Optional. Query used to select a LudicrousDB dataset.
	 *
	 * @return false|mysqli|resource MySQL database connection.
	 */
	public function db_connect( $allow_bail = true, $query = '' ) {

		// Preserve the historical db_connect( $query ) calling convention.
		if ( is_string( $allow_bail ) && '' === $query ) {
			$query      = $allow_bail;
			$allow_bail = true;
		}

		// Core may call db_connect() before a query is available.
		if ( empty( $query ) ) {
			$query = 'SELECT 1';
		}

		// Fix error reporting change (in PHP 8.1) causing fatal errors
		// See: https://php.watch/versions/8.1/mysqli-error-mode
		mysqli_report( MYSQLI_REPORT_OFF );

		// Can be empty/false if the query is e.g. "COMMIT"
		$this->table = $this->get_table_from_query( $query );
		if ( empty( $this->table ) ) {
			$this->table = 'no-table';
		}
		$this->last_table = $this->table;

		// Use current table with no callback results
		if ( isset( $this->ludicrous_tables[ $this->table ] ) ) {
			$dataset               = $this->ludicrous_tables[ $this->table ];
			$this->callback_result = null;

			// Run callbacks and either extract or update dataset
		} else {

			// Run callbacks and get result
			$this->callback_result = $this->run_callbacks( 'dataset', $query );

			// Set if not null
			if ( ! is_null( $this->callback_result ) ) {
				if ( is_array( $this->callback_result ) ) {
					extract( $this->callback_result, EXTR_OVERWRITE );
				} else {
					$dataset = $this->callback_result;
				}
			}
		}

		if ( ! isset( $dataset ) ) {
			$dataset = 'global';
		}

		if ( empty( $dataset ) ) {
			return $allow_bail
				? $this->bail( "Unable to determine which dataset to query. ({$this->table})" )
				: false;
		} else {
			$this->dataset = $dataset;
		}

		$this->run_callbacks( 'dataset_found', $dataset );

		if ( empty( $this->ludicrous_servers ) ) {

			// Return early dbh if already set
			if ( $this->dbh_type_check( $this->dbh ) ) {
				return $this->dbh;
			}

			// Bail if missing database constants
			if (
				! defined( 'DB_HOST' )
				||
				! defined( 'DB_USER' )
				||
				! defined( 'DB_PASSWORD' )
				||
				! defined( 'DB_NAME' )
			) {
				return $allow_bail
					? $this->bail( 'We were unable to query because there was no database defined.' )
					: false;
			}

			// Fallback to wpdb::db_connect() method.

			$this->dbuser     = DB_USER;
			$this->dbpassword = DB_PASSWORD;
			$this->dbname     = DB_NAME;
			$this->dbhost     = DB_HOST;

			parent::db_connect( $allow_bail );

			return $this->dbh;
		}

		/**
		 * Determine whether the query must be sent to the primary (a writable server)
		 */

		// Explicitly already set to using the primary
		if (
			! empty( $use_primary ) // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
			||
			( true === $this->send_reads_to_primaries )
			||
			isset( $this->send_reads_to_primaries[ $this->table ] )
		) {
			$use_primary = true;

			// Is this a write query?
		} elseif ( $this->is_write_query( $query ) ) {
			$use_primary = true;

			if ( is_array( $this->send_reads_to_primaries ) ) {
				$this->send_reads_to_primaries[ $this->table ] = true;
			}

			// Detect queries that have a join in the send_reads_to_primaries array.
		} elseif (
			! isset( $use_primary )
			&&
			is_array( $this->send_reads_to_primaries )
			&&
			! empty( $this->send_reads_to_primaries )
		) {
			$use_primary = false;
			$query_match = substr( $query, 0, 1000 );

			foreach ( $this->send_reads_to_primaries as $key => $value ) {
				if ( false !== stripos( $query_match, $key ) ) {
					$use_primary = true;
					break;
				}
			}

			// Default to false.
		} else {
			$use_primary = false;
		}

		if ( ! empty( $use_primary ) ) {
			$this->dbhname = $dbhname = $dataset . '__w';
			$operation     = 'write';
		} else {
			$this->dbhname = $dbhname = $dataset . '__r';
			$operation     = 'read';
		}

		// Try to reuse an existing connection
		while (
			isset( $this->dbhs[ $dbhname ] )
			&&
			$this->dbh_type_check( $this->dbhs[ $dbhname ] )
		) {

			// Get the connection indexes
			$conns = array_keys( $this->db_connections );
			$conn  = 0;

			// Loop through connections to find the matching dbhname
			if ( ! empty( $conns ) ) {
				foreach ( $conns as $i ) {
					if ( $this->db_connections[ $i ]['dbhname'] === $dbhname ) {
						$conn = (int) $i;
					}
				}
			}

			// Try to use the database name from the callback, if scalar
			if (
				! empty( $server['name'] )
				&&
				is_scalar( $server['name'] )
			) {
				$name = (string) $server['name'];

				// A callback specified a database name, but it is possible the
				// existing connection selected a different one.
				if ( $this->used_servers[ $dbhname ]['name'] !== $name ) {

					// If the select fails, disconnect and try again
					if ( ! $this->select( $name, $this->dbhs[ $dbhname ] ) ) {

						// This can happen when the user varies and lacks
						// permission on the $name database
						$this->increment_db_connection( $conn, 'disconnect (select failed)' );
						$this->disconnect( $dbhname );

						break;
					}

					// Update the used server name
					$this->used_servers[ $dbhname ]['name'] = $name;
				}

				// Otherwise, use the name from the last connection
			} else {
				$name = $this->used_servers[ $dbhname ]['name'];
			}

			$this->current_host = $this->dbh2host[ $dbhname ];

			// Keep this connection at the top of the stack to prevent
			// disconnecting from frequently-used connections
			$key = array_search( $dbhname, $this->open_connections, true );
			if ( $key !== false ) {
				unset( $this->open_connections[ $key ] );
				$this->open_connections[] = $dbhname;
			}

			$this->last_used_server = $this->used_servers[ $dbhname ];
			$this->last_connection  = compact( 'dbhname', 'name' );

			// Check if the connection is still alive
			if ( $this->should_mysql_ping( $dbhname ) ) {
				if ( ! $this->check_connection( $this->die_on_disconnect, $this->dbhs[ $dbhname ], $query ) ) {
					$this->increment_db_connection( $conn, 'disconnect (ping failed)' );
					$this->disconnect( $dbhname );

					break;
				}

				// A reconnect callback may route the query to a different cached name.
				if ( ! isset( $this->dbhs[ $dbhname ] ) || ! $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					return $this->dbh_type_check( $this->dbh )
						? $this->dbh
						: false;
				}
			}

			// Increment the connection counter
			$this->increment_db_connection( $conn, 'queries' );

			return $this->refresh_cached_connection_charset( $dbhname, $query );
		}

		// Bail if trying to connect to a dead primary
		if (
			! empty( $use_primary )
			&&
			$this->is_primary_dead()
		) {
			return $allow_bail
				? $this->bail( 'We are updating the database. Please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online.' )
				: false;
		}

		// Bail if no servers available for table/dataset/operation
		if ( empty( $this->ludicrous_servers[ $dataset ][ $operation ] ) ) {
			return $allow_bail
				? $this->bail( "No databases available with {$this->table} ({$dataset})" )
				: false;
		}

		// Put the operations in order by key
		ksort( $this->ludicrous_servers[ $dataset ][ $operation ] );

		// Make a list of at least $this->reconnect_retries connections to try,
		// repeating as necessary.
		$servers = array();
		do {
			foreach ( $this->ludicrous_servers[ $dataset ][ $operation ] as $group => $items ) {
				$keys = array_keys( $items );

				shuffle( $keys );

				foreach ( $keys as $key ) {
					$servers[] = compact( 'group', 'key' );
				}
			}

			$tries_remaining = count( $servers );
			if ( 0 === $tries_remaining ) {
				return $allow_bail
					? $this->bail( "No database servers were found to match the query. ({$this->table}, {$dataset})" )
					: false;
			}

			if ( is_null( $this->unique_servers ) ) {
				$this->unique_servers = $tries_remaining;
			}
		} while ( $tries_remaining < $this->reconnect_retries );

		// Connect to a database server
		do {
			$unique_lagged_replicas = array();
			$success                = false;

			foreach ( $servers as $group_key ) {
				--$tries_remaining;

				// If all servers are lagged, we need to start ignoring the lag and retry
				if ( count( $unique_lagged_replicas ) === (int) $this->unique_servers ) {
					break;
				}

				// $group, $key
				$group = $group_key['group'];
				$key   = $group_key['key'];

				// $host, $port, $user, $password, $name, $write, $read, $timeout, $lag_threshold
				$db_config     = $this->ludicrous_servers[ $dataset ][ $operation ][ $group ][ $key ];
				$host          = $db_config['host'];
				$port          = $db_config['port'];
				$user          = $db_config['user'];
				$password      = $db_config['password'];
				$name          = $db_config['name'];
				$write         = $db_config['write'];
				$read          = $db_config['read'];
				$timeout       = $db_config['timeout'];
				$lag_threshold = $db_config['lag_threshold'];

				// Overwrite vars from $server (if it was extracted from a callback)
				if ( ! empty( $server ) && is_array( $server ) ) {
					extract( $server, EXTR_OVERWRITE );

					// Otherwise, set $server to an empty array
				} else {
					$server = array();
				}

				// Normalize host, port, and socket using wpdb's canonical parser.
				list( $host, $port, $socket ) = $this->parse_database_host( $host, $port );

				// Maybe use the default timeout (usually: 200ms)
				if ( ! isset( $timeout ) ) {
					$timeout = (float) $this->tcp_timeout;
				}

				// Get the minimum group here, in case $server rewrites it
				if ( ! isset( $min_group ) || ( $min_group > $group ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
					$min_group = $group;
				}

				// Format the cache key using the extracted host and port
				$host_and_port = $this->tcp_get_cache_key( $host, $port, $socket );

				// Can be used by the lag callbacks
				$this->lag_cache_key = $host_and_port;
				$this->lag_threshold = isset( $lag_threshold )
					? $lag_threshold
					: $this->database_defaults['lag_threshold'];

				// Check for a lagged replica, if applicable
				if (
					empty( $use_primary )
					&&
					empty( $write )
					&&
					empty( $this->ignore_replica_lag )
					&&
					isset( $this->lag_threshold )
					&&
					! isset( $server['host'] )
					&&
					( $lagged_status = $this->get_lag_cache() ) === DB_LAG_BEHIND // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				) {

					// If it is the last lagged replica. and it is with the best
					// preference, we will ignore its lag
					if (
						! isset( $unique_lagged_replicas[ $host_and_port ] )
						&&
						( ( count( $unique_lagged_replicas ) + 1 ) === (int) $this->unique_servers )
						&&
						( $group === $min_group )
					) {
						$this->lag_threshold = null;

						// Otherwise, log the lag and continue on
					} else {
						$unique_lagged_replicas[ $host_and_port ] = $this->lag;

						continue;
					}
				}

				$this->timer_start();

				$tcp = $this->check_tcp_responsiveness( $host, $port, $timeout, $socket );

				// Connect if necessary or possible
				if (
					! empty( $use_primary )
					||
					empty( $tries_remaining )
					||
					( true === $tcp )
				) {
					$this->single_db_connect( $dbhname, $host_and_port, $user, $password );
				} else {
					$this->dbhs[ $dbhname ] = false;
				}

				$elapsed = $this->timer_stop();

				if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					/**
					 * If we care about lag, disconnect lagged replicas and try
					 * to find others. We don't disconnect if it is the last
					 * lagged replica and it is with the best preference.
					 */
					if (
						empty( $use_primary )
						&&
						empty( $write )
						&&
						empty( $this->ignore_replica_lag )
						&&
						isset( $this->lag_threshold )
						&&
						! isset( $server['host'] )
						&&
						( $lagged_status !== DB_LAG_OK )
						&&
						( $lagged_status = $this->get_lag() ) === DB_LAG_BEHIND // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
						&&
						! (
							! isset( $unique_lagged_replicas[ $host_and_port ] )
							&&
							( (int) $this->unique_servers === ( count( $unique_lagged_replicas ) + 1 ) )
							&&
							( $group === $min_group )
						)
					) {
						$unique_lagged_replicas[ $host_and_port ] = $this->lag;
						$this->disconnect( $dbhname );

						$this->dbhs[ $dbhname ] = false;
						$success                = false;
						$msg                    = "Replication lag of {$this->lag}s on {$host_and_port} ({$dbhname})";

						$this->print_error( $msg );

						continue;

					} else {
						$this->set_sql_mode( array(), $this->dbhs[ $dbhname ] );

						if ( $this->select( $name, $this->dbhs[ $dbhname ] ) ) {
							$this->current_host         = $host_and_port;
							$this->dbh2host[ $dbhname ] = $host_and_port;

							// Define these to avoid undefined variable notices
							$queries = isset( $queries ) ? $queries : 1; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
							$lag     = isset( $this->lag ) ? $this->lag : 0;

							$this->last_connection    = compact( 'dbhname', 'host', 'port', 'socket', 'user', 'name', 'tcp', 'elapsed', 'success', 'queries', 'lag' );
							$this->db_connections[]   = $this->last_connection;
							$this->open_connections[] = $dbhname;
							$success                  = true;

							break;
						}
					}
				}

				$success                = false;
				$this->last_connection  = compact( 'dbhname', 'host', 'port', 'socket', 'user', 'name', 'tcp', 'elapsed', 'success' );
				$this->db_connections[] = $this->last_connection;

				if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					$error = mysqli_error( $this->dbhs[ $dbhname ] );
					$errno = mysqli_errno( $this->dbhs[ $dbhname ] );
				}

				$msg  = date( 'Y-m-d H:i:s' ) . " Can't select {$dbhname} - \n"; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				$msg .= "'referrer' => '{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}',\n";
				$msg .= "'host' => {$host},\n";

				if ( ! empty( $error ) ) {
					$msg .= "'error' => {$error},\n";
				}

				if ( ! empty( $errno ) ) {
					$msg .= "'errno' => {$errno},\n";

					// Maybe log the error to heartbeats
					if ( ! empty( $this->check_dbh_heartbeats ) ) {
						$this->dbhname_heartbeats[ $dbhname ]['last_errno'] = $errno;
					}
				}

				$msg .= "'tcp_responsive' => " . ( $tcp === true
						? 'true'
						: $tcp ) . ",\n";

				$msg .= "'lagged_status' => " . ( isset( $lagged_status )
						? $lagged_status
						: DB_LAG_UNKNOWN );

				$this->print_error( $msg );
			}

			// Maybe bail if we have tried all the servers and none of them
			// worked.
			if (
				empty( $success )
				||
				! isset( $this->dbhs[ $dbhname ] )
				||
				! $this->dbh_type_check( $this->dbhs[ $dbhname ] )
			) {

				// Lagged replicas were not used. Ignore the lag for this
				// connection attempt and retry.
				if (
					empty( $this->ignore_replica_lag )
					&&
					count( $unique_lagged_replicas )
				) {
					$this->ignore_replica_lag = true;
					$tries_remaining          = count( $servers );

					continue;
				}

				// Setup the callback data
				$callback_data = array(
					'host'      => $host,
					'port'      => $port,
					'operation' => $operation,
					'table'     => $this->table,
					'dataset'   => $dataset,
					'dbhname'   => $dbhname,
				);

				$this->run_callbacks( 'db_connection_error', $callback_data );

				return $allow_bail
					? $this->bail( "Unable to connect to {$host}:{$port} to {$operation} table '{$this->table}' ({$dataset})" )
					: false;
			}

			break;
		} while ( true );

		// A new link must not inherit a previous object's cached settings.
		$this->forget_connection_charset( $this->dbhs[ $dbhname ] );
		if ( false === $this->set_charset( $this->dbhs[ $dbhname ] ) ) {
			$this->disconnect( $dbhname );
			return $allow_bail
				? $this->bail( 'Unable to verify the database connection charset.' )
				: false;
		}

		$this->dbh                      = $this->dbhs[ $dbhname ]; // needed by $wpdb->_real_escape()
		$this->last_used_server         = compact( 'host', 'user', 'name', 'write', 'read' );
		$this->used_servers[ $dbhname ] = $this->last_used_server;

		while (
			( false === $this->persistent )
			&&
			( count( $this->open_connections ) > $this->max_connections ) // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
		) {
			$oldest_connection = array_shift( $this->open_connections );

			if ( $this->dbhs[ $oldest_connection ] !== $this->dbhs[ $dbhname ] ) {
				$this->disconnect( $oldest_connection );
			}
		}

		return $this->dbhs[ $dbhname ];
	}

	/**
	 * Increment a database connection counter.
	 *
	 * @since 5.2.0
	 * @param int    $connection Connection index.
	 * @param string $name       Connection name.
	 */
	protected function increment_db_connection( $connection = 0, $name = '' ) {

		// Bail if name is empty
		if ( empty( $name ) ) {
			return;
		}

		// Initialize the connection counter
		if ( ! isset( $this->db_connections[ $connection ] ) ) {
			$this->db_connections[ $connection ] = array();
		}

		// Increment the connection counter
		if ( ! isset( $this->db_connections[ $connection ][ $name ] ) ) {
			$this->db_connections[ $connection ][ $name ] = 1;
		} else {
			++$this->db_connections[ $connection ][ $name ];
		}
	}

	/**
	 * Normalize a database host while preserving the separately configured port.
	 *
	 * @since 5.4.0
	 *
	 * @param string $host Database host, optionally including a port or socket.
	 * @param int    $port Separately configured port.
	 * @return array{0: string, 1: int, 2: string, 3: bool} Host, port, socket, and IPv6 flag.
	 */
	protected function parse_database_host( $host, $port = 0 ) {
		$host_data = $this->parse_db_host( $host );

		if ( false === $host_data ) {
			if ( empty( $port ) ) {
				$port = (int) $this->database_defaults['port'];
			}

			return array( $host, (int) $port, '', false );
		}

		list( $host, $parsed_port, $socket, $is_ipv6 ) = $host_data;

		if ( null !== $parsed_port ) {
			$port = $parsed_port;
		} elseif ( empty( $port ) ) {
			$port = (int) $this->database_defaults['port'];
		}

		return array(
			$host,
			(int) $port,
			null === $socket ? '' : $socket,
			$is_ipv6,
		);
	}

	/**
	 * Connect selected database
	 *
	 * @since 1.0.0
	 *
	 * @param string $dbhname Database handle name.
	 * @param string $host Internet address, including a port and optional socket.
	 * @param string $user Database user.
	 * @param string $password Database password.
	 *
	 * @return bool|mysqli|resource
	 */
	protected function single_db_connect(
		$dbhname,
		$host,
		$user,
		#[\SensitiveParameter]
		$password
	) {
		$tcp_cache_key  = $host;
		$this->is_mysql = true;

		list( $host, $port, $socket, $is_ipv6 ) = $this->parse_database_host( $host );

		// Check client flags
		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' )
			? MYSQL_CLIENT_FLAGS
			: 0;

		// Initialize the database handle
		$this->dbhs[ $dbhname ] = mysqli_init();

		/**
		 * If DB_HOST begins with a 'p:', allow it to be passed to
		 * mysqli_real_connect(). mysqli supports persistent connections
		 * starting with PHP 5.3.0.
		 */
		if (
			( true === $this->persistent )
			&&
			version_compare( phpversion(), '5.3.0', '>=' )
		) {
			$pre_host = 'p:';
		} else {
			$pre_host = '';
		}

		// mysqlnd requires brackets around IPv6 addresses.
		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$host = "[{$host}]";
		}

		// Connect to the database
		mysqli_real_connect(
			$this->dbhs[ $dbhname ],
			$pre_host . $host,
			$user,
			$password,
			'',
			$port,
			$socket,
			$client_flags
		);

		// Bail if connection failed
		if ( ! empty( $this->dbhs[ $dbhname ]->connect_errno ) ) {
			$this->dbhs[ $dbhname ] = false;

			$this->tcp_cache_set( $tcp_cache_key, 'down' );

			return false;
		}

		$this->update_heartbeat( $dbhname );
	}

	/**
	 * Change the current SQL mode, and ensure its WordPress compatibility.
	 *
	 * If no modes are passed, it will ensure the current MySQL server
	 * modes are compatible.
	 *
	 * @since 1.0.0
	 *
	 * @param array                        $modes        Optional. A list of SQL modes to set.
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 * @return void
	 */
	public function set_sql_mode( $modes = array(), $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return;
		}

		if ( empty( $modes ) ) {
			$res = mysqli_query( $dbh, 'SELECT @@SESSION.sql_mode' );

			if ( empty( $res ) ) {
				return;
			}

			$modes_array = mysqli_fetch_array( $res );
			if ( empty( $modes_array[0] ) ) {
				return;
			}

			$modes_str = $modes_array[0];

			if ( empty( $modes_str ) ) {
				return;
			}

			$modes = explode( ',', $modes_str );
		}

		$modes = array_change_key_case( $modes, CASE_UPPER );

		/**
		 * Filter the list of incompatible SQL modes to exclude.
		 *
		 * @param array $incompatible_modes An array of incompatible modes.
		 */
		$incompatible_modes = (array) apply_filters( 'incompatible_sql_modes', $this->incompatible_modes );
		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes, true ) ) {
				unset( $modes[ $i ] );
			}
		}

		$modes_str = implode( ',', $modes );

		if ( mysqli_query( $dbh, "SET SESSION sql_mode='{$modes_str}'" ) ) {
			$this->update_heartbeat( $dbh );
		}
	}

	/**
	 * Selects a database using the current database connection.
	 *
	 * The database name will be changed based on the current database
	 * connection. On failure, the execution will bail and display an DB error.
	 *
	 * @since 1.0.0
	 *
	 * @param string                       $db           MySQL database name.
	 * @param false|string|mysqli|resource $dbh          Optional. Database handle or, for backward
	 *                                                   compatibility, a table name.
	 * @param false|string|mysqli|resource $dbh_or_table Optional. Historical named-argument alias.
	 */
	public function select( $db, $dbh = null, $dbh_or_table = null ) {

		// Prefer the historical named argument when explicitly supplied.
		if ( null !== $dbh_or_table ) {
			$dbh = $dbh_or_table;
		}

		$dbh = $this->get_db_object( null === $dbh ? false : $dbh );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		$success = mysqli_select_db( $dbh, $db );

		if ( $success ) {
			$this->update_heartbeat( $dbh );
		}

		return $success;
	}

	/**
	 * Load the column metadata from the last query.
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 */
	protected function load_col_info() {

		// Bail if not enough info
		if (
			! empty( $this->col_info )
			||
			( false === $this->result )
		) {
			return;
		}

		$this->col_info = array();

		$num_fields = mysqli_num_fields( $this->result );

		for ( $i = 0; $i < $num_fields; $i++ ) {
			$this->col_info[ $i ] = mysqli_fetch_field( $this->result );
		}
	}

	/**
	 * Force addslashes() for the escapes
	 *
	 * LudicrousDB makes connections when a query is made which is why we can't
	 * use mysql_real_escape_string() for escapes
	 *
	 * This is also the reason why we don't allow certain charsets.
	 *
	 * See set_charset().
	 *
	 * @since 1.0.0
	 * @param string $data      String to escape.
	 * @param mixed  $to_escape Historical named-argument alias.
	 */
	public function _real_escape( $data = '', $to_escape = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

		// Preserve callers using the old $to_escape named argument.
		if ( null !== $to_escape ) {
			$data = $to_escape;
		}

		// Bail if not a scalar
		if ( ! is_scalar( $data ) ) {
			return '';
		}

		// Slash the query part
		$escaped = addslashes( $data );

		// Maybe use WordPress core placeholder method
		if ( method_exists( $this, 'add_placeholder_escape' ) ) {
			$escaped = $this->add_placeholder_escape( $escaped );
		}

		return $escaped;
	}

	/**
	 * Sets the connection's character set
	 *
	 * @since 1.0.0
	 *
	 * @param mysqli|resource $dbh     The resource given by mysqli_real_connect
	 * @param string          $charset Optional. The character set.
	 * @param string          $collate Optional. The collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		$use_defaults = ( null === $charset && null === $collate );

		// Default charset
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}

		// Default collation
		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}

		// An empty charset leaves the connection at its server default.
		if ( empty( $charset ) ) {
			if ( $use_defaults && $dbh instanceof mysqli ) {
				if ( ! $this->verify_default_connection_charset( $dbh ) ) {
					return false;
				}
				$this->remember_connection_charset( $dbh, $charset, $collate );
			}
			return;
		}

		// Exit if charset is not allowed
		if ( ! in_array( strtolower( $charset ), self::$allowed_charsets, true ) ) {
			wp_die( "{$charset} charset isn't supported in LudicrousDB for security reasons" );
		}

		// Bail if cannot set collation
		if ( ! $this->has_cap( 'collation', $dbh ) ) {
			return;
		}

		// Attempt to set the character set
		$do_set_names_query = $this->has_cap( 'set_charset', $dbh )
			? mysqli_set_charset( $dbh, $charset )
			: true;

		// Bail if client charset could not be set
		if ( false === $do_set_names_query ) {
			return false;
		}

		// Start the query with charset
		$query = $this->prepare( 'SET NAMES %s', $charset );

		// Maybe add collation to query
		if ( ! empty( $collate ) ) {
			$query .= $this->prepare( ' COLLATE %s', $collate );
		}

		// Do the query
		$set_names = $this->_do_query( $query, $dbh );
		if ( false === $set_names ) {
			return false;
		}
		if ( $set_names && $dbh instanceof mysqli ) {
			// An explicit per-link override survives until the object defaults change.
			$this->remember_connection_charset( $dbh, $this->charset, $this->collate );
		}
	}

	/**
	 * Check whether a MySQLi link has the current object charset settings.
	 *
	 * @since 5.3.1
	 *
	 * @param mysqli $dbh Database connection.
	 * @return bool Whether the cached settings match.
	 */
	private function is_connection_charset_current( $dbh ) {
		$key = spl_object_hash( $dbh );

		return isset( $this->connection_charsets[ $key ] )
			&& array( $this->charset, $this->collate ) === $this->connection_charsets[ $key ];
	}

	/**
	 * Refresh a cached link when the object's charset settings change.
	 *
	 * A busy link may be replaced or rerouted by connection recovery, so return
	 * the connection selected by that path rather than the original handle.
	 *
	 * @since 5.3.1
	 *
	 * @param string $dbhname Cached connection name.
	 * @param string $query   Query being routed.
	 * @return mysqli|resource|false Selected connection, or false on failure.
	 */
	private function refresh_cached_connection_charset( $dbhname, $query ) {
		// A drop-in may override these settings after the link was opened.
		$dbh = $this->dbhs[ $dbhname ];
		if ( ! ( $dbh instanceof mysqli ) || $this->is_connection_charset_current( $dbh ) ) {
			return $dbh;
		}

		// A charset update cannot use a link with an active result.
		if ( self::CONNECTION_AVAILABLE !== $this->get_connection_status( $dbh ) ) {
			if ( ! $this->check_connection( false, $dbh, $query ) ) {
				return false;
			}

			// Recovery may have replaced or rerouted the cached handle.
			if ( ! isset( $this->dbhs[ $dbhname ] ) || $this->dbhs[ $dbhname ] !== $dbh ) {
				return $this->dbh_type_check( $this->dbh ) ? $this->dbh : false;
			}
		}

		$this->dbh = $dbh; // Needed by wpdb::prepare().
		if ( false === $this->set_charset( $dbh ) ) {
			$this->disconnect( $dbhname );
			return false;
		}

		return $this->dbhs[ $dbhname ];
	}

	/**
	 * Remember the object charset settings applied to a MySQLi link.
	 *
	 * @since 5.3.1
	 *
	 * @param mysqli $dbh     Database connection.
	 * @param string $charset Charset applied to the connection.
	 * @param string $collate Collation applied to the connection.
	 * @return void
	 */
	private function remember_connection_charset( $dbh, $charset, $collate ) {
		$this->connection_charsets[ spl_object_hash( $dbh ) ] = array( $charset, $collate );
	}

	/**
	 * Forget charset settings when a MySQLi link is replaced or closed.
	 *
	 * @since 5.3.1
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	private function forget_connection_charset( $dbh ) {
		if ( $dbh instanceof mysqli ) {
			unset( $this->connection_charsets[ spl_object_hash( $dbh ) ] );
		}
	}

	/**
	 * Verify that an unconfigured MySQLi link uses an allowed client and session charset.
	 *
	 * @since 5.3.1
	 *
	 * @param mysqli $dbh Database connection.
	 * @return bool Whether the session could be inspected.
	 */
	private function verify_default_connection_charset( $dbh ) {
		// SET NAMES can change the server session without changing MySQLi's
		// client-library charset. Check both sides before trusting defaults.
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Inspect the active session before trusting an empty charset setting.
		$session_result = mysqli_query( $dbh, 'SELECT @@character_set_client AS client_charset, @@character_set_connection AS connection_charset' );
		if ( ! ( $session_result instanceof mysqli_result ) ) {
			return false;
		}

		$session_charsets = mysqli_fetch_assoc( $session_result );
		mysqli_free_result( $session_result );
		if ( ! is_array( $session_charsets ) ) {
			return false;
		}

		$charsets = array(
			mysqli_character_set_name( $dbh ),
			$session_charsets['client_charset'],
			$session_charsets['connection_charset'],
		);
		foreach ( $charsets as $current_charset ) {
			$current_charset = is_string( $current_charset ) ? strtolower( $current_charset ) : '';
			if ( 'utf8mb3' === $current_charset ) {
				$current_charset = 'utf8';
			}
			if ( ! in_array( $current_charset, self::$allowed_charsets, true ) ) {
				wp_die( 'The database connection charset is not supported in LudicrousDB for security reasons.' );
			}
		}

		return true;
	}

	/**
	 * Disconnect and remove connection from open connections list
	 *
	 * @since 1.0.0
	 *
	 * @param string $dbhname Database name.
	 */
	public function disconnect( $dbhname ) {
		$key = array_search( $dbhname, $this->open_connections, true );
		if ( false !== $key ) {
			unset( $this->open_connections[ $key ] );
		}

		if ( ! isset( $this->dbhs[ $dbhname ] ) ) {
			return;
		}

		$dbh = $this->dbhs[ $dbhname ];
		if ( ! $this->dbh_type_check( $dbh ) ) {
			unset( $this->dbhs[ $dbhname ] );

			return;
		}

		$this->release_connection_aliases( $dbh );
		$this->close( $dbh );
	}

	/**
	 * Remove every cached routing name for a handle without closing it.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	protected function release_connection_aliases( $dbh ) {
		foreach ( $this->dbhs as $other_dbhname => $other_dbh ) {
			if ( $dbh !== $other_dbh ) {
				continue;
			}

			$key = array_search( $other_dbhname, $this->open_connections, true );
			if ( false !== $key ) {
				unset( $this->open_connections[ $key ] );
			}

			unset( $this->dbhs[ $other_dbhname ] );
		}

		if ( $this->dbh === $dbh ) {
			$this->dbh = null;
		}
	}

	/**
	 * Kill cached query results
	 *
	 * @since 1.0.0
	 */
	public function flush() {
		$this->last_error = '';
		$this->num_rows   = 0;
		parent::flush();
	}

	/**
	 * Check that the connection to the database is still up. If not, try
	 * to reconnect.
	 *
	 * This function is called internally by LudicrousDB when a database
	 * connection
	 *
	 * If this function is unable to reconnect, it will forcibly die, or if
	 * after the "template_redirect" hook has been fired, return false instead.
	 *
	 * If $die_on_disconnect is false, the lack of database connection will need
	 * to be handled manually.
	 *
	 * @since 1.0.0
	 *
	 * @param bool                         $allow_bail        Optional. Allows the function to bail. Default true.
	 * @param false|string|mysqli|resource $dbh_or_table      Optional. Database handle or table name.
	 * @param string                       $query             Optional. Query string passed to db_connect().
	 * @param bool|null                    $die_on_disconnect Historical named-argument alias.
	 *
	 * @return bool|void True if the connection is up.
	 */
	public function check_connection( $allow_bail = true, $dbh_or_table = false, $query = '', $die_on_disconnect = null ) {

		// Preserve callers using the old $die_on_disconnect named argument.
		if ( null !== $die_on_disconnect ) {
			$allow_bail = $die_on_disconnect;
		}

		$dbh = $this->get_db_object( $dbh_or_table );

		// Return true if connection is alive and immediately reusable. This is the most common case.
		if ( $this->dbh_type_check( $dbh ) ) {
			$connection_status = $this->get_connection_status( $dbh );

			if ( self::CONNECTION_AVAILABLE === $connection_status ) {
				$this->clear_busy_connection_probe( $dbh );
				$this->update_heartbeat( $dbh );

				return true;
			}

			if ( self::CONNECTION_BUSY === $connection_status ) {
				// Preserve the active result while replacing the cached busy handle.
				$this->preserve_busy_connection( $dbh );
				$this->release_connection_aliases( $dbh );
				$this->clear_busy_connection_probe( $dbh );
			} else {
				$this->clear_busy_connection_probe( $dbh );

				// Remove the stale handle before db_connect() attempts a replacement.
				$dbhname = $this->lookup_dbhs_name( $dbh );
				if ( false !== $dbhname ) {
					$this->disconnect( $dbhname );
				} elseif ( $this->dbh === $dbh ) {
					$this->dbh = null;
					$this->close( $dbh );
				}
			}
		}

		// Default to false
		$error_reporting = false;

		// Disable warnings, as we don't want to see a multitude of "unable to connect" messages
		if ( $this->is_debug() ) {
			$error_reporting = error_reporting();
			error_reporting( $error_reporting & ~E_WARNING );
		}

		// Ping failed, so try to reconnect manually
		for ( $tries = 1; $tries <= $this->reconnect_retries; $tries++ ) {

			// Try to reconnect
			$retry = $this->db_connect( false, $query );

			// Return true if the connection is up
			if ( false !== $retry ) {
				return true;

				// On the last try, re-enable warnings. We want to see a single
				// instance of the "unable to connect" message on the bail()
				// screen, if it appears.
			} elseif (
				( $this->reconnect_retries === $tries )
				&&
				$this->is_debug()
			) {
				error_reporting( $error_reporting );
			}

			// Sleep before retrying
			usleep( (int) ( $this->reconnect_sleep * 1000000 ) );
		}

		// Bail here if not allowed to call $this->bail()
		if ( false === $allow_bail ) {
			return false;
		}

		// Bail if template_redirect has already happened, because it's too
		// late for wp_die()/dead_db()
		if ( did_action( 'template_redirect' ) ) {
			return false;
		}

		// Load translations early so that the error message can be translated
		wp_load_translations_early();

		$message  = '<h1>' . __( 'Error reconnecting to the database', 'ludicrousdb' ) . "</h1>\n";
		$message .= '<p>' . sprintf(
			/* translators: %s: database host */
				__( 'This means that we lost contact with the database server at %s. This could mean your host&#8217;s database server is down.', 'ludicrousdb' ),
				'<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
			) . "</p>\n";
		$message .= "<ul>\n";
		$message .= '<li>' . __( 'Are you sure that the database server is running?', 'ludicrousdb' ) . "</li>\n";
		$message .= '<li>' . __( 'Are you sure that the database server is not under particularly heavy load?', 'ludicrousdb' ) . "</li>\n";
		$message .= "</ul>\n";
		$message .= '<p>' . sprintf(
			/* translators: %s: support forums URL */
				__( 'If you&#8217;re unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress Support Forums</a>.', 'ludicrousdb' ),
				__( 'https://wordpress.org/support/', 'ludicrousdb' )
			) . "</p>\n";

		// We weren't able to reconnect, so we better bail.
		$this->bail( $message, 'db_connect_fail' );

		// Call dead_db() if bail didn't die, because this database is no more.
		// It has ceased to be (at least temporarily).
		dead_db();
	}

	/**
	 * Return the current usability status of a MySQL connection.
	 *
	 * The mysqli_ping() function is deprecated as of PHP 8.4. A harmless statement provides
	 * the same liveness check without relying on the deprecated API or a stale
	 * error code left by an earlier query.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return int One of the CONNECTION_* status constants.
	 */
	protected function get_connection_status( $dbh ) {
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed probe is handled as a reconnect signal.
			$responded = false !== @mysqli_query( $dbh, 'DO 1' );

			if ( $responded ) {
				return self::CONNECTION_AVAILABLE;
			}

			// An active unbuffered or multi-result query makes the handle busy, not dead.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A closed PHP 7.4 handle warns while reporting its error code.
			return self::MYSQL_COMMANDS_OUT_OF_SYNC === (int) @mysqli_errno( $dbh )
				? self::CONNECTION_BUSY
				: self::CONNECTION_DEAD;
		} catch ( mysqli_sql_exception $exception ) {
			return self::MYSQL_COMMANDS_OUT_OF_SYNC === (int) $exception->getCode()
				? self::CONNECTION_BUSY
				: self::CONNECTION_DEAD;
		} catch ( Throwable $exception ) {
			return self::CONNECTION_DEAD;
		}
	}

	/**
	 * Actively verify that a MySQL connection is alive.
	 *
	 * This boolean convenience wrapper retains one grace probe for a live handle
	 * with pending results. Internal routing uses
	 * get_connection_status() so that a busy handle is never mistaken for an
	 * immediately reusable one.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return bool Whether the connection is alive.
	 */
	protected function is_connection_alive( $dbh ) {
		$status = $this->get_connection_status( $dbh );

		if ( self::CONNECTION_AVAILABLE === $status ) {
			$this->clear_busy_connection_probe( $dbh );

			return true;
		}

		if ( self::CONNECTION_BUSY === $status ) {
			return $this->handle_connection_probe_failure( $dbh, self::MYSQL_COMMANDS_OUT_OF_SYNC );
		}

		$this->clear_busy_connection_probe( $dbh );

		return false;
	}

	/**
	 * Allow one busy probe before treating a repeatedly blocked handle as stale.
	 *
	 * A single grace probe avoids closing a connection that still has a legitimate
	 * unbuffered or multi-query result. If the same handle remains blocked on the
	 * next probe, the normal reconnect path can recover it instead of retaining an
	 * abandoned result set indefinitely.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh   Database connection.
	 * @param int             $errno MySQL client error number.
	 * @return bool Whether the busy handle should receive its grace probe.
	 */
	protected function handle_connection_probe_failure( $dbh, $errno ) {
		$key = $this->connection_probe_key( $dbh );

		if ( self::MYSQL_COMMANDS_OUT_OF_SYNC !== (int) $errno ) {
			$this->clear_busy_connection_probe( $dbh );

			return false;
		}

		if ( false === $key ) {
			return false;
		}

		if ( $this->has_busy_connection_probe( $dbh ) ) {
			return false;
		}

		$this->busy_connection_probes[ $key ] = is_object( $dbh )
			? WeakReference::create( $dbh )
			: true;

		return true;
	}

	/**
	 * Clear a handle's busy-probe grace after it responds normally.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	protected function clear_busy_connection_probe( $dbh ) {
		$key = $this->connection_probe_key( $dbh );

		if ( false !== $key ) {
			unset( $this->busy_connection_probes[ $key ] );
		}
	}

	/**
	 * Whether the handle currently owns a busy-probe grace marker.
	 *
	 * Weak references prevent a recycled object ID from inheriting state from a
	 * handle that was released without an explicit close.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return bool Whether the handle is marked busy.
	 */
	protected function has_busy_connection_probe( $dbh ) {
		$key = $this->connection_probe_key( $dbh );

		if ( false === $key || ! isset( $this->busy_connection_probes[ $key ] ) ) {
			return false;
		}

		$marker = $this->busy_connection_probes[ $key ];

		return $marker instanceof WeakReference
			? $dbh === $marker->get()
			: true;
	}

	/**
	 * Return a stable request-local key for a database handle.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return string|false Handle key, or false for an invalid handle.
	 */
	private function connection_probe_key( $dbh ) {
		if ( is_object( $dbh ) ) {
			return 'object:' . spl_object_id( $dbh );
		}

		if ( is_resource( $dbh ) ) {
			return 'resource:' . (int) $dbh;
		}

		return false;
	}

	/**
	 * Retain a detached busy handle until explicit close or request shutdown.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	private function preserve_busy_connection( $dbh ) {
		$key = $this->connection_probe_key( $dbh );

		if ( false !== $key ) {
			$this->detached_busy_connections[ $key ] = $dbh;
		}
	}

	/**
	 * Stop retaining a detached handle that is being closed explicitly.
	 *
	 * @since 5.4.0
	 *
	 * @param mysqli|resource $dbh Database connection.
	 * @return void
	 */
	private function release_busy_connection( $dbh ) {
		$key = $this->connection_probe_key( $dbh );

		if ( false !== $key ) {
			unset( $this->detached_busy_connections[ $key ] );
		}
	}

	/**
	 * Basic query. See documentation for more details.
	 *
	 * @since 1.0.0
	 * @since 5.2.0 Added support for SELECT modifiers (e.g. DISTINCT, HIGH_PRIORITY, etc...)
	 *
	 * @param string $query Query.
	 *
	 * @return int number of rows
	 */
	public function query( $query ) {

		// Default return value
		$retval = 0;

		$this->flush();

		// Some queries are made before plugins are loaded
		if ( function_exists( 'apply_filters' ) ) {

			/**
			 * Filter the database query.
			 *
			 * Some queries are made before the plugins have been loaded,
			 * and thus cannot be filtered with this method.
			 *
			 * @param string $query Database query.
			 */
			$query = apply_filters( 'query', $query );
		}

		// Some queries are made before plugins are loaded
		if ( function_exists( 'apply_filters_ref_array' ) ) {

			/**
			 * Filter the return value before the query is run.
			 *
			 * Passing a non-null value to the filter will effectively short-circuit
			 * the DB query, stopping it from running, then returning this value instead.
			 *
			 * This uses apply_filters_ref_array() to allow $this to be manipulated, so
			 * values can be set just-in-time to match your particular use-case.
			 *
			 * You probably will never need to use this filter, but if you do, there's
			 * no other way to do what you're trying to do, so here you go!
			 *
			 * @since 4.0.0
			 *
			 * @param string      null   The filtered return value. Default is null.
			 * @param string $query Database query.
			 * @param LudicrousDB &$this Current instance of LudicrousDB, passed by reference.
			 */
			$retval = apply_filters_ref_array( 'pre_query', array( null, $query, &$this ) );
			if ( null !== $retval ) {
				$this->run_query_log_callbacks( $query, $retval );

				return $retval;
			}
		}

		// Bail if query is empty (via application error or 'query' filter)
		if ( empty( $query ) ) {
			$this->run_query_log_callbacks( $query, $retval );

			return $retval;
		}

		// Log how the function was called
		$this->func_call = "\$db->query(\"{$query}\")";

		// If we're writing to the database, make sure the query will write safely.
		if (
			$this->check_current_query
			&&
			! $this->check_ascii( $query )
		) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );

			// strip_invalid_text_from_query() may perform queries, so
			// flush again to make sure everything is clear.
			$this->flush();

			if ( $stripped_query !== $query ) {
				$this->insert_id = 0;
				$retval          = false;

				$this->run_query_log_callbacks( $query, $retval );

				return $retval;
			}
		}

		$this->check_current_query = true;

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if (
			preg_match( '/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query )
			&&
			( $this->last_found_rows_result instanceof mysqli_result )
		) {
			$this->result                 = $this->last_found_rows_result;
			$this->last_found_rows_result = null;
			$elapsed                      = 0;
		} else {
			$this->dbh = $this->db_connect( $query );

			if ( ! $this->dbh_type_check( $this->dbh ) ) {
				$this->run_query_log_callbacks( $query, $retval );

				return false;
			}

			$this->timer_start();
			$this->result = $this->_do_query( $query, $this->dbh );
			$elapsed      = $this->timer_stop();

			++$this->num_queries;

			$mysql_errno = mysqli_errno( $this->dbh );
			if ( $mysql_errno && ! empty( $this->check_dbh_heartbeats ) ) {
				$dbhname = $this->lookup_dbhs_name( $this->dbh );

				if ( ! empty( $dbhname ) ) {
					$this->dbhname_heartbeats[ $dbhname ]['last_errno'] = $mysql_errno;
				}
			}

			// retry the server and all other servers if the connection went away
			if ( in_array( $mysql_errno, array( DB_SERVER_GONE_ERROR, DB_SERVER_LOST_ERROR ), true ) ) {
				return $this->query( $query );
			}

			if ( preg_match( '/^\s*SELECT\s+([A-Z_]+\s+)*SQL_CALC_FOUND_ROWS\s/i', $query ) && false !== $this->result ) {
				if ( false === strpos( $query, 'NO_SELECT_FOUND_ROWS' ) ) {
					$this->timer_start();
					$this->last_found_rows_result = $this->_do_query( 'SELECT FOUND_ROWS()', $this->dbh );
					$elapsed                     += $this->timer_stop();
					++$this->num_queries;
					$query .= '; SELECT FOUND_ROWS()';
				} else {
					$this->last_found_rows_result = null;
				}
			} else {
				$this->last_found_rows_result = null;
			}

			if (
				! empty( $this->save_queries )
				||
				$this->is_saving_queries()
			) {
				$this->log_query(
					$query,
					$elapsed,
					$this->get_caller(),
					$this->time_start,
					array()
				);
			}
		}

		// If there is an error then take note of it
		if ( $this->dbh_type_check( $this->dbh ) ) {
			$this->last_error = mysqli_error( $this->dbh );
		}

		if ( ! empty( $this->last_error ) ) {
			// Clear insert_id on a subsequent failed insert.
			if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				$this->insert_id = 0;
			}

			$this->print_error( $this->last_error );
			$retval = false;

			$this->run_query_log_callbacks( $query, $retval );

			return $retval;
		}

		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			$retval = $this->result;

		} elseif ( preg_match( '/^\s*(insert|delete|update|replace|alter) /i', $query ) ) {
			$this->rows_affected = mysqli_affected_rows( $this->dbh );

			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				$this->insert_id = mysqli_insert_id( $this->dbh );
			}

			// Return number of rows affected
			$retval = $this->rows_affected;

		} else {
			$num_rows          = 0;
			$this->last_result = array();

			if ( $this->result instanceof mysqli_result ) {
				$this->load_col_info();

				while ( $row = mysqli_fetch_object( $this->result ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					$this->last_result[ $num_rows ] = $row;

					// phpcs:ignore
					$num_rows++;
				}
			}

			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$retval         = $num_rows;
		}

		$this->run_query_log_callbacks( $query, $retval );

		// Some queries are made before plugins are loaded
		if ( function_exists( 'do_action_ref_array' ) ) {

			/**
			 * Runs after a query is finished.
			 *
			 * @since 4.0.0
			 *
			 * @param string $query Database query.
			 * @param LudicrousDB &$this Current instance of LudicrousDB, passed by reference.
			 */
			do_action_ref_array( 'queried', array( $query, &$this ) );
		}

		// Return number of rows
		return $retval;
	}

	/**
	 * Internal function to perform the mysqli_query() call
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @see wpdb::query()
	 *
	 * @param  string $query The query to run.
	 * @param  bool   $dbh_or_table  Database or table name. Defaults to false.
	 * @throws Throwable If the query fails.
	 */
	protected function _do_query( $query, $dbh_or_table = false ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		// Try to execute the query
		try {
			$result = mysqli_query( $dbh, $query );

			// Catch any exceptions
		} catch ( Throwable $exception ) {
			if ( true === $this->suppress_errors ) {
				$result = false;
			} else {
				throw $exception;
			}
		}

		$this->update_heartbeat( $dbh );

		return $result;
	}

	/**
	 * Closes the current database connection.
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @throws Error If mysqli_close() reports an error other than an already-closed handle.
	 *
	 * @return bool True if the connection was successfully closed. False if it wasn't
	 *              or the connection doesn't exist.
	 */
	public function close( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		$this->clear_busy_connection_probe( $dbh );
		$this->release_busy_connection( $dbh );
		$this->forget_connection_charset( $dbh );

		$already_closed         = false;
		$previous_error_handler = null;

		// PHP 7.4 emits a warning when a stale mysqli object is already closed.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Normalize PHP 7.4's warning with PHP 8's exception for this call only.
		$previous_error_handler = set_error_handler(
			static function ( $error_level, $error_message, $error_file, $error_line ) use ( &$already_closed, &$previous_error_handler ) {
				if ( false !== strpos( $error_message, "mysqli_close(): Couldn't fetch mysqli" ) ) {
					$already_closed = true;
					return true;
				}

				if ( is_callable( $previous_error_handler ) ) {
					return (bool) call_user_func( $previous_error_handler, $error_level, $error_message, $error_file, $error_line );
				}

				return false;
			},
			E_WARNING
		);

		try {
			$closed = mysqli_close( $dbh );
		} catch ( Error $exception ) {
			if ( 'mysqli object is already closed' !== $exception->getMessage() ) {
				throw $exception;
			}

			$already_closed = true;
			$closed         = false;
		} finally {
			restore_error_handler();
		}

		if ( ! empty( $closed ) || $already_closed ) {
			$this->dbh = null;
		}

		return $closed;
	}

	/**
	 * Whether or not MySQL database is at least the required minimum version.
	 * The additional argument allows the caller to check a specific database.
	 *
	 * @since 1.0.0
	 *
	 * @global $wp_version
	 * @global $required_mysql_version
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @return WP_Error
	 */
	public function check_database_version( $dbh_or_table = false ) {
		global $wp_version, $required_mysql_version;

		// Make sure the server has the required MySQL version
		$mysql_version = preg_replace( '|[^0-9\.]|', '', $this->db_version( $dbh_or_table ) );
		if ( version_compare( $mysql_version, $required_mysql_version, '<' ) ) {
			// translators: 1. WordPress version, 2. MySql Version.
			return new WP_Error( 'database_version', sprintf( __( '<strong>ERROR</strong>: WordPress %1$s requires MySQL %2$s or higher', 'ludicrousdb' ), $wp_version, $required_mysql_version ) );
		}
	}

	/**
	 * This function is called when WordPress is generating the table schema to
	 * determine whether or not the current database supports or needs the
	 * collation statements.
	 *
	 * The additional argument allows the caller to check a specific database.
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @return bool
	 */
	public function supports_collation( $dbh_or_table = false ) {
		_deprecated_function( __FUNCTION__, '3.5', 'wpdb::has_cap( \'collation\' )' );

		return $this->has_cap( 'collation', $dbh_or_table );
	}

	/**
	 * Generic function to determine if a database supports a particular feature.
	 * The additional argument allows the caller to check a specific database.
	 *
	 * @since 1.0.0
	 *
	 * @param string                       $db_cap       The feature.
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @return bool
	 */
	public function has_cap( $db_cap, $dbh_or_table = false ) {

		// This capability belongs to wpdb::prepare(), not to a database server.
		if ( 'identifier_placeholders' === strtolower( $db_cap ) ) {
			return parent::has_cap( $db_cap );
		}

		$db_version     = $this->db_version( $dbh_or_table );
		$db_server_info = $this->db_server_info( $dbh_or_table );

		// Account for MariaDB version being prefixed with '5.5.5-' on older PHP versions.
		// See: https://github.com/Automattic/HyperDB/pull/143
		if (
			( '5.5.5' === $db_version )
			&&
			( false !== strpos( $db_server_info, 'MariaDB' ) )
			&&
			(
				PHP_VERSION_ID <= 80015 // PHP 8.0.15 or older.
				||
				( 80100 <= PHP_VERSION_ID && PHP_VERSION_ID <= 80102 ) // PHP 8.1.0 to PHP 8.1.2.
			)
		) {
			// Strip the '5.5.5-' prefix and set the version to the correct value.
			$db_server_info = preg_replace( '/^5\.5\.5-(.*)/', '$1', $db_server_info );
			$db_version     = preg_replace( '/[^0-9.].*/', '', $db_server_info );
		}

		switch ( strtolower( $db_cap ) ) {
			case 'collation':    // @since WP 2.5.0
			case 'group_concat': // @since WP 2.7.0
			case 'subqueries':   // @since WP 2.7.0
				return version_compare( $db_version, '4.1', '>=' );
			case 'set_charset':
				return version_compare( $db_version, '5.0.7', '>=' );
			case 'utf8mb4':      // @since WP 4.1.0
				if ( version_compare( $db_version, '5.5.3', '<' ) ) {
					return false;
				}

				$dbh = $this->get_db_object( $dbh_or_table );

				if ( $this->dbh_type_check( $dbh ) ) {
					$client_version = mysqli_get_client_info();

					/*
					 * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
					 * mysqlnd has supported utf8mb4 since 5.0.9.
					 */
					if ( false !== strpos( $client_version, 'mysqlnd' ) ) {
						$client_version = preg_replace( '/^\D+([\d.]+).*/', '$1', $client_version );

						return version_compare( $client_version, '5.0.9', '>=' );
					} else {
						return version_compare( $client_version, '5.5.3', '>=' );
					}
				}
				break;
			case 'utf8mb4_520': // @since WP 4.6.0
				return version_compare( $db_version, '5.6', '>=' );
		}

		return false;
	}

	/**
	 * Retrieve the name of the function that called wpdb.
	 *
	 * Searches up the list of functions until it reaches
	 * the one that would most logically had called this method.
	 *
	 * @since 2.5.0
	 *
	 * @return string Comma separated list of the calling functions.
	 */
	public function get_caller() {
		return ( true === $this->save_backtrace )
			? wp_debug_backtrace_summary( __CLASS__ )
			: null;
	}

	/**
	 * The database version number.
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @return false|string False on failure. Version number on success.
	 */
	public function db_version( $dbh_or_table = false ) {
		$server_info = $this->db_server_info( $dbh_or_table );

		return $server_info ? preg_replace( '/[^0-9.].*/', '', $server_info, 1 ) : false;
	}

	/**
	 * Retrieves full MySQL server information.
	 *
	 * @since 5.0.0
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 *
	 * @return string|false Server info on success, false on failure.
	 */
	public function db_server_info( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		$server_info = mysqli_get_server_info( $dbh );

		$this->update_heartbeat( $dbh );

		return $server_info;
	}

	/**
	 * Get the db connection object
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|mysqli|resource $dbh_or_table Optional. The database. One of:
	 *                                                   - the current database
	 *                                                   - the database housing the specified table
	 *                                                   - the database of the MySQL resource
	 * @return bool|mysqli|resource
	 */
	private function get_db_object( $dbh_or_table = false ) {

		// No database
		$dbh = false;

		// Database
		if ( $this->dbh_type_check( $dbh_or_table ) ) {
			$dbh = &$dbh_or_table;

			// Database
		} elseif (
			( false === $dbh_or_table )
			&&
			$this->dbh_type_check( $this->dbh )
		) {
			$dbh = &$this->dbh;

			// Table name
		} elseif ( is_string( $dbh_or_table ) ) {
			$dbh = $this->db_connect( "SELECT FROM {$dbh_or_table} {$this->users}" );
		}

		return $dbh;
	}

	/**
	 * Check database object type.
	 *
	 * @since 1.0.0
	 *
	 * @param mysqli|resource $dbh Database resource.
	 *
	 * @return bool
	 */
	private function dbh_type_check( $dbh ) {
		if ( $dbh instanceof mysqli ) {
			return true;
		} elseif ( is_resource( $dbh ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Logs query data.
	 *
	 * @since 4.3.0
	 *
	 * @param string $query           The query's SQL.
	 * @param float  $query_time      Total time spent on the query, in seconds.
	 * @param string $query_callstack Comma separated list of the calling functions.
	 * @param float  $query_start     Unix timestamp of the time at the start of the query.
	 * @param array  $query_data      Custom query data.
	 * }
	 */
	public function log_query( $query = '', $query_time = 0, $query_callstack = '', $query_start = 0, $query_data = array() ) {

		/**
		 * Filters the custom query data being logged.
		 *
		 * Caution should be used when modifying any of this data, it is recommended that any additional
		 * information you need to store about a query be added as a new associative entry to the fourth
		 * element $query_data.
		 *
		 * @since 5.3.0
		 *
		 * @param array  $query_data      Custom query data.
		 * @param string $query           The query's SQL.
		 * @param float  $query_time      Total time spent on the query, in seconds.
		 * @param string $query_callstack Comma separated list of the calling functions.
		 * @param float  $query_start     Unix timestamp of the time at the start of the query.
		 */
		$query_data = apply_filters( 'log_query_custom_data', $query_data, $query, $query_time, $query_callstack, $query_start );

		// Pass to custom callback...
		if ( is_callable( $this->save_query_callback ) ) {
			$this->queries[] = call_user_func_array(
				$this->save_query_callback,
				array(
					$query,
					$query_time,
					$query_callstack,
					$query_start,
					$query_data,
					&$this,
				)
			);

			// ...or save it to the queries array
		} else {
			$this->queries[] = array(
				$query,
				$query_time,
				$query_callstack,
				$query_start,
				$query_data,
			);
		}
	}

	/**
	 * Check the responsiveness of a TCP/IP daemon
	 *
	 * @since 1.0.0
	 *
	 * @param string $host          Host.
	 * @param int    $port          Port.
	 * @param float  $float_timeout Timeout in seconds.
	 * @param string $socket        Optional. Unix socket path.
	 *
	 * @return bool True when the database endpoint responds within the timeout, otherwise false.
	 */
	public function check_tcp_responsiveness( $host, $port, $float_timeout, $socket = '' ) {

		// Honor raw host strings passed by integrations calling this method directly.
		list( $host, $port, $parsed_socket, $is_ipv6 ) = $this->parse_database_host( $host, $port );

		if ( empty( $socket ) ) {
			$socket = $parsed_socket;
		}

		if ( empty( $this->check_tcp_responsiveness ) ) {
			$this->tcp_responsive = true;

			return true;
		}

		// Get the cache key
		$cache_key = $this->tcp_get_cache_key( $host, $port, $socket );

		// Persistent cached value exists
		$cached_value = $this->tcp_cache_get( $cache_key );

		// Confirmed up or down response
		if ( 'up' === $cached_value ) {
			$this->tcp_responsive = true;

			return true;
		} elseif ( 'down' === $cached_value ) {
			$this->tcp_responsive = false;

			return false;
		}

		// Defaults
		$errno  = 0;
		$errstr = '';
		$target = empty( $socket )
			? ( $is_ipv6 ? "tcp://[{$host}]" : $host )
			: 'unix://' . $socket;
		$port   = empty( $socket ) ? $port : -1;

		// Try to get a new socket
		// phpcs:disable
		$connection = $this->is_debug()
			? fsockopen( $target, $port, $errno, $errstr, $float_timeout )
			: @fsockopen( $target, $port, $errno, $errstr, $float_timeout );
		// phpcs:enable

		// No socket
		if ( false === $connection ) {
			$this->tcp_cache_set( $cache_key, 'down' );
			$this->tcp_responsive = false;

			return "[ > {$float_timeout} ] ({$errno}) '{$errstr}'";
		}

		// Close the socket
		// phpcs:ignore
		fclose( $connection );

		// Using API
		$this->tcp_cache_set( $cache_key, 'up' );
		$this->tcp_responsive = true;

		return true;
	}

	/**
	 * Run lag cache callbacks and return current lag
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function get_lag_cache() {
		$this->lag = $this->run_callbacks( 'get_lag_cache' );

		return $this->check_lag();
	}

	/**
	 * Run query log callbacks and return the return value.
	 *
	 * @since 5.2.0
	 *
	 * @param string $query The query's SQL.
	 * @param mixed  $retval The return value of the query.
	 *
	 * @return void
	 */
	public function run_query_log_callbacks( $query = '', $retval = null ) {

		// Setup the callback data
		$callback_data = array(
			$query,
			$retval,
			$this->last_error,
		);

		// Run the callbacks
		$this->run_callbacks( 'sql_query_log', $callback_data );
	}

	/**
	 * Should we try to ping the MySQL host?
	 *
	 * @since 4.1.0
	 *
	 * @param string $dbhname Database name.
	 *
	 * @return bool True if we should try to ping the MySQL host, false otherwise.
	 */
	public function should_mysql_ping( $dbhname = '' ) {
		if ( empty( $dbhname ) ) {
			return false;
		}

		if (
			empty( $this->check_dbh_heartbeats )
			&&
			isset( $this->dbhs[ $dbhname ] )
			&&
			$this->dbh_type_check( $this->dbhs[ $dbhname ] )
			&&
			in_array( mysqli_errno( $this->dbhs[ $dbhname ] ), array( DB_SERVER_GONE_ERROR, DB_SERVER_LOST_ERROR ), true )
		) {
			return true;
		}

		if ( empty( $this->check_dbh_heartbeats ) ) {
			return false;
		}

		// Return true if no heartbeat yet
		if ( empty( $this->dbhname_heartbeats[ $dbhname ] ) ) {
			return true;
		}

		// Return true if last error is a down server
		if (
			! empty( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] )
			&&
			in_array( $this->dbhname_heartbeats[ $dbhname ]['last_errno'], array( DB_SERVER_GONE_ERROR, DB_SERVER_LOST_ERROR ), true )
		) {

			// Also clear the last error
			unset( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] );

			return true;
		}

		// Return true if last used is older than recheck timeout
		if ( ( microtime( true ) - $this->dbhname_heartbeats[ $dbhname ]['last_used'] ) > $this->recheck_timeout ) {
			return true;
		}

		// Default to false
		return false;
	}

	/**
	 * Run lag callbacks and return current lag
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function get_lag() {
		$this->lag = $this->run_callbacks( 'get_lag' );

		return $this->check_lag();
	}

	/**
	 * Check lag
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function check_lag() {
		if ( false === $this->lag ) {
			return DB_LAG_UNKNOWN;
		}

		if ( $this->lag > $this->lag_threshold ) {
			return DB_LAG_BEHIND;
		}

		return DB_LAG_OK;
	}

	/**
	 * Retrieves the character set for a database table.
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so
	 *       that wpdb::dbh is set correctly.
	 *
	 * @param string $table Table name
	 *
	 * @return mixed The table character set, or WP_Error if we couldn't find it
	 */
	protected function get_table_charset( $table ) {
		$tablekey = strtolower( $table );

		/**
		 * Filter the table charset value before the DB is checked.
		 *
		 * Passing a non-null value to the filter will effectively short-circuit
		 * checking the DB for the charset, returning that value instead.
		 *
		 * @param string $charset The character set to use. Default null.
		 * @param string $table The name of the table being checked.
		 */
		$charset = apply_filters( 'pre_get_table_charset', null, $table );
		if ( null !== $charset ) {
			return $charset;
		}

		if ( isset( $this->table_charset[ $tablekey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		$charsets = $columns = array();

		$table_parts = explode( '.', $table );
		$table       = '`' . implode( '`.`', $table_parts ) . '`';
		$results     = $this->get_results( "SHOW FULL COLUMNS FROM {$table}" );

		if ( empty( $results ) ) {
			return new WP_Error( 'wpdb_get_table_charset_failure' );
		}

		foreach ( $results as $column ) {
			$columns[ strtolower( $column->Field ) ] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->col_meta[ $tablekey ] = $columns;

		foreach ( $columns as $column ) {
			if ( ! empty( $column->Collation ) ) {  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				list( $charset ) = explode( '_', $column->Collation );  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				// If the current connection can't support utf8mb4 characters, let's only send 3-byte utf8 characters.
				if (
					( 'utf8mb4' === $charset )
					&&
					! $this->has_cap( 'utf8mb4' )
				) {
					$charset = 'utf8';
				}

				$charsets[ strtolower( $charset ) ] = true;
			}

			list( $type ) = explode( '(', $column->Type );  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			// A binary/blob means the whole query gets treated like this.
			if ( in_array( strtoupper( $type ), self::$bin_blobs, true ) ) {
				$this->table_charset[ $tablekey ] = 'binary';

				return 'binary';
			}
		}

		// utf8mb3 is an alias for utf8.
		if ( isset( $charsets['utf8mb3'] ) ) {
			$charsets['utf8'] = true;
			unset( $charsets['utf8mb3'] );
		}

		// Check if there is more than one charset in play.
		$count = count( $charsets );
		if ( 1 === $count ) {
			$charset = key( $charsets );

			// No charsets, assume this table can store whatever.
		} elseif ( 0 === $count ) {
			$charset = false;

			// More than one charset. Remove latin1 if present and recalculate.
		} else {
			unset( $charsets['latin1'] );
			$count = count( $charsets );

			// Only one charset (besides latin1).
			if ( 1 === $count ) {
				$charset = key( $charsets );

				// Two charsets, but they're utf8 and utf8mb4, use utf8.
			} elseif ( 2 === $count && isset( $charsets['utf8'], $charsets['utf8mb4'] ) ) {
				$charset = 'utf8';

				// Two mixed character sets. ascii.
			} else {
				$charset = 'ascii';
			}
		}

		$this->table_charset[ $tablekey ] = $charset;

		return $charset;
	}

	/**
	 * Given a string, a character set and a table, ask the DB to check the
	 * string encoding. Classes that extend wpdb can override this function
	 * without needing to copy/paste all of wpdb::strip_invalid_text().
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so
	 *       that wpdb::dbh is set correctly.
	 *
	 * @since 1.0.0
	 *
	 * @param string $to_strip String to convert
	 * @param string $charset Character set to test against (uses MySQL character set names)
	 *
	 * @return mixed The converted string, or a WP_Error if the conversion fails
	 */
	protected function strip_invalid_text_using_db( $to_strip, $charset ) {
		$sql    = "SELECT CONVERT( %s USING {$charset} )";
		$query  = $this->prepare( $sql, $to_strip );
		$result = $this->_do_query( $query, $this->dbh );

		// Bail with error if no result
		if ( empty( $result ) ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		// Fetch row
		$row = mysqli_fetch_row( $result );

		// Bail with error if no rows
		if ( ! is_array( $row ) || count( $row ) < 1 ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		return $row[0];
	}

	/** TCP Cache *************************************************************/

	/**
	 * Start the TCP cache
	 *
	 * Only runs once. Subsequent calls will bail early.
	 *
	 * @since 5.2.0
	 *
	 * @see    https://github.com/stuttter/ludicrousdb/issues/126
	 * @uses   wp_start_object_cache() If available, to start the object cache.
	 * @static var bool $started True if started. False if not.
	 */
	protected function tcp_cache_start() {
		static $started = null;

		// Bail if added or caching not available yet
		if ( true === $started ) {
			return;
		}

		// Maybe start object cache
		if ( function_exists( 'wp_start_object_cache' ) ) {
			wp_start_object_cache();

			// Make sure the global group is added
			$this->tcp_cache_add_global_group();
		}

		// Set started
		$started = true;
	}

	/**
	 * Add global TCP cache group.
	 *
	 * Only runs if object cache is available and the necessary WordPress
	 * function (wp_cache_add_global_groups) exists.
	 *
	 * @since 5.2.0
	 */
	protected function tcp_cache_add_global_group() {

		// Add the cache group
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( $this->tcp_cache_group );
		}
	}

	/**
	 * Get the cache key used for TCP responses
	 *
	 * @since 3.0.0
	 *
	 * @param string     $host   Host.
	 * @param int|string $port   Port.
	 * @param string     $socket Optional. Unix socket path.
	 *
	 * @return string
	 */
	protected function tcp_get_cache_key( $host, $port, $socket = '' ) {
		$host = substr_count( $host, ':' ) > 1 ? "[{$host}]" : $host;
		$key  = "{$host}:{$port}";

		return empty( $socket ) ? $key : "{$key}:{$socket}";
	}

	/**
	 * Get the number of seconds TCP response is good for
	 *
	 * @since 3.0.0
	 *
	 * @return int
	 */
	protected function tcp_get_cache_expiration() {
		return 10;
	}

	/**
	 * Check if TCP is using a persistent cache or not.
	 *
	 * @since 5.1.0
	 *
	 * @return bool True if yes. False if no.
	 */
	protected function tcp_is_cache_persistent() {

		// Check if using external object cache
		if ( wp_using_ext_object_cache() ) {

			// Cache is persistent
			return true;
		}

		// Cache is not persistent
		return false;
	}

	/**
	 * Get cached up/down value of previous TCP response.
	 *
	 * Falls back to local cache if persistent cache is not available.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Results of tcp_get_cache_key()
	 *
	 * @return mixed Results of wp_cache_get()
	 */
	protected function tcp_cache_get( $key = '' ) {

		// Bail for invalid key
		if ( empty( $key ) ) {
			return false;
		}

		// Get from persistent cache
		if ( $this->tcp_is_cache_persistent() ) {
			return wp_cache_get( $key, $this->tcp_cache_group );

			// Fallback to local cache
		} elseif ( ! empty( $this->tcp_cache[ $key ] ) ) {

			// Not expired
			if (
				! empty( $this->tcp_cache[ $key ]['expiration'] )
				&&
				( time() < $this->tcp_cache[ $key ]['expiration'] )
			) {

				// Return value or false if empty
				return ! empty( $this->tcp_cache[ $key ]['value'] )
					? $this->tcp_cache[ $key ]['value']
					: false;

				// Expired, so delete and proceed
			} else {
				$this->tcp_cache_delete( $key );
			}
		}

		return false;
	}

	/**
	 * Set cached up/down value of current TCP response.
	 *
	 * Falls back to local cache if persistent cache is not available.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Results of tcp_get_cache_key()
	 * @param string $value "up" or "down" based on TCP response
	 *
	 * @return bool Results of wp_cache_set() or true
	 */
	protected function tcp_cache_set( $key = '', $value = '' ) {

		// Bail if invalid values were passed
		if ( empty( $key ) || empty( $value ) ) {
			return false;
		}

		// Get expiration
		$expires = $this->tcp_get_cache_expiration();

		// Add to persistent cache
		if ( $this->tcp_is_cache_persistent() ) {
			return wp_cache_set( $key, $value, $this->tcp_cache_group, $expires );

			// Fallback to local cache
		} else {
			$this->tcp_cache[ $key ] = array(
				'value'      => $value,
				'expiration' => time() + $expires,
			);
		}

		return true;
	}

	/**
	 * Delete cached up/down value of current TCP response.
	 *
	 * Falls back to local cache if persistent cache is not available.
	 *
	 * @since 5.1.0
	 *
	 * @param string $key Results of tcp_get_cache_key()
	 *
	 * @return bool Results of wp_cache_delete() or true
	 */
	protected function tcp_cache_delete( $key = '' ) {

		// Bail if invalid key
		if ( empty( $key ) ) {
			return false;
		}

		// Delete from persistent cache
		if ( $this->tcp_is_cache_persistent() ) {
			return wp_cache_delete( $key, $this->tcp_cache_group );

			// Fallback to local cache
		} else {
			unset( $this->tcp_cache[ $key ] );
		}

		return true;
	}

	/**
	 * Update the heartbeat
	 *
	 * @param string|object $dbhname_or_dbh To update the heartbeat for
	 *
	 * @return void
	 */
	protected function update_heartbeat( $dbhname_or_dbh ) {
		if ( ! $this->check_dbh_heartbeats ) {
			return;
		}

		if ( is_string( $dbhname_or_dbh ) ) {
			$dbhname = $dbhname_or_dbh;
		} else {
			$dbhname = $this->lookup_dbhs_name( $dbhname_or_dbh );
		}

		// Set last used for this dbh
		if ( empty( $dbhname ) ) {
			return;
		}

		if ( ! isset( $this->dbhname_heartbeats[ $dbhname ] ) ) {
			$this->dbhname_heartbeats[ $dbhname ] = array();
		}

		$this->dbhname_heartbeats[ $dbhname ]['last_used'] = microtime( true );
	}

	/**
	 * Find a dbh name value for a given $dbh object.
	 *
	 * @since 5.0.0
	 *
	 * @param object|false $dbh The dbh object for which to find the dbh name.
	 *
	 * @return string|false The dbh name, or false when the handle is unknown.
	 */
	private function lookup_dbhs_name( $dbh = false ) {

		// Loop through database hosts and look for this one
		foreach ( $this->dbhs as $dbhname => $other_dbh ) {

			// Match found so return the key
			if ( $dbh === $other_dbh ) {
				return $dbhname;
			}
		}

		// No match
		return false;
	}

	/** Deprecated ************************************************************/

	/**
	 * Set a flag to prevent reading from replicas which might be lagging after
	 * a write.
	 *
	 * @since 1.0.0
	 * @deprecated 5.2.0 Use send_reads_to_primaries() instead.
	 */
	public function send_reads_to_masters() {
		_deprecated_function( __FUNCTION__, '5.2.0', 'wpdb::send_reads_to_primaries()' );
		$this->send_reads_to_primaries();
	}
}
