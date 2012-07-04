<?php namespace Illuminate\Database\Eloquent;

use DateTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

abstract class Model {

	/**
	 * The connection for the model.
	 *
	 * @var string
	 */
	protected $connection;

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * The primary key for the model.
	 *
	 * @var string
	 */
	protected $key = 'id';

	/**
	 * The model's attributes.
	 *
	 * @var array
	 */
	protected $attributes;

	/**
	 * Indicates if the model exists.
	 *
	 * @var bool
	 */
	public $exists = false;

	/**
	 * The connections registered with Eloquent.
	 *
	 * @var array
	 */
	protected static $connections = array();

	/**
	 * The default connection name.
	 *
	 * @var string
	 */
	protected static $defaultConnection;

	/**
	 * Create a new Eloquent model instance.
	 *
	 * @param  array  $attributes
	 * @return void
	 */
	public function __construct(array $attributes = array())
	{
		$this->fill($attributes);
	}

	/**
	 * Fill the model with an array of attributes.
	 *
	 * @param  array  $attributes
	 * @return void
	 */
	public function fill(array $attributes)
	{
		foreach ($attributes as $key => $value)
		{
			$this->$key = $value;
		}
	}

	/**
	 * Create a new instance of the given model.
	 *
	 * @param  array  $attributes
	 * @return Illuminate\Database\Eloquent\Model
	 */
	public function newInstance(array $attributes = array())
	{
		$class = get_class($this);

		return new $class($attributes);
	}

	/**
	 * Save a new model and return the instance.
	 *
	 * @param  array  $attributes
	 * @return Illuminate\Database\Eloquent\Model
	 */
	public static function create(array $attributes)
	{
		$model = new static($attributes);

		$model->save();

		return $model;
	}

	/**
	 * Find a model by its primary key.
	 *
	 * @param  mixed  $id
	 * @param  array  $columns
	 * @return Illuminate\Database\Eloquent\Model
	 */
	public static function find($id, $columns = array('*'))
	{
		$instance = new static;

		return $instance->newQuery()->find($id, $columns);
	}

	/**
	 * Being querying a model with eager loading.
	 *
	 * @param  array  $relations
	 * @return ?
	 */
	public static function with($relations)
	{
		if (is_string($relations)) $relations = func_get_args();

		$instance = new static;

		return $instance->newQuery()->with($relations);
	}

	/**
	 * Define a one-to-one relationship.
	 *
	 * @param  string  $related
	 * @param  string  $foreignKey
	 * @return Illuminate\Database\Eloquent\Relation\HasOne
	 */
	public function hasOne($related, $foreignKey = null)
	{
		$foreignKey = $foreignKey ?: $this->getForeignKey();

		$instance = new $related;

		return new HasOne($instance->newQuery(), $this, $foreignKey);
	}

	/**
	 * Define an inverse one-to-one or many relationship.
	 *
	 * @param  string  $related
	 * @param  string  $foreignKey
	 * @return Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function belongsTo($related, $foreignKey = null)
	{
		// If no foreign key was supplied, we can use a backtrace to guess the proper
		// foreign key name by using the name of the relationship function, which
		// when combined with an "_id" should conventionally match the column.
		if (is_null($foreignKey))
		{
			list(, $caller) = debug_backtrace(false);

			$foreignKey = "{$caller['function']}_id";
		}

		// Once we have the foreign key name, we'll just create a new Eloquent query
		// for the related model and return the relationship instance which will
		// actually be responsible for retrieving and hydrating the relation.
		$instance = new $related;

		$query = $instance->newQuery();

		return new BelongsTo($query, $this, $foreignKey);
	}

	/**
	 * Define a one-to-many relationship.
	 *
	 * @param  string  $related
	 * @param  string  $foreignKey
	 * @return Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function hasMany($related, $foreignKey = null)
	{
		$foreignKey = $foreignKey ?: $this->getForeignKey();

		$instance = new $related;

		return new HasMany($instance->newQuery(), $this, $foreignKey);
	}

	/**
	 * Define a many-to-many relationship.
	 *
	 * @param  string  $related
	 * @param  string  $table
	 * @param  string  $foreignKey
	 * @param  string  $otherKey
	 * @return Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function belongsToMany($related, $table = null, $foreignKey = null, $otherKey = null)
	{
		// First we need to determine the foreign key and "other key" for the relationship.
		// Once we have determined the keys we can create the query instance as well as
		// the relationship instances which will retrieve and hyrdate all the models.
		$foreignKey = $foreignKey ?: $this->getForeignKey();
		
		$instance = new $related;

		$otherKey = $otherKey ?: $instance->getForeignKey();

		// If no table name was provided, we can guess it by concatenating the two models
		// with underscores in alphabetical order. The two model names are transformed
		// to snake case from their default CamelCase spellings autmatically for us.
		if (is_null($table))
		{
			$table = $this->joiningTable($related);
		}

		// Now we are ready to create a new query builder for the related model and a
		// relationship instance for the relation. The relationships will set each
		// of the appropriate query constraints and entirely manages hydrations.
		$query = $instance->newQuery();

		return new BelongsToMany($query, $this, $table, $foreignKey, $otherKey);
	}

	/**
	 * Get the joining table name for a many-to-many relation.
	 *
	 * @param  string  $related
	 * @return string
	 */
	public function joiningTable($related)
	{
		// The joining table name, by convention, is simply the snake cased models
		// sorted alphabetically and concatenated with an underscore, so we can
		// just sort the models and join them together to get the table name.
		$base = $this->snakeCase(static::classBasename($this));

		$related = $this->snakeCase(static::classBasename($related));

		$models = array($related, $base);

		// Now that we have the model names in an array we can just sort them and
		// use the implode function to join them together with an underscores,
		// which is typically used by convention within the datbase systems.
		sort($models);

		return strtolower(implode('_', $models));
	}

	/**
	 * Save the model to the database.
	 *
	 * @return bool
	 */
	public function save()
	{
		// First we need to create a fresh query instance and touch the creation
		// and update timestamps on the model which are maintained by us for
		// convenience to the developers. Then we'll just save the model.
		$query = $this->newQuery();

		$this->updateTimestamps();

		// If the model already exists in the database, we can just update our
		// record that is already in the database using the current IDs in
		// the "where" clause of the queries to only update this model.
		if ($this->exists)
		{
			$query->where($this->getKeyName(), '=', $this->getKey());

			$query->update($this->attributes);
		}

		// If the model is brand new, we'll insert it into our database and
		// set the ID attribute on the model to the value of the newly
		// inserted row's ID, which is typically auto-incremented.
		else
		{
			$this->id = $query->insertGetId($this->attributes);
		}

		return true;
	}

	/**
	 * Update the creation and update timestamps.
	 *
	 * @return void
	 */
	protected function updateTimestamps()
	{
		$this->updated_at = new DateTime;

		if ( ! $this->exists)
		{
			$this->created_at = $this->updated_at;
		}
	}

	/**
	 * Get a new query builder for the model's table.
	 *
	 * @return Illuminate\Database\Eloquent\Builder
	 */
	public function newQuery()
	{
		$conn = $this->getConnection();

		// First we will build the query builder by passing in the grammar and
		// post processor, which are used to construct and process the SQL
		// queries generated by the builder. Then we can set the model.
		$grammar = $conn->getQueryGrammar();

		$processor = $conn->getPostProcessor();

		$builder = new Builder($conn, $grammar, $processor);

		// Once we have the query builder, we will set the model instance so
		// the builder can easily access any information it may need from
		// the model while it's constructing and executing the queries.
		$builder->setModel($this);

		return $builder;
	}

	/**
	 * Get the table associated with the model.
	 *
	 * @return string
	 */
	public function getTable()
	{
		return $this->table;
	}

	/**
	 * Set the table associated with the model.
	 *
	 * @param  string  $table
	 * @return void
	 */
	public function setTable($table)
	{
		$this->table = $table;
	}

	/**
	 * Get the value of the model's primary key.
	 *
	 * @return mixed
	 */
	public function getKey()
	{
		return $this->getAttribute($this->getKeyName());
	}

	/**
	 * Get the primary key for the model.
	 *
	 * @return string
	 */
	public function getKeyName()
	{
		return $this->key;
	}

	/**
	 * Get the default foreign key name for the model.
	 *
	 * @return string
	 */
	public function getForeignKey()
	{
		return $this->snakeCase(get_class($this)).'_id';
	}

	/**
	 * Get the database connection for the model.
	 *
	 * @return Illuminate\Database\Connection
	 */
	public function getConnection()
	{
		return $this->connection ?: static::getDefaultConnection();
	}

	/**
	 * Set the connection associated with the model.
	 *
	 * @param  string  $name
	 * @return void
	 */
	public function setConnection($name)
	{
		$this->connection = static::$connections[$name];
	}

	/**
	 * Register a connection with Eloquent.
	 *
	 * @param  string  $name
	 * @param  Illuminate\Database\Connection  $connection
	 * @return void
	 */
	public static function addConnection($name, Connection $connection)
	{
		if (count(static::$connections) == 0)
		{
			static::$defaultConnection = $name;
		}

		static::$connections[$name] = $connection;
	}

	/**
	 * Get the default connection instance.
	 *
	 * @return Illuminate\Database\Connection
	 */
	public static function getDefaultConnection()
	{
		return static::$connections[static::$defaultConnection];
	}

	/**
	 * Set the default connection name.
	 *
	 * @param  string  $name
	 * @return void
	 */
	public static function setDefaultConnectionName($name)
	{
		static::$defaultConnection = $name;
	}

	/**
	 * Clear the array of registered collections.
	 *
	 * @return void
	 */
	public static function clearConnections()
	{
		static::$connections = array();
	}

	/**
	 * Get an attribute from the model.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function getAttribute($key)
	{
		$value = null;

		if (array_key_exists($key, $this->attributes))
		{
			$value = $this->attributes[$key];
		}

		if ($this->hasGetMutator($key))
		{
			return $this->{'get'.$this->camelCase($key)}($value);
		}

		return $value;
	}

	/**
	 * Determine if a get mutator exists for an attribute.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	protected function hasGetMutator($key)
	{
		return method_exists($this, 'get'.$this->camelCase($key));
	}

	/**
	 * Set a given attribute on the model.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function setAttribute($key, $value)
	{
		// First we will check for the presence of a mutator for the set operation.
		// This simply lets the developer tweak the attribute as it is set on
		// the model, such as json_encoding an array of data for storage.
		if ($this->hasSetMutator($key))
		{
			$method = 'set'.$this->camelCase($key);

			return $this->attributes[$key] = $this->$method($value);
		}

		$this->attributes[$key] = $value;
	}

	/**
	 * Determine if a set mutator exists for an attribute.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	protected function hasSetMutator($key)
	{
		return method_exists($this, 'set'.$this->camelCase($key));
	}

	/**
	 * Get all of the current attributes on the model.
	 *
	 * @return array
	 */
	public function getAttributes()
	{
		return $this->attributes;
	}

	/**
	 * Convert a snake case string to camel case.
	 *
	 * @param  string  $value
	 * @return string
	 */
	protected function camelCase($value)
	{
		$value = ucwords(str_replace('_', ' ', $value));

		return str_replace(' ', '', $value);
	}

	/**
	 * Convert a CamelCase string back to snake case.
	 *
	 * @param  string  $value
	 * @return string
	 */
	protected function snakeCase($value)
	{
		return preg_replace_callback('/[A-Z]/', function($match)
		{
			return '_'.strtolower($match[0]);

		}, $value);
	}

	/**
	 * Get the "base name" of the given class or object.
	 *
	 * @param  string|object  $class
	 * @return string
	 */
	public static function classBasename($class)
	{
		$class = is_object($class) ? get_class($class) : $class;

		return basename(str_replace('\\', '/', $class));
	}

	/**
	 * Dynamically retrieve attributes on the model.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		return $this->getAttribute($key);
	}

	/**
	 * Dynamically set attributes on the model.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		$this->setAttribute($key, $value);
	}

	/**
	 * Determine if an attribute exists on the model.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __isset($key)
	{
		return isset($this->attributes[$key]);
	}

	/**
	 * Unset an attribute on the model.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __unset($key)
	{
		unset($this->attributes[$key]);
	}

	/**
	 * Handle dynamic method calls into the method.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		$query = $this->newQuery();

		if (method_exists($query, $method))
		{
			return call_user_func_array(array($query, $method), $parameters);
		}

		throw new \BadMethodCallException("Method [$method] does not exist.");
	}

	/**
	 * Handle dynamic static method calls into the method.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public static function __callStatic($method, $parameters)
	{
		$instance = new static;

		return call_user_func_array(array($instance, $method), $parameters);
	}

}