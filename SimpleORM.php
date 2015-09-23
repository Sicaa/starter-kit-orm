<?php

namespace StarterKit\ORM;

use \StarterKit\SQL\SimplePDO as SimplePDO;
use \StarterKit\Cache\Memcached as Memcached;

class SimpleORM
{
	protected $_caching = false;

	protected static $_cacheHost   = NULL;
	protected static $_cachePort   = NULL;
	protected static $_cachePrefix = NULL;
	protected static $_cacheExpire = 300;

	protected static $_PDOInstance = NULL;

	protected static $_table = NULL;

	public function __construct($id_ = NULL, $caching_ = false)
	{
		$this->_caching = (bool) $caching_;

		if (!is_null($id_)) {
			if (is_null(static::$_PDOInstance)) {
				static::$_PDOInstance = \StarterKit\SQL\SimplePDO::getInstance();
			}
			$this->getRecord((int) $id_);
		}
	}

	public static function getTable()
	{
		return static::$_table;
	}

	public static function setCachingRules($host_, $port_, $prefix_, $expire_)
	{
		static::$_cacheHost   = $host_;
		static::$_cachePort   = (int) $port_;
		static::$_cachePrefix = $prefix_;
		static::$_cacheExpire = (int) $expire_;
		return true;
	}

	public static function setPDOInstance(SimplePDO $PDOInstance_)
	{
		static::$_PDOInstance = $PDOInstance_;
	}

	public function set($var_, $value_)
	{
		$this->$var_ = $value_;
		return $this;
	}

	public function get($var_)
	{
		return $this->$var_;
	}

	public function getAll()
	{
		return get_object_vars($this);
	}

	public function dbToVars(array $arr_)
	{
		foreach ($arr_ as $key => $value) {
			$this->$key = $value;
		}
	}

	public function getRecord($id_ = NULL)
	{
		$id = $id_;

		if (is_null($id))
			$id = $this->id;

		if (!is_numeric($id))
			throw new \Exception('Wrong id given in getRecord() method in '.get_called_class().' class.');

		$this->id = (int) $id;

		if ($this->_caching) {
			$mc = Memcached::getInstance(static::$_cacheHost, static::$_cachePort);
			$ret = $mc->get(static::$_cachePrefix.static::$_table.'_record_'.$this->id);

			if ($ret && is_object($ret) && get_class($ret) == get_called_class()) {
				$this->dbToVars($ret->getAll());
				return $this;
			}
		}

		$query = 'SELECT * FROM '.static::$_table.' WHERE id = :id';
		$q = static::$_PDOInstance->prepare($query);
		$q->bindParam(':id', $this->id, \PDO::PARAM_INT);
		$q->execute();

		if ($q->rowCount() > 0) {
			$q->setFetchMode(\PDO::FETCH_ASSOC);
			$this->dbToVars($q->fetch());
			$q->closeCursor();

			if ($this->_caching) {
				$mc->add(static::$_cachePrefix.static::$_table.'_record_'.$this->id, $this, static::$_cacheExpire);
			}
			return $this;
		}

		$q->closeCursor();
		throw new \Exception('No rows fetched in getRecord() method in '.get_called_class().' class.');
	}

	public function insertRecord()
	{   
		$arrParams = array();
		$cpt = 0;
		$query = 'INSERT INTO '.static::$_table.' (';

		foreach ($this as $key => $value) {
			if ($key == 'id' || strpos($key, '_') === 0) // auto-incremented id or "_" beginning vars
				continue;

			if ($cpt != 0)
				$query  .= ', ';

			$cpt++;
			$query .= '`'.$key.'`';
		}
		
		$query .= ') VALUES (';

		$cpt = 0;
		foreach ($this as $key => $value) {
			if ($key == 'id' || strpos($key, '_') === 0) // auto-incremented id or "_" beginning vars
				continue;

			if ($cpt != 0)
				$query .= ', ';

			$query .= ':'.$key;
			$cpt++;
			$arrParams[$key] = $value;
		}

		$query .= ')';
		$q = static::$_PDOInstance->prepare($query);
		$q->execute($arrParams);
		
		$this->id = static::$_PDOInstance->lastInsertId();
		$q->closeCursor();

		if ($this->_caching) {
			$mc = Memcached::getInstance(static::$_cacheHost, static::$_cachePort);
			$mc->add(static::$_cachePrefix.static::$_table.'_record_'.$this->id, $this, static::$_cacheExpire);
		}
		return $this;
	}

	public function updateRecord()
	{
		$arrParams = array();
		$cpt = 0;
		$query = 'UPDATE '.static::$_table.' SET ';
				
		foreach ($this as $key => $value) {
			if ($key == 'id' || strpos($key, '_') === 0) // auto-incremented id or "_" beginning vars
				continue;

			if ($cpt != 0)
				$query .= ', ';

			$cpt++;
			$query .= '`'.$key.'`= :'.$key;
			$arrParams[$key] = $value;
		}

		$query .= ' WHERE id = :id';
		$arrParams['id'] = $this->id;
		$q = static::$_PDOInstance->prepare($query);
		$q->execute($arrParams);

		if ($this->_caching) {
			$mc = Memcached::getInstance(static::$_cacheHost, static::$_cachePort);
			$mc->set(static::$_cachePrefix.static::$_table.'_record_'.$this->id, $this, static::$_cacheExpire);
		}

		$q->closeCursor();
		return $this;
	}

	public function deleteRecord()
	{
		$query  = 'DELETE FROM '.static::$_table.' WHERE id = :id';
		$q = static::$_PDOInstance->prepare($query);
		$q->execute(array('id' => $this->id));

		if ($this->_caching) {
			$mc = Memcached::getInstance(static::$_cacheHost, static::$_cachePort);
			$mc->delete(static::$_cachePrefix.static::$_table.'_record_'.$this->id);
		}
		return true;
	}
}
