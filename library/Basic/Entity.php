<?php

class Basic_Entity
{
	protected $id;
	private $_dbData;

	private static $_cache;
	protected static $_relations = [];
	protected static $_numerical = [];
	protected static $_serialized = [];
	protected static $_order = array('id' => true);

	private function __construct()
	{
		$this->_dbData = clone $this;

		// Let __get handle relations lazily
		foreach (static::$_relations as $property => $class)
			unset($this->$property);

		foreach (static::$_numerical as $property)
			if (property_exists($this, $property) && null !== $this->$property)
				$this->$property = 1*$this->$property;

		foreach (static::$_serialized as $property)
			if (property_exists($this, $property) && null !== $this->$property)
				$this->$property = unserialize($this->$property);

		// Checks might need a property, so do this after the actual loading
		$this->_checkPermissions('load');

		// Don't cache freshly ::created entities
		if (isset($this->id))
			self::$_cache[ get_called_class() ][ $this->id ] = $this;
	}

	public static function get($id): self
	{
		if (!is_scalar($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', [gettype($id)]);

		$class = get_called_class();

		if (!isset(self::$_cache[ $class ][ $id ]))
		{
			$result = Basic::$database->query("SELECT * FROM ". Basic_Database::escapeTable(static::getTable()) ." WHERE id = ?", [$id]);
			$result->setFetchMode(PDO::FETCH_CLASS, $class);

			// Allow caching negatives too. Note fetch() calls __construct() which stores in cache
			if (!$result->fetch())
				self::$_cache[ $class ][ $id ] = false;
		}

		if (false == self::$_cache[ $class ][ $id ])
			throw new Basic_Entity_NotFoundException('Did not find `%s` with id `%s`', [$class, $id]);

		return self::$_cache[ $class ][ $id ];
	}

	public static function getStub(array $data = []): self
	{
		$entity = new static;

		foreach ($data as $k => $v)
			$entity->$k = $v;

		return $entity;
	}

	public static function create(array $data = [], bool $reload = true): self
	{
		$entity = new static;
		$entity->_dbData = new StdClass;
		$entity->save($data);

		if ($reload)
			return static::get($entity->id);
		else
			return $entity;
	}

	protected function _isNew(): bool
	{
		return $this->_dbData instanceof StdClass;
	}

	public function __get($key)
	{
		if ('id' == $key)
			return $this->id;

		if (array_key_exists($key, static::$_relations) && isset($this->_dbData->$key))
		{
			$class = static::$_relations[$key];
			$id = $this->_dbData->$key;

			if (isset(self::$_cache[ $class ][ $id ]))
				return $this->$key = self::$_cache[ $class ][ $id ];
			else
				return $this->$key = $class::get($id);
		}

		if (method_exists($this, '_get'. ucfirst($key)))
			return call_user_func(array($this, '_get'. ucfirst($key)));
	}

	public function __isset($key)
	{
		return (null !== $this->__get($key));
	}

	public function save(array $data = []): bool
	{
		if (isset($this->id, $data['id']) && $data['id'] != $this->id)
			throw new Basic_Entity_CannotUpdateIdException('You cannot change the `id` of an object');

		// Apply $data to $this
		foreach ($data as $property => $value)
		{
			if (isset($value, static::$_relations[$property]) && !is_object($value))
			{
				$class = static::$_relations[$property];
				$value = $class::get($value);
			}

			$this->$property = $value;
		}

		$this->_checkPermissions('save');

		// Now determine what properties have changed
		$data = [];
		foreach ($this->_getProperties() as $property)
		{
			$value = $this->$property;

			if (isset($value))
			{
				if ($value === '')
					$value = null;
				elseif (isset(static::$_relations[$property]))
					$value = $value->id;
				elseif (in_array($property, static::$_serialized))
					$value = serialize($value);
				elseif (!is_scalar($value))
					throw new Basic_Entity_InvalidDataException('Value for `%s` contains invalid data `%s`', array($property, gettype($value)));
			}

			if ($value === $this->_dbData->$property || in_array($property, static::$_numerical) && $value == $this->_dbData->$property)
				continue;

			$data[ $property ] = $value;
		}

		if (empty($data))
			return false;

		if (isset($this->id))
		{
			$fields = implode(' = ?, ', array_map([Basic_Database::class, 'escapeColumn'], array_keys($data)));
			Basic::$database->query("UPDATE ". Basic_Database::escapeTable(static::getTable()) ." SET ". $fields ." = ? WHERE id = ?", array_merge(array_values($data), [$this->id]));

			$this->removeCached();
		}
		else
		{
			$columns = implode(', ', array_map([Basic_Database::class, 'escapeColumn'], array_keys($data)));
			$values = implode(', :', array_keys($data));

			$query = Basic::$database->query("INSERT INTO ". Basic_Database::escapeTable(static::getTable()) ." (". $columns .") VALUES (:". $values .")", $data);

			if (1 != $query->rowCount())
				throw new Basic_Entity_StorageException('New `%s` could not be created', array(get_class($this)));

			try
			{
				$this->id = Basic::$database->lastInsertId(static::getTable(). '_id_seq');
			} catch (PDOException $e) {
				// ignore
			}
		}

		return true;
	}

	protected function _getProperties()
	{
		return array_diff(array_keys(get_object_vars($this)), array('id', '_dbData'));
	}

	public static function find(string $filter = null, array $parameters = [], array $order = []): Basic_EntitySet
	{
		$class = get_called_class();

		$setClass = $class.'Set';
		if (!class_exists($setClass))
			eval("class $setClass extends Basic_EntitySet {}");
		$set = new $setClass($class);

		return $set
			->getSubset($filter, $parameters)
			->setOrder($order ?? static::$_order);
	}

	public function delete()
	{
		$this->_checkPermissions('delete');
		$this->removeCached();

		$result = Basic::$database->query("DELETE FROM ". Basic_Database::escapeTable(static::getTable()) ." WHERE id = ?", [$this->id]);

		if ($result != 1)
			throw new Basic_Entity_DeleteException('An error occured while deleting `%s`:`%s`', [get_class($this), $this->id]);
	}

	public function removeCached()
	{
		unset(self::$_cache[ get_class($this) ][ $this->id ]);
	}

	public static function getTable()
	{
		return substr(strrchr(get_called_class(), '_'), 1);
	}

	protected function _checkPermissions($action): void
	{
		return;
	}

	public function setUserinputDefault()
	{
		foreach ($this as $key => $value)
		{
			if (!isset(Basic::$userinput->$key))
			{
				Basic::$log->write('SetUserinputDefault failed, property `'. $key .'` on `'. get_class($this). '` is not defined in Basic::$userinput');
				continue;
			}

			$value = isset(static::$_relations[$key]) ? $value->id : $value;

			try
			{
				$this->_setUserinputDefault($key, $value);
			}
			catch (Basic_UserinputValue_InvalidDefaultException $e)
			{
				if (!Basic::$config->PRODUCTION_MODE)
					throw $e;

				Basic::$log->write('InvalidDefaultException for `'. $key .'` on `'. get_class($this). '`, value = '. var_export($value, true). ', caused by '. get_class($e->getPrevious()));
				// ignore, user cannot fix this
			}
		}
	}

	protected function _setUserinputDefault($key, $value)
	{
		Basic::$userinput->$key->default = $value;
	}

	public function getRelated($entityType): Basic_EntitySet
	{
		$keys = array_keys($entityType::$_relations, get_class($this), true);

		if (1 != count($keys))
			throw new Basic_Entity_NoRelationFoundException('No relation of type `%s` was found', array($entityType));

		return $entityType::find($keys[0] ." = ?", array($this->id));
	}

	public function getEnumValues($property)
	{
		$q = Basic::$database->query("SHOW COLUMNS FROM ". Basic_Database::escapeTable(static::getTable()) ." WHERE field =  ?", array($property));
		return explode("','", str_replace(array("enum('", "')", "''"), array('', '', "'"), $q->fetchArray('Type')[0]));
	}
}