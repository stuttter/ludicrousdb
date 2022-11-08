# LudicrousDB

LudicrousDB is an advanced database interface for WordPress that supports replication, fail-over, load balancing, and partitioning, based on Automattic's HyperDB drop-in.

## Installation

### Files

Copy the main `ludicrousdb` plugin folder & its contents to either:

* `wp-content/plugins/ludicrousdb/`
* `wp-content/mu-plugins/ludicrousdb/`

It does not matter which one; LudicrousDB will figure it out. The folder name should be exactly `ludicrousdb`. Be careful when you do "Download ZIP" from github and unzip.

### Drop-ins

WordPress supports a few "drop-in" style plugins, used for advanced overriding of a few specific pieces of functionality.

LudicrousDB includes 3 basic database drop-ins:

* `db.php` <-> `wp-content/db.php` - Bootstrap for replacement `$wpdb` object
* `db-error.php` <-> `wp-content/db-error.php` - Endpoint for fatal database error output to users
* `db-config.php` <-> `ABSPATH/db-config.php` - For configuring your database environment

You'll probably want to copy these files to their respective locations, and modify them once you're comfortable with what they do and how they work.

## Configuration

LudicrousDB can manage connections to a large number of databases. Queries are distributed to appropriate servers by mapping table names to datasets.

A dataset is defined as a group of tables that are located in the same database. There may be similarly-named databases containing different tables on different servers. There may also be many replicas of a database on different servers. The term "dataset" removes any ambiguity. Consider a dataset as a group of tables that can be mirrored on many servers.

Configuring LudicrousDB involves defining databases and datasets. Defining a database involves specifying the server connection details, the dataset it contains, and its capabilities and priorities for reading and writing. Defining a dataset involves specifying its exact table names or registering one or more callback functions that translate table names to datasets.

## Sample Configuration 1: Default Server

This is the most basic way to add a server to LudicrousDB using only the required parameters: host, user, password, name. This adds the DB defined in wp-config.php as a read/write server for the 'global' dataset. (Every table is in 'global' by default.)

```
$wpdb->add_database( array(
	'host'     => DB_HOST,     // If port is other than 3306, use host:port.
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
) );
```

This adds the same server again, only this time it is configured as a slave. The last three parameters are set to the defaults but are shown for clarity.

```
$wpdb->add_database( array(
	'host'     => DB_HOST,     // If port is other than 3306, use host:port.
	'user'     => DB_USER,
	'password' => DB_PASSWORD,
	'name'     => DB_NAME,
	'write'    => 0,
	'read'     => 1,
	'dataset'  => 'global',
	'timeout'  => 0.2,
) );
```

## Sample Configuration 2: Partitioning

This example shows a setup where the multisite blog tables have been separated from the global dataset.

```
$wpdb->add_database( array(
	'host'     => 'global.db.example.com',
	'user'     => 'globaluser',
	'password' => 'globalpassword',
	'name'     => 'globaldb',
) );

$wpdb->add_database( array(
	'host'     => 'blog.db.example.com',
	'user'     => 'bloguser',
	'password' => 'blogpassword',
	'name'     => 'blogdb',
	'dataset'  => 'blog',
) );

$wpdb->add_callback( 'my_db_callback' );

// Multisite blog tables are "{$base_prefix}{$blog_id}_*"
function my_db_callback( $query, $wpdb ) {
	if ( preg_match("/^{$wpdb->base_prefix}\d+_/i", $wpdb->table) ) {
		return 'blog';
	}
}
```

## Configuration Functions

### add_database()

```
$wpdb->add_database( $database );
```

`$database` is an associative array with these parameters:

```
host          (required) Hostname with optional :port. Default port is 3306.
user          (required) MySQL user name.
password      (required) MySQL user password.
name          (required) MySQL database name.
read          (optional) Whether server is readable. Default is 1 (readable).
                         Also used to assign preference. See "Network topology".
write         (optional) Whether server is writable. Default is 1 (writable).
                         Also used to assign preference in multi-master mode.
dataset       (optional) Name of dataset. Default is 'global'.
timeout       (optional) Seconds to wait for TCP responsiveness. Default is 0.2
lag_threshold (optional) The minimum lag on a slave in seconds before we consider it lagged.
                         Set null to disable. When not set, the value of $wpdb->default_lag_threshold is used.
```

### add_table()

```
$wpdb->add_table( $dataset, $table );
```

`$dataset` and `$table` are strings.

### add_callback()

```
$wpdb->add_callback( $callback, $callback_group = 'dataset' );
```

`$callback` is a callable function or method. `$callback_group` is the group of callbacks, this `$callback` belongs to.

Callbacks are executed in the order in which they are registered until one of them returns something other than null.

The default `$callback_group` is 'dataset'. Callback in this group  will be called with two arguments and expected to compute a dataset or return null.

```
$dataset = $callback($table, &$wpdb);
```

Anything evaluating to false will cause the query to be aborted.

For more complex setups, the callback may be used to overwrite properties of `$wpdb` or variables within `LudicrousDB::connect_db()`. If a callback returns an array, LudicrousDB will extract the array. It should be an associative array and it should include a `$dataset` value corresponding to a database added with `$wpdb->add_database()`. It may also include `$server`, which will be extracted to overwrite the parameters of each randomly selected database server prior to connection. This allows you to dynamically vary parameters such as the host, user, password, database name, lag_threshold and TCP check timeout.

## Masters and slaves

A database definition can include 'read' and 'write' parameters. These operate as boolean switches but they are typically specified as integers. They allow or disallow use of the database for reading or writing.

A master database might be configured to allow reading and writing:

```
'write' => 1,
'read'  => 1,
```

while a slave would be allowed only to read:

```
'write' => 0,
'read'  => 1,
```

It might be advantageous to disallow reading from the master, such as when there are many slaves available and the master is very busy with writes.

```
  'write' => 1,
  'read'  => 0,
```

LudicrousDB tracks the tables that it has written since instantiation and sending subsequent read queries to the same server that received the write query. Thus a master set up this way will still receive read queries, but only subsequent to writes.


## Network topology / Datacenter awareness

When your databases are located in separate physical locations there is typically an advantage to connecting to a nearby server instead of a more distant one. The read and write parameters can be used to place servers into logical groups of more or less preferred connections. Lower numbers indicate greater preference.

This configuration instructs LudicrousDB to try reading from one of the local slaves at random. If that slave is unreachable or refuses the connection, the other slave will be tried, followed by the master, and finally the remote slaves in random order.

```
Local slave 1:   'write' => 0, 'read' => 1,
Local slave 2:   'write' => 0, 'read' => 1,
Local master:    'write' => 1, 'read' => 2,
Remote slave 1:  'write' => 0, 'read' => 3,
Remote slave 2:  'write' => 0, 'read' => 3,
```

In the other datacenter, the master would be remote. We would take that into account while deciding where to send reads. Writes would always be sent to the master, regardless of proximity.

```
Local slave 1:   'write' => 0, 'read' => 1,
Local slave 2:   'write' => 0, 'read' => 1,
Remote slave 1:  'write' => 0, 'read' => 2,
Remote slave 2:  'write' => 0, 'read' => 2,
Remote master:   'write' => 1, 'read' => 3,
```

There are many ways to achieve different configurations in different locations. You can deploy different config files. You can write code to discover the web server's location, such as by inspecting `$_SERVER` or `php_uname()`, and compute the read/write parameters accordingly.

## Slaves lag awareness

LudicrousDB accommodates slave lag by making decisions, based on the defined lag threshold. If the lag threshold is not set, it will ignore the slave lag. Otherwise, it will try to find a non-lagged slave, before connecting to a lagged one.

A slave is considered lagged, if it's replication lag is bigger than the lag threshold you have defined in `$wpdb->$default_lag_threshold` or in the per-database settings. You can also rewrite the lag threshold, by returning `$server['lag_threshold']` variable with the 'dataset' group callbacks.

LudicrousDB does not check the lag on the slaves. You have to define two callbacks callbacks to do that:

```
$wpdb->add_callback( $callback, 'get_lag_cache' );
```

and

```
$wpdb->add_callback( $callback, 'get_lag' );
```

The first one is called, before connecting to a slave and should return the replication lag in seconds or false, if unknown, based on `$wpdb->lag_cache_key`.

The second callback is called after a connection to a slave is established. It should return it's replication lag or false, if unknown, based on the connection in `$wpdb->dbhs[ $wpdb->dbhname ]`.

## Sample replication lag detection configuration.

To detect replication lag, try mk-heartbeat: (http://www.maatkit.org/doc/mk-heartbeat.html)

This implementation requires the database user to have read access to the heartbeat table.

The cache uses shared memory for portability. Can be modified to work with Memcached, APC and etc.

```
$wpdb->lag_cache_ttl = 30;
$wpdb->shmem_key = ftok( __FILE__, "Y" );
$wpdb->shmem_size = 128 * 1024;

$wpdb->add_callback( 'get_lag_cache', 'get_lag_cache' );
$wpdb->add_callback( 'get_lag',       'get_lag' );

function get_lag_cache( $wpdb ) {
	$segment = shm_attach( $wpdb->shmem_key, $wpdb->shmem_size, 0600 );
	$lag_data = @shm_get_var( $segment, 0 );
	shm_detach( $segment );

	if ( !is_array( $lag_data ) || !is_array( $lag_data[ $wpdb->lag_cache_key ] ) )
		return false;

	if ( $wpdb->lag_cache_ttl < time() - $lag_data[ $wpdb->lag_cache_key ][ 'timestamp' ] )
		return false;

	return $lag_data[ $wpdb->lag_cache_key ][ 'lag' ];
}

function get_lag( $wpdb ) {
	$dbh = $wpdb->dbhs[ $wpdb->dbhname ];

	if ( !mysql_select_db( 'heartbeat', $dbh ) )
		return false;

	$result = mysql_query( "SELECT UNIX_TIMESTAMP() - UNIX_TIMESTAMP(ts) AS lag FROM heartbeat LIMIT 1", $dbh );

	if ( !$result || false === $row = mysql_fetch_assoc( $result ) )
		return false;

	// Cache the result in shared memory with timestamp
	$sem_id = sem_get( $wpdb->shmem_key, 1, 0600, 1 ) ;
	sem_acquire( $sem_id );
	$segment = shm_attach( $wpdb->shmem_key, $wpdb->shmem_size, 0600 );
	$lag_data = @shm_get_var( $segment, 0 );

	if ( !is_array( $lag_data ) )
		$lag_data = array();

	$lag_data[ $wpdb->lag_cache_key ] = array( 'timestamp' => time(), 'lag' => $row[ 'lag' ] );
	shm_put_var( $segment, 0, $lag_data );
	shm_detach( $segment );
	sem_release( $sem_id );

	return $row[ 'lag' ];
}
```
