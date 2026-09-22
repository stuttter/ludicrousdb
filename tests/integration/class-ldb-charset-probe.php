<?php
/**
 * Count charset changes in the WordPress integration regression.
 *
 * @package LudicrousDB
 */

/**
 * LudicrousDB subclass that counts charset changes.
 */
class LDB_Charset_Probe extends LudicrousDB {
	/**
	 * Number of times set_charset() was called.
	 *
	 * @var int
	 */
	public $set_charset_calls = 0;

	/**
	 * Count calls while retaining the production behavior.
	 *
	 * @param mysqli $dbh     The connection.
	 * @param string $charset Optional charset.
	 * @param string $collate Optional collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		++$this->set_charset_calls;
		parent::set_charset( $dbh, $charset, $collate );
	}
}
