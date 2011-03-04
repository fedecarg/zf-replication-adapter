# Database Replication Adapter

Database replication is an option that allows the content of one database to be replicated to another database or databases, providing a mechanism to scale out the database. Scaling out the database allows more activities to be processed and more users to access the database by running multiple copies of the databases on different machines.

The problem with monolithic database designs is that they don't establish an infrastructure that allows for rapid changes in business requirements. Here is where database replication comes into play. Replication can be used effectively for many different purposes, such as separating data entry and reporting, distributing load across servers, providing high availability, etc.

Zf_Orm_DataSource is a Zend Framework Replication Adapter class that supports the most commonly used replication scenarios:

## Single-Master Replication

In the simplest replication scenario, the master copy of directory data is held in a single read-write replica on one server called the supplier server. The supplier server also maintains changelog for this replica. On another server, called the consumer server, there can be multiple read-only replicas.

**Configuration array**

<pre>
<code>
$config = array(
    'adapter'        => 'Pdo_Mysql',
    'driver_options' => array(PDO::ATTR_TIMEOUT=>5),
    'username'       => 'root',
    'password'       => 'root',
    'dbname'         => 'test',
    'master_servers' => 1,
    'servers'        => array(
        array('host' => 'db.master-1.com'),
        array('host' => 'db.slave-1.com'),
        array('host' => 'db.slave-2.com')
    )
);

// or ...

$config = array(
    'adapter'        => 'Pdo_Mysql',
    'driver_options' => array(PDO::ATTR_TIMEOUT=>5),
    'dbname'         => 'test',
    'master_servers' => 1,
    'servers'        => array(
        array('host' => 'db.master-1.com', 'username' => 'user1', 'password'=>'pass1'),
        array('host' => 'db.slave-1.com', 'username' => 'user2', 'password' => 'pass2'),
        array('host' => 'db.slave-2.com', 'username' => 'user3', 'password' => 'pass3')
    )
);
</code>
</pre>

In the setup above, all writes will go to the master connection and all reads will be randomly distributed across the available slaves.

## Usage
<pre>
<code>
$dataSource = new Zf_Orm_DataSource($config);
$db = $dataSource->getConnection('slave')
$query = $db->select()->from('test');
$rows = $db->fetchAll($query);
</code>
</pre>

## Multi-Master Replication

This type of configuration can work with any number of consumer servers. Each consumer server holds a read-only replica. The consumers can receive updates from all the suppliers. The consumers also have referrals defined for all the suppliers to forward any update requests that the consumers receive.

<pre>
<code>
$config = array(
    'adapter'        => 'Pdo_Mysql',
    'driver_options' => array(PDO::ATTR_TIMEOUT=>5),
    'username'       => 'root',
    'password'       => 'root',
    'dbname'         => 'test',
    'master_servers' => 2,
    'master_read'    => true,
    'servers'        => array(
        array('host' => 'db.master-1.com'),
        array('host' => 'db.master-2.com')
    )
);
</code>
</pre>

## Using a distributed memory caching system

Database connections are expensive and it's very inefficient for an application to try to connect to a server that is down or not responding. A distributed memory caching system can help alleviate this problem by keeping a list of all the failed connections in memory, sharing that information across multiple servers and allowing the application to access it before attempting to open a connection.

To enable this option, you have to pass an instance of the Memcached adapter class:

<pre>
<code>
class Bootstrap extends Zend_Application_Bootstrap_Base {

    protected function _initCache() {
    	...
    }
    
    protected function _initDatabase() {
    	$config = include APPLICATION_PATH . '/config/database.php';
        $dataSource = new Zf_Orm_DataSource($config, $this->getResource('cache'), 'cache_tag');
        Zend_Registry::set('dataSource', $dataSource);
    }
}
</code>
</pre>

And here is a short example of how the Replication Adapter might be used in a ZF application:

<pre>
<code>
class TestDao {

    public function select() {
        $db = Zend_Registry::get('dataSource')->getConnection('slave');
        $query = $db->select()->from('test');
        return $db->fetchAll($query);
    }

    public function insert($data) {
        $db = Zend_Registry::get('dataSource')->getConnection('master');
        $db->insert('test', $data);
        return $db->lastInsertId();
    }
}
</code>
</pre>

# License

- New BSD License http://www.opensource.org/licenses/bsd-license.php
- Copyright (c) 2010, Federico Cargnelutti. All rights reserved.