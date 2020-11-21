<?php

/**
 * LudicrousDB Class
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
	 * The last table that was queried
	 *
	 * @var string
	 */
	public $last_table = '';

	/**
	 * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
	 * is sent and the MySQL result resource stored here. The next query
	 * for FOUND_ROWS() will retrieve this. We do this to prevent any
	 * intervening queries from making FOUND_ROWS() inaccessible. You may
	 * prevent this by adding "NO_SELECT_FOUND_ROWS" in a comment
	 *
	 * @var resource
	 */
	public $last_found_rows_result = null;

	/**
	 * Whether to store queries in an array. Useful for debugging and profiling
	 *
	 * @var bool
	 */
	public $save_queries = false;

	/**
	 * The current MySQL link resource
	 *
	 * @var resource
	 */
	public $dbh;

	/**
	 * Associative array (dbhname => dbh) for established MySQL connections
	 *
	 * @var array
	 */
	public $dbhs = array();

	/**
	 * The multi-dimensional array of datasets and servers
	 *
	 * @var array
	 */
	public $ludicrous_servers = array();

	/**
	 * Optional directory of tables and their datasets
	 *
	 * @var array
	 */
	public $ludicrous_tables = array();

	/**
	 * Optional directory of callbacks to determine datasets from queries
	 *
	 * @var array
	 */
	public $ludicrous_callbacks = array();

	/**
	 * Custom callback to save debug info in $this->queries
	 *
	 * @var callable
	 */
	public $save_query_callback = null;

	/**
	 * Whether to use mysql_pconnect instead of mysql_connect
	 *
	 * @var bool
	 */
	public $persistent = false;

	/**
	 * Allow bail if connection fails
	 *
	 * @var bool
	 */
	public $allow_bail = false;

	/**
	 * The maximum number of db links to keep open. The least-recently used
	 * link will be closed when the number of links exceeds this
	 *
	 * @var int
	 */
	public $max_connections = 10;

	/**
	 * Whether to check with fsockopen prior to mysql_connect
	 *
	 * @var bool
	 */
	public $check_tcp_responsiveness = true;

	/**
	 * The amount of time to wait before trying again to ping mysql server.
	 *
	 * @var float
	 */
	public $recheck_timeout = 0.1;

	/**
	 * Whether to check for heartbeats
	 *
	 * @var bool
	 */
	public $check_dbh_heartbeats = true;

	/**
	 * Keeps track of the dbhname usage and errors.
	 *
	 * @var array
	 */
	public $dbhname_heartbeats = array();

	/**
	 * The number of times to retry reconnecting before dying
	 *
	 * @access protected
	 * @see wpdb::check_connection()
	 * @var int
	 */
	protected $reconnect_retries = 3;

	/**
	 * Send Reads To Masters. This disables slave connections while true.
	 * Otherwise it is an array of written tables
	 *
	 * @var array
	 */
	public $srtm = array();

	/**
	 * The log of db connections made and the time each one took
	 *
	 * @var array
	 */
	public $db_connections = array();

	/**
	 * The list of unclosed connections sorted by LRU
	 *
	 * @var array
	 */
	public $open_connections = array();

	/**
	 * Lookup array (dbhname => host:port)
	 *
	 * @var array
	 */
	public $dbh2host = array();

	/**
	 * The last server used and the database name selected
	 *
	 * @var array
	 */
	public $last_used_server = array();

	/**
	 * Lookup array (dbhname => (server, db name) ) for re-selecting the db
	 * when a link is re-used
	 *
	 * @var array
	 */
	public $used_servers = array();

	/**
	 * Whether to save debug_backtrace in save_query_callback. You may wish
	 * to disable this, e.g. when tracing out-of-memory problems.
	 *
	 * @var bool
	 */
	public $save_backtrace = true;

	/**
	 * Maximum lag in seconds. Set null to disable. Requires callbacks
	 *
	 * @var integer
	 */
	public $default_lag_threshold = null;

	/**
	 * In memory cache for tcp connected status.
	 *
	 * @var array
	 */
	private $tcp_cache = array();

	/**
	 * Name of object cache group.
	 *
	 * @var string
	 */
	public $cache_group = 'ludicrousdb';

	/**
	 * Whether to ignore slave lag.
	 *
	 * @var bool
	 */
	private $ignore_slave_lag = false;

	/**
	 * Number of unique servers.
	 *
	 * @var int
	 */
	private $unique_servers = null;

	/**
	 * Gets ready to make database connections
	 *
	 * @since 1.0.0
	 *
	 * @param array $args db class vars
	 */
	public function __construct( $args = null ) {

		if ( WP_DEBUG && WP_DEBUG_DISPLAY ) {
			$this->show_errors();
		}

		/*
		 *  Use ext/mysqli if it exists and:
		 *  - WP_USE_EXT_MYSQL is defined as false, or
		 *  - We are a development version of WordPress, or
		 *  - We are running PHP 5.5 or greater, or
		 *  - ext/mysql is not loaded.
		 */
		if ( function_exists( 'mysqli_connect' ) ) {
			if ( defined( 'WP_USE_EXT_MYSQL' ) ) {
				$this->use_mysqli = ! WP_USE_EXT_MYSQL;
			} elseif ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
				$this->use_mysqli = true;
			} elseif ( false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
				$this->use_mysqli = true;
			}
		}

		// Maybe override class vars
		if ( is_array( $args ) ) {
			$class_vars = array_keys( get_class_vars( __CLASS__ ) );
			foreach ( $class_vars as $var ) {
				if ( isset( $args[ $var ] ) ) {
					$this->{$var} = $args[ $var ];
				}
			}
		}

		// Set collation and character set
		$this->init_charset();
	}

	/**
	 * Sets $this->charset and $this->collate
	 *
	 * @since 1.0.0
	 *
	 * @global array $wp_global
	 */
	public function init_charset() {
		global $wp_version;

		// Defaults
		$charset = $collate = '';

		// Multisite
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			if ( version_compare( $wp_version, '4.2', '<' ) ) {
				$charset = 'utf8';
				$collate = 'utf8_general_ci';
			} else {
				$charset = 'utf8mb4';
				$collate = 'utf8mb4_unicode_520_ci';
			}
		}

		// Use constant if defined
		if ( defined( 'DB_COLLATE' ) ) {
			$collate = DB_COLLATE;
		}

		// Use constant if defined
		if ( defined( 'DB_CHARSET' ) ) {
			$charset = DB_CHARSET;
		}

		// determine_charset is only in WordPress 4.6
		if ( method_exists( $this, 'determine_charset' ) ) {
			$determined = $this->determine_charset( $charset, $collate );
			$charset    = $determined['charset'];
			$collate    = $determined['collate'];
			unset( $determined );
		}

		// Set charset & collation
		$this->charset = $charset;
		$this->collate = $collate;
	}

	/**
	 * Add the connection parameters for a database
	 *
	 * @since 1.0.0
	 *
	 * @param array $db Default to empty array.
	 */
	public function add_database( array $db = array() ) {

		// Setup some sane default values
		$database_defaults = array(
			'dataset'       => 'global',
			'write'         => 1,
			'read'          => 1,
			'timeout'       => 0.2,
			'port'          => 3306,
			'lag_threshold' => null,
		);

		// Merge using defaults
		$db      = wp_parse_args( $db, $database_defaults );

		// Break these apart to make code easier to understand below
		$dataset = $db['dataset'];
		$read    = $db['read'];
		$write   = $db['write'];

		// We do not include the dataset in the array. It's used as a key.
		unset( $db['dataset'] );

		// Maybe add database to array of read's
		if ( ! empty( $read ) ) {
			$this->ludicrous_servers[ $dataset ]['read'][ $read ][] = $db;
		}

		// Maybe add database to array of write's
		if ( ! empty( $write ) ) {
			$this->ludicrous_servers[ $dataset ]['write'][ $write ][] = $db;
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
		if ( preg_match( '/(?:^|\s)(?:ALTER|CREATE|ANALYZE|CHECK|OPTIMIZE|REPAIR|CALL|DELETE|DROP|INSERT|LOAD|REPLACE|UPDATE|SET|RENAME\s+TABLE)(?:\s|$)/i', $q ) ) {
			return true;
		}

		// Not possible non-writes (phew!)
		return ! preg_match( '/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)(?:\s|$)/i', $q );
	}

	/**
	 * Set a flag to prevent reading from slaves which might be lagging after a write
	 *
	 * @since 1.0.0
	 */
	public function send_reads_to_masters() {
		$this->srtm = true;
	}

	/**
	 * Callbacks are executed in the order in which they are registered until one
	 * of them returns something other than null
	 *
	 * @since 1.0.0
	 * @param string $group Group, key name in array.
	 * @param array  $args   Args passed to callback. Default to null.
	 */
	public function run_callbacks( $group, $args = null ) {
		if ( ! isset( $this->ludicrous_callbacks[ $group ] ) || ! is_array( $this->ludicrous_callbacks[ $group ] ) ) {
			return null;
		}

		if ( ! isset( $args ) ) {
			$args = array( &$this );
		} elseif ( is_array( $args ) ) {
			$args[] = &$this;
		} else {
			$args = array( $args, &$this );
		}

		foreach ( $this->ludicrous_callbacks[ $group ] as $func ) {
			$result = call_user_func_array( $func, $args );
			if ( isset( $result ) ) {
				return $result;
			}
		}
	}

	/**
	 * Figure out which db server should handle the query, and connect to it
	 *
	 * @since 1.0.0
	 *
	 * @param string $query Query.
	 *
	 * @return resource MySQL database connection
	 */
	public function db_connect( $query = '' ) {

		// Bail if empty query
		if ( empty( $query ) ) {
			return false;
		}

		// can be empty/false if the query is e.g. "COMMIT"
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
			return $this->bail( "Unable to determine which dataset to query. ({$this->table})" );
		} else {
			$this->dataset = $dataset;
		}

		$this->run_callbacks( 'dataset_found', $dataset );

		if ( empty( $this->ludicrous_servers ) ) {
			if ( $this->dbh_type_check( $this->dbh ) ) {
				return $this->dbh;
			}

			if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
				return $this->bail( 'We were unable to query because there was no database defined.' );
			}

			// Fallback to wpdb db_connect method.

			$this->dbuser     = DB_USER;
			$this->dbpassword = DB_PASSWORD;
			$this->dbname     = DB_NAME;
			$this->dbhost     = DB_HOST;

			parent::db_connect();

			return $this->dbh;
		}

		// Determine whether the query must be sent to the master (a writable server)
		if ( ! empty( $use_master ) || ( $this->srtm === true ) || isset( $this->srtm[ $this->table ] ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
			$use_master = true;
		} elseif ( $this->is_write_query( $query ) ) {
			$use_master = true;
			if ( is_array( $this->srtm ) ) {
				$this->srtm[ $this->table ] = true;
			}

			// Detect queries that have a join in the srtm array.
		} elseif ( ! isset( $use_master ) && is_array( $this->srtm ) && ! empty( $this->srtm ) ) {
			$use_master  = false;
			$query_match = substr( $query, 0, 1000 );

			foreach ( $this->srtm as $key => $value ) {
				if ( false !== stripos( $query_match, $key ) ) {
					$use_master = true;
					break;
				}
			}
		} else {
			$use_master = false;
		}

		if ( ! empty( $use_master ) ) {
			$this->dbhname = $dbhname = $dataset . '__w';
			$operation     = 'write';
		} else {
			$this->dbhname = $dbhname = $dataset . '__r';
			$operation     = 'read';
		}

		// Try to reuse an existing connection
		while ( isset( $this->dbhs[ $dbhname ] ) && $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {

			// Find the connection for incrementing counters
			foreach ( array_keys( $this->db_connections ) as $i ) {
				if ( $this->db_connections[ $i ]['dbhname'] == $dbhname ) {
					$conn = &$this->db_connections[ $i ];
				}
			}

			if ( isset( $server['name'] ) ) {
				$name = $server['name'];

				// A callback has specified a database name so it's possible the
				// existing connection selected a different one.
				if ( $name != $this->used_servers[ $dbhname ]['name'] ) {
					if ( ! $this->select( $name, $this->dbhs[ $dbhname ] ) ) {

						// This can happen when the user varies and lacks
						// permission on the $name database
						if ( isset( $conn['disconnect (select failed)'] ) ) {
							++ $conn['disconnect (select failed)'];
						} else {
							$conn['disconnect (select failed)'] = 1;
						}

						$this->disconnect( $dbhname );
						break;
					}
					$this->used_servers[ $dbhname ]['name'] = $name;
				}
			} else {
				$name = $this->used_servers[ $dbhname ]['name'];
			}

			$this->current_host = $this->dbh2host[ $dbhname ];

			// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
			if ( $k = array_search( $dbhname, $this->open_connections, true ) ) {
				unset( $this->open_connections[ $k ] );
				$this->open_connections[] = $dbhname;
			}

			$this->last_used_server = $this->used_servers[ $dbhname ];
			$this->last_connection  = compact( 'dbhname', 'name' );

			if ( $this->should_mysql_ping( $dbhname ) && ! $this->check_connection( false, $this->dbhs[ $dbhname ] ) ) {
				if ( isset( $conn['disconnect (ping failed)'] ) ) {
					++ $conn['disconnect (ping failed)'];
				} else {
					$conn['disconnect (ping failed)'] = 1;
				}

				$this->disconnect( $dbhname );
				break;
			}

			if ( isset( $conn['queries'] ) ) {
				++ $conn['queries'];
			} else {
				$conn['queries'] = 1;
			}

			return $this->dbhs[ $dbhname ];
		}

		if ( ! empty( $use_master ) && defined( 'MASTER_DB_DEAD' ) ) {
			return $this->bail( 'We are updating the database. Please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online.' );
		}

		if ( empty( $this->ludicrous_servers[ $dataset ][ $operation ] ) ) {
			return $this->bail( "No databases available with {$this->table} ({$dataset})" );
		}

		// Put the groups in order by priority
		ksort( $this->ludicrous_servers[ $dataset ][ $operation ] );

		// Make a list of at least $this->reconnect_retries connections to try, repeating as necessary.
		$servers = array();
		do {
			foreach ( $this->ludicrous_servers[ $dataset ][ $operation ] as $group => $items ) {
				$keys = array_keys( $items );
				shuffle( $keys );

				foreach ( $keys as $key ) {
					$servers[] = compact( 'group', 'key' );
				}
			}

			if ( ! $tries_remaining = count( $servers ) ) {
				return $this->bail( "No database servers were found to match the query. ({$this->table}, {$dataset})" );
			}

			if ( is_null( $this->unique_servers ) ) {
				$this->unique_servers = $tries_remaining;
			}
		} while ( $tries_remaining < $this->reconnect_retries );

		// Connect to a database server
		do {
			$unique_lagged_slaves = array();
			$success              = false;

			foreach ( $servers as $group_key ) {
				-- $tries_remaining;

				// If all servers are lagged, we need to start ignoring the lag and retry
				if ( count( $unique_lagged_slaves ) == $this->unique_servers ) {
					break;
				}

				// $group, $key
				$group = $group_key['group'];
				$key   = $group_key['key'];

				// $host, $user, $password, $name, $read, $write [, $lag_threshold, $timeout ]
				$db_config     = $this->ludicrous_servers[ $dataset ][ $operation ][ $group ][ $key ];
				$host          = $db_config['host'];
				$user          = $db_config['user'];
				$password      = $db_config['password'];
				$name          = $db_config['name'];
				$write         = $db_config['write'];
				$read          = $db_config['read'];
				$timeout       = $db_config['timeout'];
				$port          = $db_config['port'];
				$lag_threshold = $db_config['lag_threshold'];

				// Split host:port into $host and $port
				if ( strpos( $host, ':' ) ) {
					list( $host, $port ) = explode( ':', $host );
				}

				// Overlay $server if it was extracted from a callback
				if ( isset( $server ) && is_array( $server ) ) {
					extract( $server, EXTR_OVERWRITE );
				}

				// Split again in case $server had host:port
				if ( strpos( $host, ':' ) ) {
					list( $host, $port ) = explode( ':', $host );
				}

				// Make sure there's always a port number
				if ( empty( $port ) ) {
					$port = 3306;
				}

				// Use a default timeout of 200ms
				if ( ! isset( $timeout ) ) {
					$timeout = 0.2;
				}

				// Get the minimum group here, in case $server rewrites it
				if ( ! isset( $min_group ) || ( $min_group > $group ) ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
					$min_group = $group;
				}

				$host_and_port = "{$host}:{$port}";

				// Can be used by the lag callbacks
				$this->lag_cache_key = $host_and_port;
				$this->lag_threshold = isset( $lag_threshold )
					? $lag_threshold
					: $this->default_lag_threshold;

				// Check for a lagged slave, if applicable
				if ( empty( $use_master ) && empty( $write ) && empty ( $this->ignore_slave_lag ) && isset( $this->lag_threshold ) && ! isset( $server['host'] ) && ( $lagged_status = $this->get_lag_cache() ) === DB_LAG_BEHIND ) {

					// If it is the last lagged slave and it is with the best preference we will ignore its lag
					if ( ! isset( $unique_lagged_slaves[ $host_and_port ] ) && $this->unique_servers == count( $unique_lagged_slaves ) + 1 && $group == $min_group ) {
						$this->lag_threshold = null;
					} else {
						$unique_lagged_slaves[ $host_and_port ] = $this->lag;
						continue;
					}
				}

				$this->timer_start();

				// Maybe check TCP responsiveness
				$tcp = ! empty( $this->check_tcp_responsiveness )
					? $this->check_tcp_responsiveness( $host, $port, $timeout )
					: null;

				// Connect if necessary or possible
				if ( ! empty( $use_master ) || empty( $tries_remaining ) || ( true === $tcp ) ) {
					$this->single_db_connect( $dbhname, $host_and_port, $user, $password );
				} else {
					$this->dbhs[ $dbhname ] = false;
				}

				$elapsed = $this->timer_stop();

				if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					/**
					 * If we care about lag, disconnect lagged slaves and try to find others.
					 * We don't disconnect if it is the last lagged slave and it is with the best preference.
					 */
					if ( empty( $use_master )
						 && empty( $write )
						 && empty( $this->ignore_slave_lag )
						 && isset( $this->lag_threshold )
						 && ! isset( $server['host'] )
						 && ( $lagged_status !== DB_LAG_OK )
						 && ( $lagged_status = $this->get_lag() ) === DB_LAG_BEHIND && ! (
							! isset( $unique_lagged_slaves[ $host_and_port ] )
							&& ( $this->unique_servers == ( count( $unique_lagged_slaves ) + 1 ) )
							&& ( $group == $min_group )
						)
					) {
						$unique_lagged_slaves[ $host_and_port ] = $this->lag;
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
							$queries = isset( $queries   ) ? $queries : 1; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
							$lag     = isset( $this->lag ) ? $this->lag : 0;

							$this->last_connection    = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success', 'queries', 'lag' );
							$this->db_connections[]   = $this->last_connection;
							$this->open_connections[] = $dbhname;
							$success                  = true;
							break;
						}
					}
				}

				$success                = false;
				$this->last_connection  = compact( 'dbhname', 'host', 'port', 'user', 'name', 'tcp', 'elapsed', 'success' );
				$this->db_connections[] = $this->last_connection;

				if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
					if ( true === $this->use_mysqli ) {
						$error = mysqli_error( $this->dbhs[ $dbhname ] );
						$errno = mysqli_errno( $this->dbhs[ $dbhname ] );
					} else {
						$error = mysql_error( $this->dbhs[ $dbhname ] );
						$errno = mysql_errno( $this->dbhs[ $dbhname ] );
					}
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

			if ( empty( $success )
				 || ! isset( $this->dbhs[ $dbhname ] )
				 || ! $this->dbh_type_check( $this->dbhs[ $dbhname ] )
			) {

				// Lagged slaves were not used. Ignore the lag for this connection attempt and retry.
				if ( empty( $this->ignore_slave_lag ) && count( $unique_lagged_slaves ) ) {
					$this->ignore_slave_lag = true;
					$tries_remaining        = count( $servers );
					continue;
				}

				$this->run_callbacks( 'db_connection_error', array(
					'host'      => $host,
					'port'      => $port,
					'operation' => $operation,
					'table'     => $this->table,
					'dataset'   => $dataset,
					'dbhname'   => $dbhname,
				) );

				return $this->bail( "Unable to connect to {$host}:{$port} to {$operation} table '{$this->table}' ({$dataset})" );
			}

			break;
		} while ( true );

		$this->set_charset( $this->dbhs[ $dbhname ] );

		$this->dbh                      = $this->dbhs[ $dbhname ]; // needed by $wpdb->_real_escape()
		$this->last_used_server         = compact( 'host', 'user', 'name', 'read', 'write' );
		$this->used_servers[ $dbhname ] = $this->last_used_server;

		while ( ( false === $this->persistent ) && count( $this->open_connections ) > $this->max_connections ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
			$oldest_connection = array_shift( $this->open_connections );
			if ( $this->dbhs[ $oldest_connection ] != $this->dbhs[ $dbhname ] ) {
				$this->disconnect( $oldest_connection );
			}
		}

		return $this->dbhs[ $dbhname ];
	}

	/**
	 * Connect selected database
	 *
	 * @since 1.0.0
	 *
	 * @param string $dbhname Database name.
	 * @param string $host Internet address: host:port of server on internet.
	 * @param string $user Database user.
	 * @param string $password Database password.
	 *
	 * @return bool|mysqli|resource
	 */
	protected function single_db_connect( $dbhname, $host, $user, $password ) {
		$this->is_mysql = true;

		/*
		 * Deprecated in 3.9+ when using MySQLi. No equivalent
		 * $new_link parameter exists for mysqli_* functions.
		 */
		$new_link     = defined( 'MYSQL_NEW_LINK' ) ? MYSQL_NEW_LINK : true;
		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		if ( true === $this->use_mysqli ) {
			$this->dbhs[ $dbhname ] = mysqli_init();

			// mysqli_real_connect doesn't support the host param including a port or socket
			// like mysql_connect does. This duplicates how mysql_connect detects a port and/or socket file.
			$port           = null;
			$socket         = null;
			$port_or_socket = strstr( $host, ':' );

			if ( ! empty( $port_or_socket ) ) {
				$host           = substr( $host, 0, strpos( $host, ':' ) );
				$port_or_socket = substr( $port_or_socket, 1 );

				if ( 0 !== strpos( $port_or_socket, '/' ) ) {
					$port         = intval( $port_or_socket );
					$maybe_socket = strstr( $port_or_socket, ':' );

					if ( ! empty( $maybe_socket ) ) {
						$socket = substr( $maybe_socket, 1 );
					}
				} else {
					$socket = $port_or_socket;
				}
			}

			// Detail found here - https://core.trac.wordpress.org/ticket/31018
			$pre_host = '';

			// If DB_HOST begins with a 'p:', allow it to be passed to mysqli_real_connect().
			// mysqli supports persistent connections starting with PHP 5.3.0.
			if ( ( true === $this->persistent ) && version_compare( phpversion(), '5.3.0', '>=' ) ) {
				$pre_host = 'p:';
			}

			mysqli_real_connect( $this->dbhs[ $dbhname ], $pre_host . $host, $user, $password, null, $port, $socket, $client_flags );

			if ( $this->dbhs[ $dbhname ]->connect_errno ) {
				$this->dbhs[ $dbhname ] = false;

				return false;
			}
		} else {

			// Check if functions exists (they do not in PHP 7)
			if ( ( true === $this->persistent ) && function_exists( 'mysql_pconnect' ) ) {
				$this->dbhs[ $dbhname ] = mysql_pconnect( $host, $user, $password, $new_link, $client_flags );
			} elseif ( function_exists( 'mysql_connect' ) ) {
				$this->dbhs[ $dbhname ] = mysql_connect( $host, $user, $password, $new_link, $client_flags );
			}
		}
	}

	/**
	 * Change the current SQL mode, and ensure its WordPress compatibility
	 *
	 * If no modes are passed, it will ensure the current MySQL server
	 * modes are compatible
	 *
	 * @since 1.0.0
	 *
	 * @param array                 $modes Optional. A list of SQL modes to set.
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 */
	public function set_sql_mode( $modes = array(), $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return;
		}

		if ( empty( $modes ) ) {
			if ( true === $this->use_mysqli ) {
				$res = mysqli_query( $dbh, 'SELECT @@SESSION.sql_mode' );
			} else {
				$res = mysql_query( 'SELECT @@SESSION.sql_mode', $dbh );
			}

			if ( empty( $res ) ) {
				return;
			}

			if ( true === $this->use_mysqli ) {
				$modes_array = mysqli_fetch_array( $res );
				if ( empty( $modes_array[0] ) ) {
					return;
				}
				$modes_str = $modes_array[0];
			} else {
				$modes_str = mysql_result( $res, 0 );
			}

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

		if ( true === $this->use_mysqli ) {
			mysqli_query( $dbh, "SET SESSION sql_mode='{$modes_str}'" );
		} else {
			mysql_query( "SET SESSION sql_mode='{$modes_str}'", $dbh );
		}
	}

	/**
	 * Selects a database using the current database connection
	 *
	 * The database name will be changed based on the current database
	 * connection. On failure, the execution will bail and display an DB error
	 *
	 * @since 1.0.0
	 *
	 * @param string                $db MySQL database name
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 */
	public function select( $db, $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		if ( true === $this->use_mysqli ) {
			$success = mysqli_select_db( $dbh, $db );
		} else {
			$success = mysql_select_db( $db, $dbh );
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
		if ( ! empty( $this->col_info ) || ( false === $this->result ) ) {
			return;
		}

		$this->col_info = array();

		if ( true === $this->use_mysqli ) {
			$num_fields = mysqli_num_fields( $this->result );
			for ( $i = 0; $i < $num_fields; $i ++ ) {
				$this->col_info[ $i ] = mysqli_fetch_field( $this->result );
			}
		} else {
			$num_fields = mysql_num_fields( $this->result );
			for ( $i = 0; $i < $num_fields; $i ++ ) {
				$this->col_info[ $i ] = mysql_fetch_field( $this->result, $i );
			}
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
	 * @param string $string String to escape.
	 */
	public function _real_escape( $string ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore

		// Slash the query part
		$escaped = addslashes( $string );

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
	 * @param resource $dbh The resource given by mysql_connect
	 * @param string   $charset The character set (optional)
	 * @param string   $collate The collation (optional)
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}
		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}
		if ( empty( $charset ) || empty( $collate ) ) {
			wp_die( "{$charset}  {$collate}" );
		}
		if ( ! in_array( strtolower( $charset ), array( 'utf8', 'utf8mb4', 'latin1' ), true ) ) {
			wp_die( "{$charset} charset isn't supported in LudicrousDB for security reasons" );
		}
		if ( $this->has_cap( 'collation', $dbh ) && ! empty( $charset ) ) {
			$set_charset_succeeded = true;
			if ( ( true === $this->use_mysqli ) && function_exists( 'mysqli_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
				$set_charset_succeeded = mysqli_set_charset( $dbh, $charset );
			} elseif ( function_exists( 'mysql_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
				$set_charset_succeeded = mysql_set_charset( $charset, $dbh );
			}
			if ( $set_charset_succeeded ) {
				$query = $this->prepare( 'SET NAMES %s', $charset );
				if ( ! empty( $collate ) ) {
					$query .= $this->prepare( ' COLLATE %s', $collate );
				}
				$this->_do_query( $query, $dbh );
			}
		}
	}

	/**
	 * Disconnect and remove connection from open connections list
	 *
	 * @since 1.0.0
	 *
	 * @param string $dbhname Dataname key name.
	 */
	public function disconnect( $dbhname ) {

		$k = array_search( $dbhname, $this->open_connections, true );
		if ( ! empty( $k ) ) {
			unset( $this->open_connections[ $k ] );
		}

		if ( $this->dbh_type_check( $this->dbhs[ $dbhname ] ) ) {
			$this->close( $this->dbhs[ $dbhname ] );
		}

		unset( $this->dbhs[ $dbhname ] );
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
	 * to reconnect
	 *
	 * If this function is unable to reconnect, it will forcibly die, or if after the
	 * the template_redirect hook has been fired, return false instead
	 *
	 * If $allow_bail is false, the lack of database connection will need
	 * to be handled manually
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allow_bail Optional. Allows the function to bail. Default true.
	 * @param bool   $dbh_or_table Optional.
	 * @param string $query Optional. Query string passed db_connect
	 *
	 * @return bool|void True if the connection is up.
	 */
	public function check_connection( $allow_bail = true, $dbh_or_table = false, $query = '' ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( $this->dbh_type_check( $dbh ) ) {
			if ( true === $this->use_mysqli ) {
				if ( mysqli_ping( $dbh ) ) {
					return true;
				}
			} else {
				if ( mysql_ping( $dbh ) ) {
					return true;
				}
			}
		}

		if ( false === $allow_bail ) {
			return false;
		}

		$error_reporting = false;

		// Disable warnings, as we don't want to see a multitude of "unable to connect" messages
		if ( WP_DEBUG ) {
			$error_reporting = error_reporting();
			error_reporting( $error_reporting & ~E_WARNING );
		}

		for ( $tries = 1; $tries <= $this->reconnect_retries; $tries ++ ) {

			// On the last try, re-enable warnings. We want to see a single instance of the
			// "unable to connect" message on the bail() screen, if it appears.
			if ( $this->reconnect_retries === $tries && WP_DEBUG ) {
				error_reporting( $error_reporting );
			}

			if ( $this->db_connect( $query ) ) {
				if ( $error_reporting ) {
					error_reporting( $error_reporting );
				}

				return true;
			}

			sleep( 1 );
		}

		// If template_redirect has already happened, it's too late for wp_die()/dead_db().
		// Let's just return and hope for the best.
		if ( did_action( 'template_redirect' ) ) {
			return false;
		}

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

		// Call dead_db() if bail didn't die, because this database is no more. It has ceased to be (at least temporarily).
		dead_db();
	}

	/**
	 * Basic query. See documentation for more details.
	 *
	 * @since 1.0.0
	 *
	 * @param string $query Query.
	 *
	 * @return int number of rows
	 */
	public function query( $query ) {

		// initialise return
		$return_val = 0;
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
			$return_val = apply_filters_ref_array( 'pre_query', array( null, $query, &$this ) );
			if ( null !== $return_val ) {
				$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

				return $return_val;
			}
		}

		// Bail if query is empty (via application error or 'query' filter)
		if ( empty( $query ) ) {
			$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

			return $return_val;
		}

		// Log how the function was called
		$this->func_call = "\$db->query(\"{$query}\")";

		// If we're writing to the database, make sure the query will write safely.
		if ( $this->check_current_query && ! $this->check_ascii( $query ) ) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );

			// strip_invalid_text_from_query() can perform queries, so we need
			// to flush again, just to make sure everything is clear.
			$this->flush();

			if ( $stripped_query !== $query ) {
				$this->insert_id = 0;
				$return_val      = false;
				$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

				return $return_val;
			}
		}

		$this->check_current_query = true;

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if ( preg_match( '/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query )
			&&
			(
				(
					( false === $this->use_mysqli )
					&&
					is_resource( $this->last_found_rows_result )
				)
				||
				(
					( true === $this->use_mysqli )
					&&
					( $this->last_found_rows_result instanceof mysqli_result )
				)
			)
		) {
			$this->result = $this->last_found_rows_result;
			$elapsed      = 0;
		} else {
			$this->dbh = $this->db_connect( $query );

			if ( ! $this->dbh_type_check( $this->dbh ) ) {
				$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

				return false;
			}

			$this->timer_start();
			$this->result = $this->_do_query( $query, $this->dbh );
			$elapsed      = $this->timer_stop();

			++ $this->num_queries;

			if ( preg_match( '/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query ) ) {
				if ( false === strpos( $query, 'NO_SELECT_FOUND_ROWS' ) ) {
					$this->timer_start();
					$this->last_found_rows_result = $this->_do_query( 'SELECT FOUND_ROWS()', $this->dbh );
					$elapsed                     += $this->timer_stop();
					++ $this->num_queries;
					$query .= '; SELECT FOUND_ROWS()';
				}
			} else {
				$this->last_found_rows_result = null;
			}

			if ( ! empty( $this->save_queries ) || ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) ) {
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
			if ( true === $this->use_mysqli ) {
				$this->last_error = mysqli_error( $this->dbh );
			} else {
				$this->last_error = mysql_error( $this->dbh );
			}
		}

		if ( ! empty( $this->last_error ) ) {
			$this->print_error( $this->last_error );
			$return_val = false;
			$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

			return $return_val;
		}

		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			$return_val = $this->result;
		} elseif ( preg_match( '/^\\s*(insert|delete|update|replace|alter) /i', $query ) ) {
			if ( true === $this->use_mysqli ) {
				$this->rows_affected = mysqli_affected_rows( $this->dbh );
			} else {
				$this->rows_affected = mysql_affected_rows( $this->dbh );
			}

			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				if ( true === $this->use_mysqli ) {
					$this->insert_id = mysqli_insert_id( $this->dbh );
				} else {
					$this->insert_id = mysql_insert_id( $this->dbh );
				}
			}

			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$num_rows          = 0;
			$this->last_result = array();

			if ( ( true === $this->use_mysqli ) && ( $this->result instanceof mysqli_result ) ) {
				$this->load_col_info();
				while ( $row = mysqli_fetch_object( $this->result ) ) {
					$this->last_result[ $num_rows ] = $row;
					$num_rows ++;
				}
			} elseif ( is_resource( $this->result ) ) {
				$this->load_col_info();
				while ( $row = mysql_fetch_object( $this->result ) ) {
					$this->last_result[ $num_rows ] = $row;
					$num_rows ++;
				}
			}

			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		$this->run_callbacks( 'sql_query_log', array( $query, $return_val, $this->last_error ) );

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

		return $return_val;
	}

	/**
	 * Internal function to perform the mysql_query() call
	 *
	 * @since 1.0.0
	 *
	 * @access protected
	 * @see wpdb::query()
	 *
	 * @param string $query The query to run.
	 * @param bool   $dbh_or_table  Database or table name. Defaults to false.
	 */
	protected function _do_query( $query, $dbh_or_table = false ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		if ( true === $this->use_mysqli ) {
			$result = mysqli_query( $dbh, $query );
		} else {
			$result = mysql_query( $query, $dbh );
		}

		// Maybe log last used to heartbeats
		if ( ! empty( $this->check_dbh_heartbeats ) ) {

			// Lookup name
			$name = $this->lookup_dbhs_name( $dbh );

			// Set last used for this dbh
			if ( ! empty( $name ) ) {
				$this->dbhname_heartbeats[ $name ]['last_used'] = microtime( true );
			}
		}

		return $result;
	}

	/**
	 * Closes the current database connection
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 *
	 * @return bool True if the connection was successfully closed, false if it wasn't,
	 *              or the connection doesn't exist.
	 */
	public function close( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		if ( true === $this->use_mysqli ) {
			$closed = mysqli_close( $dbh );
		} else {
			$closed = mysql_close( $dbh );
		}

		if ( ! empty( $closed ) ) {
			$this->dbh = null;
		}

		return $closed;
	}

	/**
	 * Whether or not MySQL database is at least the required minimum version.
	 * The additional argument allows the caller to check a specific database
	 *
	 * @since 1.0.0
	 *
	 * @global $wp_version
	 * @global $required_mysql_version
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
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
	 * This function is called when WordPress is generating the table schema to determine whether or not the current database
	 * supports or needs the collation statements
	 *
	 * The additional argument allows the caller to check a specific database
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 *
	 * @return bool
	 */
	public function supports_collation( $dbh_or_table = false ) {
		_deprecated_function( __FUNCTION__, '3.5', 'wpdb::has_cap( \'collation\' )' );

		return $this->has_cap( 'collation', $dbh_or_table );
	}

	/**
	 * Generic function to determine if a database supports a particular feature
	 * The additional argument allows the caller to check a specific database
	 *
	 * @since 1.0.0
	 *
	 * @param string                $db_cap the feature
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 *
	 * @return bool
	 */
	public function has_cap( $db_cap, $dbh_or_table = false ) {
		$version = $this->db_version( $dbh_or_table );

		switch ( strtolower( $db_cap ) ) {
			case 'collation':    // @since 2.5.0
			case 'group_concat': // @since 2.7.0
			case 'subqueries':   // @since 2.7.0
				return version_compare( $version, '4.1', '>=' );
			case 'set_charset':
				return version_compare( $version, '5.0.7', '>=' );
			case 'utf8mb4':      // @since 4.1.0
				if ( version_compare( $version, '5.5.3', '<' ) ) {
					return false;
				}

				$dbh = $this->get_db_object( $dbh_or_table );

				if ( $this->dbh_type_check( $dbh ) ) {
					if ( true === $this->use_mysqli ) {
						$client_version = mysqli_get_client_info( $dbh );
					} else {
						$client_version = mysql_get_client_info( $dbh );
					}

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
			case 'utf8mb4_520': // @since 4.6.0
				return version_compare( $version, '5.6', '>=' );
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
	 * The database version number
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 *
	 * @return false|string false on failure, version number on success
	 */
	public function db_version( $dbh_or_table = false ) {
		return preg_replace( '/[^0-9.].*/', '', $this->db_server_info( $dbh_or_table ) );
	}

	/**
	 * Retrieves full MySQL server information.
	 *
	 * @since 5.0.0
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 *
	 * @return string|false Server info on success, false on failure.
	 */
	public function db_server_info( $dbh_or_table = false ) {
		$dbh = $this->get_db_object( $dbh_or_table );

		if ( ! $this->dbh_type_check( $dbh ) ) {
			return false;
		}

		$server_info = ( true === $this->use_mysqli )
				? mysqli_get_server_info( $dbh )
				: mysql_get_server_info( $dbh );

		return $server_info;
	}

	/**
	 * Get the db connection object
	 *
	 * @since 1.0.0
	 *
	 * @param false|string|resource $dbh_or_table the database (the current database, the database housing the specified table, or the database of the MySQL resource)
	 */
	private function get_db_object( $dbh_or_table = false ) {

		// No database
		$dbh = false;

		// Database
		if ( $this->dbh_type_check( $dbh_or_table ) ) {
			$dbh = &$dbh_or_table;

			// Database
		} elseif ( ( false === $dbh_or_table ) && $this->dbh_type_check( $this->dbh ) ) {
			$dbh = &$this->dbh;

			// Table name
		} elseif ( is_string( $dbh_or_table ) ) {
			$dbh = $this->db_connect( "SELECT FROM {$dbh_or_table} {$this->users}" );
		}

		return $dbh;
	}

	/**
	 * Check databse object type.
	 *
	 * @param resource|mysqli $dbh Database resource.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function dbh_type_check( $dbh ) {
		if ( ( true === $this->use_mysqli ) && ( $dbh instanceof mysqli ) ) {
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
	 * @param string $host Host.
	 * @param int    $port Port or socket.
	 * @param float  $float_timeout Timeout as float number.
	 * @return bool true when $host:$post responds within $float_timeout seconds, else false
	 */
	public function check_tcp_responsiveness( $host, $port, $float_timeout ) {

		// Get the cache key
		$cache_key = $this->tcp_get_cache_key( $host, $port );

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

		// Try to get a new socket
		$socket = ( WP_DEBUG )
			? fsockopen( $host, $port, $errno, $errstr, $float_timeout )
			: @fsockopen( $host, $port, $errno, $errstr, $float_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// No socket
		if ( false === $socket ) {
			$this->tcp_cache_set( $cache_key, 'down' );

			return "[ > {$float_timeout} ] ({$errno}) '{$errstr}'";
		}

		// Close the socket
		fclose( $socket );

		// Using API
		$this->tcp_cache_set( $cache_key, 'up' );

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
	 * Should we try to ping the MySQL host?
	 *
	 * @since 4.1.0
	 *
	 * @param string $dbhname Database name.
	 *
	 * @return bool
	 */
	public function should_mysql_ping( $dbhname = '' ) {

		// Bail early if no MySQL ping
		if ( empty( $this->check_dbh_heartbeats ) ) {
			return false;
		}

		// Shouldn't happen
		if ( empty( $dbhname ) || empty( $this->dbhname_heartbeats[ $dbhname ] ) ) {
			return true;
		}

		// MySQL server has gone away
		if ( ! empty( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] ) && ( DB_SERVER_GONE_ERROR === $this->dbhname_heartbeats[ $dbhname ]['last_errno'] ) ) {
			unset( $this->dbhname_heartbeats[ $dbhname ]['last_errno'] );

			return true;
		}

		// More than 0.1 seconds of inactivity on that dbhname
		if ( microtime( true ) - $this->dbhname_heartbeats[ $dbhname ]['last_used'] > $this->recheck_timeout ) {
			return true;
		}

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
	 * Retrieves a tables character set.
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
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
				if ( ( 'utf8mb4' === $charset ) && ! $this->has_cap( 'utf8mb4' ) ) {
					$charset = 'utf8';
				}

				$charsets[ strtolower( $charset ) ] = true;
			}

			list( $type ) = explode( '(', $column->Type );  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			// A binary/blob means the whole query gets treated like this.
			if ( in_array( strtoupper( $type ), array(
				'BINARY',
				'VARBINARY',
				'TINYBLOB',
				'MEDIUMBLOB',
				'BLOB',
				'LONGBLOB',
			), true ) ) {
				$this->table_charset[ $tablekey ] = 'binary';

				return 'binary';
			}
		}

		// utf8mb3 is an alias for utf8.
		if ( isset( $charsets['utf8mb3'] ) ) {
			$charsets['utf8'] = true;
			unset( $charsets['utf8mb3'] );
		}

		// Check if we have more than one charset in play.
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
	 * Given a string, a character set and a table, ask the DB to check the string encoding.
	 * Classes that extend wpdb can override this function without needing to copy/paste
	 * all of wpdb::strip_invalid_text().
	 *
	 * NOTE: This must be called after LudicrousDB::db_connect, so that wpdb::dbh is set correctly
	 *
	 * @since 1.0.0
	 *
	 * @param string $string String to convert
	 * @param string $charset Character set to test against (uses MySQL character set names)
	 *
	 * @return mixed The converted string, or a WP_Error if the conversion fails
	 */
	protected function strip_invalid_text_using_db( $string, $charset ) {
		$query  = $this->prepare( "SELECT CONVERT( %s USING {$charset} )", $string );
		$result = $this->_do_query( $query, $this->dbh );

		// Bail with error if no result
		if ( empty( $result ) ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		// Fetch row
		$row = ( true === $this->use_mysqli )
			? mysqli_fetch_row( $result )
			: mysql_fetch_row( $result );

		// Bail with error if no rows
		if ( ! is_array( $row ) || count( $row ) < 1 ) {
			return new WP_Error( 'wpdb_convert_text_failure' );
		}

		return $row[0];
	}

	/** TCP Cache *************************************************************/

	/**
	 * Get the cache key used for TCP responses
	 *
	 * @since 3.0.0
	 *
	 * @param string $host Host
	 * @param string $port Port or socket.
	 *
	 * @return string
	 */
	protected function tcp_get_cache_key( $host, $port ) {
		return "{$host}:{$port}";
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

			// Make sure the global group is added
			$this->add_global_group();

			// Yes
			return true;
		}

		// No
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
			return wp_cache_get( $key, $this->cache_group );

		// Fallback to local cache
		} elseif ( ! empty( $this->tcp_cache[ $key ] ) ) {

			// Not expired
			if ( ! empty( $this->tcp_cache[ $key ]['expiration'] ) && ( time() < $this->tcp_cache[ $key ]['expiration'] ) ) {

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
			return wp_cache_set( $key, $value, $this->cache_group, $expires );

		// Fallback to local cache
		} else {
			$this->tcp_cache[ $key ] = array(
				'value'      => $value,
				'expiration' => time() + $expires
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
			return wp_cache_delete( $key, $this->cache_group );

		// Fallback to local cache
		} else {
			unset( $this->tcp_cache[ $key ] );
		}

		return true;
	}

	/**
	 * Add global cache group.
	 *
	 * Only run once, as that is all that is required.
	 *
	 * @since 4.3.0
	 */
	protected function add_global_group() {
		static $added = null;

		// Bail if added or caching not available yet
		if ( true === $added ) {
			return;
		}

		// Add the cache group
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( $this->cache_group );
		}

		// Set added
		$added = true;
	}

	/**
	 * Find a dbh name value for a given $dbh object.
	 *
	 * @since 5.0.0
	 *
	 * @param object $dbh The dbh object for which to find the dbh name
	 *
	 * @return string The dbh name
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
}
