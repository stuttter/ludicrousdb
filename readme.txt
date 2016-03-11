=== LudicrousDB ===
Contributors: matt, andy, ryan, mdawaffe, vnsavage, automattic, johnjamesjacoby
Tags: mysql, scaling, performance, availability
Requires at least: 4.2
Tested up to: 4.3
Stable tag: 2.0.0

LudicrousDB is a database class that supports replication, failover, load balancing, and partitioning, based on Automattic's HyperDB.

== Description ==

LudicrousDB is a very advanced database class that replaces a few of the WordPress built-in database functions. The main differences are:
* LudicrousDB can be connect to an arbitrary number of database servers,
* LudicrousDB inspects each query to determine the appropriate database.

It supports:

* Read and write servers (replication)
* Configurable priority for reading and writing
* Local and remote datacenters
* Private and public networks
* Different tables on different databases/hosts
* Smart post-write master reads
* Failover for downed host
* Advanced statistics for profiling

It is based on the code currently used in production on WordPress.org with many MySQL servers spanning multiple datacenters.

== Installation ==

Nothing goes in the plugins directory.

1. Enter a configuration in `db-config.php`.

2. Deploy `db-config.php` in the directory that holds `wp-config.php`. This may be the WordPress root or one level above. It may also be anywhere else the web server can see it; in this case, define `DB_CONFIG_FILE` in `wp-config.php`.

3. Deploy `db.php` to the `/wp-content/` directory. Simply placing this file activates it. To deactivate it, move it from that location or move the config file.

4. Optional - deploy `db-error.php` to the `/wp-content/` directory. This file is used when database connections critically fail.

Any value of `WP_USE_MULTIPLE_DB` will be ignored by LudicrousDB.

== Frequently Asked Questions ==

= What can I do with LudicrousDB that I can't do with WPDB? =

WordPress.org, the most complex LudicrousDB installation, manages millions of tables spanning thousands of databases. Dynamic configuration logic allows LudicrousDB to compute the location of tables by querying a central database. Custom scripts constantly balance database server resources by migrating tables and updating their locations in the central database.

Stretch your imagination. You could create a dynamic configuration using persistent caching to gather intelligence about the state of the network. The only constant is the name of the configuration file. The rest, as they say, is PHP.

= How does LudicrousDB support replication? =

LudicrousDB does not provide replication services. That is done by configuring MySQL servers for replication. LudicrousDB can then be configured to use these servers appropriately, e.g. by connecting to master servers to perform write queries.

= How does LudicrousDB support load balancing? =

LudicrousDB randomly selects database connections from priority groups that you configure. The most advantageous connections are tried first. Thus you can optimize your configuration for network topology, hardware capability, or any other scheme you invent.

= How does LudicrousDB support failover? =

Failover describes how LudicrousDB deals with connection failures. When LudicrousDB fails to connect to one database, it tries to connect to another database that holds the same data. If replication hasn't been set up, LudicrousDB tries reconnecting a few times before giving up.

= How does LudicrousDB support partitioning? =

LudicrousDB allows tables to be placed in arbitrary databases. It can use callbacks you write to compute the appropriate database for a given query. Thus you can partition your site's data according to your own scheme and configure LudicrousDB accordingly.

= Is there any advantage to using LudicrousDB with just one database server? =

None that has been measured. LudicrousDB does at least try again before giving up connecting, so it might help in cases where the web server is momentarily unable to connect to the database server.

One way LudicrousDB differs from WPDB is that LudicrousDB does not attempt to connect to a database until a query is made. Thus a site with sufficiently aggressive persistent caching could remain read-only accessible despite the database becoming unreachable.

= What if all database servers for a dataset go down? =

Since LudicrousDB attempts a connection only when a query is made, your WordPress installation will not kill the site with a database error, but will let the code decide what to do next on an unsuccessful query. If you want to do something different, like setting a custom error page or kill the site, you need to define the 'db_connection_error' callback in your db-config.php.

== Changelog ==

= 2.0 =
* Fork from HyperDB
* Include utf8mb4 support (for WordPress 4.2 compatibility)
* Remove support for WPDB_PATH as require_wp_db() prevents it

= 1.1 =
* Extended callbacks functionality
* Added connection error callback
* Added replication lag detection support

= 1.0 =
* Removed support for WPMU and BackPress.
* New class with inheritance: hyperdb extends wpdb.
* New instantiation scheme: $wpdb = new hyperdb(); then include config. No more $db_* globals.
* New configuration file name (db-config.php) and logic for locating it. (ABSPATH, dirname(ABSPATH), or pre-defined)
* Added fallback to wpdb in case database config not found.
* Renamed servers to databases in config in an attempt to reduce ambiguity.
* Added config interface functions to hyperdb: add_database, add_table, add_callback.
* Refactored db_server array to simplify finding a server.
* Removed native support for datacenters and partitions. The same effects are accomplished by read/write parameters and dataset names.
* Removed preg pattern support from $db_tables. Use callbacks instead.
* Removed delay between connection retries and avoid immediate retry of same server when others are available to try.
* Added connection stats.
* Added save_query_callback for custom debug logging.
* Refined SRTM granularity. Now only send reads to masters when the written table is involved.
* Improved connection reuse logic and added mysql_ping to recover from "server has gone away".
* Added min_tries to configure the minimum number of connection attempts before bailing.
* Added WPDB_PATH constant. Define this if you'd rather not use ABSPATH . WPINC . '/wp-db.php'.
