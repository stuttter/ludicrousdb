<?php

/**
 * Minimal wpdb behavior required by isolated LudicrousDB tests.
 *
 * @phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital
 */
class wpdb {
	/**
	 * Database user.
	 *
	 * @var string
	 */
	protected $dbuser = '';

	/**
	 * Database password.
	 *
	 * @var string
	 */
	protected $dbpassword = '';

	/**
	 * Database name.
	 *
	 * @var string
	 */
	protected $dbname = '';

	/**
	 * Database host.
	 *
	 * @var string
	 */
	protected $dbhost = '';

	/**
	 * Current character set.
	 *
	 * @var string
	 */
	public $charset = '';

	/**
	 * Current collation.
	 *
	 * @var string
	 */
	public $collate = '';

	/**
	 * Whether errors are displayed.
	 *
	 * @var bool
	 */
	public $show_errors = false;

	/**
	 * Compatibility properties handled by wpdb magic methods.
	 *
	 * @var array
	 */
	private $compat_properties = array();

	/**
	 * Retrieve a compatibility property.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( property_exists( $this, $name ) ) {
			return $this->{$name};
		}

		return isset( $this->compat_properties[ $name ] )
			? $this->compat_properties[ $name ]
			: null;
	}

	/**
	 * Store a compatibility property.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Property value.
	 */
	public function __set( $name, $value ) {
		$this->compat_properties[ $name ] = $value;
	}

	/**
	 * Enable or disable error display.
	 *
	 * @param bool $show Whether errors should be displayed.
	 */
	public function show_errors( $show = true ) {
		$this->show_errors = $show;
	}

	/**
	 * Return the requested character set and collation.
	 *
	 * @param string $charset Character set.
	 * @param string $collate Collation.
	 * @return array
	 */
	public function determine_charset( $charset, $collate ) {
		return array(
			'charset' => $charset,
			'collate' => $collate,
		);
	}

	/**
	 * Report capabilities implemented by the installed wpdb version.
	 *
	 * @param string $db_cap Capability name.
	 * @return bool
	 */
	public function has_cap( $db_cap ) {
		return 'identifier_placeholders' === $db_cap;
	}

	/**
	 * Parse a database host like WordPress 4.9 and later.
	 *
	 * @param string $host Database host.
	 * @return array|false
	 */
	public function parse_db_host( $host ) {
		$socket  = null;
		$is_ipv6 = false;

		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			$pattern = '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#';
			$is_ipv6 = true;
		} else {
			$pattern = '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
		}

		$matches = array();
		if ( 1 !== preg_match( $pattern, $host, $matches ) ) {
			return false;
		}

		$host = ! empty( $matches['host'] ) ? $matches['host'] : '';
		$port = ! empty( $matches['port'] ) ? (int) $matches['port'] : null;

		return array( $host, $port, $socket, $is_ipv6 );
	}
}
