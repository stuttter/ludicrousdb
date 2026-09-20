<?php

/**
 * Minimal wpdb behavior required by isolated LudicrousDB tests.
 *
 * @phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital
 */
class wpdb {
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
}
