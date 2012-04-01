<?php
class Basic_Entity implements ArrayAccess
{
	protected static $_cache;

	protected $id = null;
	protected $_data = array();
	//FIXME: most /all properties below should be static (?)
	protected $_table = null;
	protected $_relations = array();
	protected $_numerical = array();
	protected $_serialized = array();
	protected static $_order = array('id' => true);

	public function __construct($id = null)
	{
		if (!isset($this->_table))
			$this->_table = array_pop(explode('_', get_class($this)));

		if (isset($id) && !is_scalar($id))
			throw new Basic_Entity_InvalidIdException('Invalid type `%s` for `id`', array(gettype($id)));

		$this->id = $id;

		if (!isset(self::$_cache[ get_class($this) ][ $id ]))
			self::$_cache[ get_class($this) ][ $id ] = $this;
	}

	public static function get($id)
	{
		$class = get_called_class();

		if (!isset(self::$_cache[ $class ][ $id ]))
			return new $class($id);

		return self::$_cache[ $class ][ $id ];
	}

	public function load($id = null)
	{
		Basic::$log->start();

		if (!isset($id))
			$id = $this->id;

		$query = Basic::$database->query("SELECT * FROM `". $this->_table ."` WHERE `id` = ?", array($id));

		if (0 == $query->rowCount())
			throw new Basic_Entity_NotFoundException('`%s` with id `%s` was not found', array(get_class($this), $id));

		$this->_load($query->fetch());

		Basic::$log->end($this->_table .':'. $id);
	}

	// Public so the EntitySet can push results into the Entity
	public function _load(array $data)
	{
		foreach ($data as $key => $value)
		{
			$this->_data[$key] = $value;

			if (isset($value))
			{
				if (array_key_exists($key, $this->_relations))
				{
					$class = $this->_relations[$key];
					$value = $class::get($value);
				}
				elseif (in_array($key, $this->_numerical))
					$value = intval($value);
				elseif (in_array($key, $this->_serialized))
				{
					$_value = unserialize($value);

					if (is_array($_value) || is_object($_value))
						$value = $_value;
				}
			}

			$this->$key = $value;
		}

		// Checks might need a property, so do this after the actual loading
		$this->_checkPermissions('load');
	}

	public function __get($key)
	{
		// `id` is private
		if ($key == 'id')
			return $this->id;

		// Are we lazy-loaded?
		if (empty($this->_data) && isset($this->id))
		{
			$this->load();

			if (property_exists($this, $key))
				return $this->$key;
		}

		if (method_exists($this, '__get'. ucfirst($key)))
			return call_user_func(array($this, '__get'. ucfirst($key)));
	}

	public function __isset($key)
	{
		return (null !== $this->__get($key));
	}

	public function save(array $data = array())
	{
		if (array_key_exists('id', $data) && $data['id'] != $this->id)
			throw new Basic_Entity_CannotUpdateIdException('You cannot change the `id` of an object');

		foreach ($data as $property => $value)
		{
			if (isset($this->_relations[$property]) && !is_object($value))
			{
				$class = $this->_relations[$property];
				$value = new $class($value);
			}

			$this->$property = $value;
		}

		$this->_checkPermissions('save');

		$fields = $values = array();
		foreach ($this->_getProperties() as $property)
		{
			$value = $this->$property;

			if (isset($value))
			{
				if ($value === '')
					$value = null;
				elseif (isset($this->_relations[$property]))
					$value = $value->id;
				elseif (in_array($property, $this->_serialized))
					$value = serialize($value);
				elseif (!is_scalar($value))
					throw new Basic_Entity_InvalidDataException('Value for `%s` contains invalid data', array($property));
			}

			if ($value === $this->_data[ $property ] || in_array($property, $this->_numerical) && $value == $this->_data[ $property ])
				continue;

			array_push($values, $value);
			array_push($fields, "`". $property ."` = ?");
		}
		$fields = implode(',', $fields);

		// No changes?
		if (empty($values))
			return;

		if (isset($this->id))
		{
			array_push($values, $this->id);

			$query = Basic::$database->query("UPDATE `". $this->_table ."` SET ". $fields ." WHERE `id` = ?", $values);
		}
		else
		{
			$query = Basic::$database->query("INSERT INTO `". $this->_table ."` SET ". $fields, $values);

			$this->id = Basic::$database->lastInsertId();
		}

		if ($query->rowCount() != 1)
			throw new Basic_Entity_StorageException('An error occured while creating/updating `%s`:`%s`', array(get_class($this), $this->id));

		unset(self::$_cache[ get_class($this) ][ $this->id ]);
	}

	protected function _getProperties()
	{
		if (!empty($this->_data))
			return array_diff(array_keys($this->_data), array('id'));

		$properties = array();
		$object = new ReflectionObject($this);

		foreach ($object->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
			array_push($properties, $property->getName());

		return $properties;
	}

	public static function find($filter = null, array $parameters = array(), array $order = null)
	{
		$class = get_called_class();

		if (!isset($order))
			$order = isset($class::$_order) ? $class::$_order : self::$_order;

		return new Basic_EntitySet($class, $filter, $parameters, $order);
	}

	public function delete()
	{
		$this->_checkPermissions('delete');

		$result = Basic::$database->query("
			DELETE FROM
				`". $this->_table ."`
			WHERE
				`id` = ". $this->id);

		unset(self::$_cache[ get_class($this) ][ $this->id ]);

		if ($result != 1)
			throw new Basic_Entity_DeleteException('An error occured while deleting `%s`:`%s`', array(get_class($this), $this->id));
	}

	public function getTable()
	{
		return $this->_table;
	}

	protected function _checkPermissions($action)
	{
		return true;
	}

	public function setUserinputDefault()
	{
		foreach ($this->_getProperties() as $key)
		{
			if (isset(Basic::$userinput->$key))
			{
				try
				{
					Basic::$userinput->$key->default = isset($this->_relations[$key]) ? $this->$key->id : $this->$key;
				}
				catch (Basic_UserinputValue_InvalidDefaultException $e)
				{
					Basic::$log->write('InvalidDefaultException for `'. $key .'` on `'. get_class($this). '`, value = '. var_export($this->$key, true). ', caused by '. get_class($e->getPrevious()));
					// ignore, user cannot fix this
				}
			}
		}
	}

	public function getRelated($entityType)
	{
		$entity = new $entityType;

		$keys = array_keys($entity->_relations, get_class($this), true);

		if (1 != count($keys))
			throw new Basic_Entity_NoRelationFoundException('No relation of type `%s` was found', array($entityType));

		$key = array_pop($keys);

		return $entityType::find($key ." = ?", array($this->id));
	}

	// For the templates
	public function offsetExists($offset){		return isset($this->$offset);					}
	public function offsetGet($offset){			return $this->$offset;							}
	public function offsetSet($offset,$value){	throw new Basic_Entity_UnsupportedException;	}
	public function offsetUnset($offset){		throw new Basic_Entity_UnsupportedException;	}
}