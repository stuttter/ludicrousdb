# LudicrousDB

LudicrousDB is an advanced database interface for WordPress that supports replication, failover, load balancing, and partitioning, based on Automattic's HyperDB drop-in.

## 0. Installation

### Files

Copy the main `ludicrousdb` plugin folder and its contents to either:

* `wp-content/plugins/ludicrousdb/`
* `wp-content/mu-plugins/ludicrousdb/`

It does not matter which one; LudicrousDB will figure it out. The folder name should be exactly `ludicrousdb`. Be careful when you use "Download ZIP" from GitHub and unzip it.

### Drop-ins

WordPress supports a few "drop-in" style plugins, used for advanced overriding of a few specific pieces of functionality.

LudicrousDB includes 3 basic database drop-ins:

* `db.php` <-> `wp-content/db.php` - Bootstrap for replacement `$wpdb` object
* `db-error.php` <-> `wp-content/db-error.php` - Endpoint for fatal database error output to users
* `db-config.php` <-> `ABSPATH/db-config.php` - For configuring your database environment

You'll probably want to copy these files to their respective locations, and modify them once you're comfortable with what they do and how they work.

## 1. Configuration

LudicrousDB can manage connections to a large number of databases. Queries are distributed to appropriate servers by mapping table names to datasets.

A dataset is defined as a group of tables that are located in the same database. There may be similarly-named databases containing different tables on different servers. There may also be many replicas of a database on different servers. The term "dataset" removes any ambiguity. Consider a dataset as a group of tables that can be mirrored on many servers.

Configuring LudicrousDB involves defining databases and datasets. Defining a database involves specifying the server connection details, the dataset it contains, and its capabilities and priorities for reading and writing. Defining a dataset involves specifying its exact table names or registering one or more callback functions that translate table names to datasets.

### Sample Configuration 1: Default Server

This is the most basic way to add a server to LudicrousDB using only the required parameters: host, user, password, name. This adds the DB defined in wp-config.php as a read/write server for the 'global' dataset. (Every table is in 'global' by default.)

```php
$wpdb->add_database( array(
    'host'     => DB_HOST,     // If port is other than 3306, use host:port.
    'user'     => DB_USER,
    'password' => DB_PASSWORD,
    'name'     => DB_NAME,
) );
```

This adds the same server again, only this time it is configured as a replica. The last three parameters are set to the defaults but are shown for clarity.

```php
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

### Sample Configuration 2: Partitioning

This example shows a setup where the multisite blog tables have been separated from the global dataset.

```php
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

### Configuration Functions

#### add_database()

```php
$wpdb->add_database( $database );
```

`$database` is an associative array with these parameters:

```php
host          (required) Hostname with optional :port. Default port is 3306.
user          (required) MySQL user name.
password      (required) MySQL user password.
name          (required) MySQL database name.
read          (optional) Whether server is readable. Default is 1 (readable).
                         Also used to assign preference. See "Network topology".
write         (optional) Whether server is writable. Default is 1 (writable).
                         Also used to assign preference in multi-primary mode.
dataset       (optional) Name of dataset. Default is 'global'.
timeout       (optional) Seconds to wait for TCP responsiveness. Default is 0.2
lag_threshold (optional) The minimum lag on a replica in seconds before we consider it lagged.
                         Set null to disable. When not set, the value of
                         $wpdb->database_defaults['lag_threshold'] is used.
```

#### add_table()

```php
$wpdb->add_table( $dataset, $table );
```

`$dataset` and `$table` are strings.

#### add_callback()

```php
$wpdb->add_callback( $callback, $callback_group = 'dataset' );
```

`$callback` is a callable function or method. `$callback_group` is the group of callbacks, this `$callback` belongs to.

Callbacks are executed in the order in which they are registered until one of them returns something other than null.

The default `$callback_group` is 'dataset'. Callback in this group  will be called with two arguments and expected to compute a dataset or return null.

```php
$dataset = $callback($table, &$wpdb);
```

Anything evaluating to false will cause the query to be aborted.

For more complex setups, the callback may be used to overwrite properties of `$wpdb` or variables within `LudicrousDB::connect_db()`. If a callback returns an array, LudicrousDB will extract the array. It should be an associative array and it should include a `$dataset` value corresponding to a database added with `$wpdb->add_database()`. It may also include `$server`, which will be extracted to overwrite the parameters of each randomly selected database server prior to connection. This allows you to dynamically vary parameters such as the host, user, password, database name, lag_threshold and TCP check timeout.

## 2. Primary & Replica Databases

A database definition can include 'read' and 'write' parameters. These operate as boolean switches but they are typically specified as integers. They allow or disallow use of the database for reading or writing.

A primary database might be configured to allow reading and writing:

```php
'write' => 1,
'read'  => 1,
```

while a replica would be allowed only to read:

```php
'write' => 0,
'read'  => 1,
```

It might be advantageous to disallow reading from the primary, such as when there are many replicas available and the primary is very busy with writes.

```php
'write' => 1,
'read'  => 0,
```

LudicrousDB tracks the tables that it has written since instantiation and sending subsequent read queries to the same server that received the write query. Thus a primary set up this way will still receive read queries, but only subsequent to writes.

## 3. Network topology / Datacenter awareness

When your databases are located in separate physical locations there is typically an advantage to connecting to a nearby server instead of a more distant one. The read and write parameters can be used to place servers into logical groups of more or less preferred connections. Lower numbers indicate greater preference.

This configuration instructs LudicrousDB to try reading from one of the local replicas at random. If that replica is unreachable or refuses the connection, the other replica will be tried, followed by the primary, and finally the remote replicas in random order.

```php
Local replica 1:   'write' => 0, 'read' => 1,
Local replica 2:   'write' => 0, 'read' => 1,
Local primary:     'write' => 1, 'read' => 2,
Remote replica 1:  'write' => 0, 'read' => 3,
Remote replica 2:  'write' => 0, 'read' => 3,
```

In the other datacenter, the primary would be remote. We would take that into account while deciding where to send reads. Writes would always be sent to the primary, regardless of proximity.

```php
Local replica 1:   'write' => 0, 'read' => 1,
Local replica 2:   'write' => 0, 'read' => 1,
Remote replica 1:  'write' => 0, 'read' => 2,
Remote replica 2:  'write' => 0, 'read' => 2,
Remote primary:    'write' => 1, 'read' => 3,
```

There are many ways to achieve different configurations in different locations. You can deploy different config files. You can write code to discover the web server's location, such as by inspecting `$_SERVER` or `php_uname()`, and compute the read/write parameters accordingly.

## 4. Replication Lag

LudicrousDB accommodates replica lag by making decisions based on the defined lag threshold. If the lag threshold is not set, it ignores replica lag. Otherwise, it tries to find a non-lagged replica before connecting to a lagged one.

A replica is considered lagged when its lag is greater than the per-database `lag_threshold`, or the default in `$wpdb->database_defaults['lag_threshold']`. A `dataset` callback may also override the threshold by returning `$server['lag_threshold']`.

LudicrousDB does not impose a particular replication monitor. Register two callbacks to supply lag data:

```php
$wpdb->add_callback( 'ludicrousdb_get_lag_cache', 'get_lag_cache' );
```

and

```php
$wpdb->add_callback( 'ludicrousdb_get_lag', 'get_lag' );
```

The first callback runs before LudicrousDB connects to a replica. It should return the cached lag in seconds, or `false` when the lag is unknown. `$wpdb->lag_cache_key` identifies the replica endpoint.

The second callback runs after the replica connection is established. It should measure and return lag in seconds, or `false` when the lag is unknown. The active connection is available in `$wpdb->dbhs[ $wpdb->dbhname ]`.

## Sample replication lag detection configuration

The following example reads a heartbeat timestamp replicated from the primary. [Percona Toolkit's `pt-heartbeat`](https://docs.percona.com/percona-toolkit/pt-heartbeat.html) can maintain such a table. Change `heartbeat.heartbeat` to the qualified database and table used by your monitor.

The LudicrousDB database user needs `SELECT` access to the heartbeat table. The query uses a qualified table name so it does not change the database selected for the WordPress query that follows.

This example uses the WordPress object cache when it is available. With a persistent object-cache drop-in, the value can be shared across requests; otherwise the live check remains authoritative.

```php
if ( ! defined( 'LUDICROUSDB_LAG_CACHE_TTL' ) ) {
    define( 'LUDICROUSDB_LAG_CACHE_TTL', 30 );
}

$wpdb->add_callback( 'ludicrousdb_get_lag_cache', 'get_lag_cache' );
$wpdb->add_callback( 'ludicrousdb_get_lag', 'get_lag' );

function ludicrousdb_get_lag_cache( $wpdb ) {
    if ( ! function_exists( 'wp_cache_get' ) || empty( $GLOBALS['wp_object_cache'] ) ) {
        return false;
    }

    $lag = wp_cache_get( $wpdb->lag_cache_key, 'ludicrousdb-lag' );

    return is_numeric( $lag ) ? (float) $lag : false;
}

function ludicrousdb_get_lag( $wpdb ) {
    if ( empty( $wpdb->dbhs[ $wpdb->dbhname ] ) ) {
        return false;
    }

    $dbh    = $wpdb->dbhs[ $wpdb->dbhname ];
    $result = mysqli_query(
        $dbh,
        'SELECT GREATEST( 0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP( ts ) ) AS lag
        FROM heartbeat.heartbeat
        ORDER BY ts DESC
        LIMIT 1'
    );

    if ( false === $result ) {
        return false;
    }

    $row = mysqli_fetch_assoc( $result );
    mysqli_free_result( $result );

    if ( ! is_array( $row ) || ! isset( $row['lag'] ) || ! is_numeric( $row['lag'] ) ) {
        return false;
    }

    $lag = (float) $row['lag'];

    if ( function_exists( 'wp_cache_set' ) && ! empty( $GLOBALS['wp_object_cache'] ) ) {
        wp_cache_set( $wpdb->lag_cache_key, $lag, 'ludicrousdb-lag', LUDICROUSDB_LAG_CACHE_TTL );
    }

    return $lag;
}
```
