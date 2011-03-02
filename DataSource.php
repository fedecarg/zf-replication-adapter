<?php
/**
 * Copyright (c) 2010, Federico Cargnelutti. All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. All advertising materials mentioning features or use of this software
 *    must display the following acknowledgment:
 *    This product includes software developed by Federico Cargnelutti.
 * 4. Neither the name of Federico Cargnelutti nor the names of its contributors 
 *    may be used to endorse or promote products derived from this software without 
 *    specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY FEDERICO CARGNELUTTI "AS IS" AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL FEDERICO CARGNELUTTI BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Zf
 * @package     Zf_Orm
 * @author      Federico Cargnelutti <fedecarg@gmail.com>
 * @copyright   Copyright (c) 2010 Federico Cargnelutti
 * @license     New BSD License
 * @version     $Id: $
 */

/**
 * @category    Zf
 * @package     Zf_Orm
 * @author      Federico Cargnelutti <fedecarg@gmail.com>
 * @copyright   Copyright (c) 2010 Federico Cargnelutti
 * @license     New BSD License
 * @version     $Id: $
 */
class Zf_Orm_DataSourceException extends Zf_Orm_Exception {}

class Zf_Orm_DataSource
{    
    const SUPPLIER_SERVER     = 'master';
    const CONSUMER_SERVER     = 'slave';
    const ACTIVE_CONNECTION   = '%s_datasource_active_connection_%s';
    const FAILED_CONNECTIONS  = '%s_datasource_failed_connections';
    
    /**
     * @var array
     */
    private $config = array();
    
    /**
     * @var Zend_Cache_Core
     */
    private $cache = null;
    
    /**
     * @var string
     */
    private $cacheTag = '';
    
    /**
     * @var array
     */
    private $connections = array();
    
    /**
     * Class constructor.
     *
     * @param array|Zend_Config $config
     * @param Zend_Cache_Core $cache
     * @param string $cacheTag
     */
    public function __construct($config, Zend_Cache_Core $cache, $cacheTag)
    {
        $this->setConfig($config);
        $this->setCache($cache);
        $this->setCacheTag($cacheTag);
    }
    
    /**
     * Set configuration array.
     *
     * @param array|Zend_Config $config
     * @return void
     */
    public function setConfig($config)
    {
        if ($config instanceof Zend_Config) {
            $config = $config->toArray();
        }
        $this->config = $config;
    }
    
    /**
     * Return configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }
    
    /**
     * Set instance of Zend_Cache_Core.
     *
     * @param Zend_Cache_Core $cache
     * @return void
     */
    public function setCache(Zend_Cache_Core $cache)
    {
        $this->cache = $cache;
    }
    
    /**
     * Return instance of Zend_Cache_Core.
     *
     * @return Zend_Cache_Core
     */
    public function getCache()
    {
        return $this->cache;
    }
    
    /**
     * Set cache tag name.
     *
     * @param string
     * @return void
     */
    public function setCacheTag($name)
    {
        $this->cacheTag = $name;
    }
    
    /**
     * Return cache tag name.
     *
     * @return string
     */
    public function getCacheTag()
    {
        return $this->cacheTag;
    }
    
    /**
     * Set an instance of Zend_Db_Adapter_Abstract.
     * 
     * @param Zend_Db_Adapter_Abstract $conn
     * @param string $server Options: master, slave
     * @return void
     */
    public function setConnection(Zend_Db_Adapter_Abstract $conn, $server)
    {
        $namespace = sprintf(self::ACTIVE_CONNECTION, $this->getCacheTag(), strtolower($server));
        $this->connections[$namespace] = $conn;
    }
    
    /**
     * Return an instance of Zend_Db_Adapter_Abstract.
     * 
     * @param string $server master (supplier) or slave (consumer)
     * @return Zend_Db_Adapter_Abstract
     * @throws Zf_Orm_DataSourceException
     */
    public function getConnection($server)
    {
        $server = strtolower($server);
        $namespace = sprintf(self::ACTIVE_CONNECTION, $this->getCacheTag(), $server);
        if ($this->hasConnection($namespace)) {
            return $this->connections[$namespace];
        }
        
        $failedCacheKey = sprintf(self::FAILED_CONNECTIONS, $this->getCacheTag());
        $result = $this->getCache()->load($failedCacheKey);
        $failed = ($result && is_array($result)) ? $result : array();
        
        $servers = $this->getListOfServers($server);
        $keys = (array) array_rand($servers, count($servers));
        foreach ($keys as $i => $key) {
            if (in_array($key, $failed)) {
                continue;
            }
            $connection = $this->createConnection($servers[$key]);
            if ($connection instanceof Zend_Db_Adapter_Abstract) {
                $this->setConnection($connection, $server);
                return $connection;
            }
            $failed[] = $key;
            $this->getCache()->save(array_unique($failed), $failedCacheKey, array(), 30);
        }
        throw new Zf_Orm_DataSourceException(sprintf('Unable to connect to "%s" server', $server));
    }
    
    /**
     * Verify that a given connection name exists.
     * 
     * @param string $name
     * @return boolean
     */
    public function hasConnection($name)
    {
        return array_key_exists($name, $this->connections);
    }
    
    /**
     * Create an instance of Zend_Db_Adapter_Abstract.
     *
     * @param array $server master (supplier) or slave (consumer)
     * @return Zend_Db_Adapter_Abstract|false
     * @see Zend_Db
     */
    public function createConnection($server)
    {
        $config = $this->getConfig();
        foreach ($config as $key => $value) {
            if ('servers' !== $key && !array_key_exists($key, $server)) {
                $server[$key] = $value;
            }
        }
        $db = Zend_Db::factory($config['adapter'], $server);
        if ($this->isConnected($db)) {
            return $db;
        }
        return false;
    }
    
    /**
     * Verify that we have a valid connection.
     * 
     * @param Zend_Db_Adapter_Abstract $adapter
     * @return boolean
     * @throws Zf_Orm_DataSourceException
     */
    public function isConnected(Zend_Db_Adapter_Abstract $adapter)
    {
        try {
            return ($adapter->getConnection()) ? true : false;
        } catch (Zend_Exception $e) {
            throw new Zf_Orm_DataSourceException($e->getMessage());
        }
    }
    
    /**
     * Return list of database servers that will be used to create a 
     * connection.
     * 
     * @param string $server master (supplier) or slave (consumer)
     * @return array
     */
    public function getListOfServers($server)
    {
        $config = $this->getConfig();
        $servers = (isset($config['servers'])) ? $config['servers'] : array();
        $masterServers = (isset($config['master_servers'])) ? $config['master_servers'] : 1;
        if (self::SUPPLIER_SERVER === $server) {
            $servers = array_slice($servers, 0, $masterServers);
        } elseif (self::CONSUMER_SERVER === $server) {
            $masterRead = (isset($config['master_read'])) ? $config['master_read'] : false;
            if (false === $masterRead) {
                $servers = array_slice($servers, $masterServers, count($servers), true);
            }
        }
        return $servers;
    }
}
