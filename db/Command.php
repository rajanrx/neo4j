<?php

namespace neo4j\db;

use Everyman\Neo4j\Cypher\Query;
use Everyman\Neo4j\Exception;
use Everyman\Neo4j\Label;
use Everyman\Neo4j\Node;
use Everyman\Neo4j\PropertyContainer;
use Everyman\Neo4j\Query\ResultSet;
use Everyman\Neo4j\Query\Row;
use neo4j\neo4jphp\Command\ExecuteCypherQuery;
use Yii;
use yii\base\NotSupportedException;
use yii\caching\Cache;
use yii\db\Expression;

/**
 * Command represents a SQL statement to be executed against a database.
 *
 * A command object is usually created by calling [[Connection::createCommand()]].
 * The SQL statement it represents can be set via the [[sql]] property.
 *
 * To execute a non-query SQL (such as INSERT, DELETE, UPDATE), call [[execute()]].
 * To execute a SQL statement that returns result data set (such as SELECT),
 * use [[queryAll()]], [[queryOne()]], [[queryColumn()]], [[queryScalar()]], or [[query()]].
 * For example,
 *
 * ~~~
 * $users = $connection->createCommand('SELECT * FROM user')->queryAll();
 * ~~~
 *
 * Command supports SQL statement preparation and parameter binding.
 * Call [[bindValue()]] to bind a value to a SQL parameter;
 * Call [[bindParam()]] to bind a PHP variable to a SQL parameter.
 * When binding a parameter, the SQL statement is automatically prepared.
 * You may also call [[prepare()]] explicitly to prepare a SQL statement.
 *
 * Command also supports building SQL statements by providing methods such as [[insert()]],
 * [[update()]], etc. For example,
 *
 * ~~~
 * $connection->createCommand()->insert('user', [
 *     'name' => 'Sam',
 *     'age' => 30,
 * ])->execute();
 * ~~~
 *
 * To build SELECT SQL statements, please use [[QueryBuilder]] instead.
 *
 * @property PropertyContainer $container The SQL statement to be executed.
 * @property string $rawQuery
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Command extends \yii\base\Component
{
	/**
	 * @var Connection the DB connection that this command is associated with
	 */
	public $db;

	/**
	 * @var array the parameters (name => value) that are bound to the current PDO statement.
	 * This property is maintained by methods such as [[bindValue()]].
	 * Do not modify it directly.
	 */
	public $params = [];

	/**
	 * @var PropertyContainer
	 */
	private $_container;

	/**
	 * Returns the SQL statement for this command.
	 * @return PropertyContainer the SQL statement to be executed
	 */
	public function getContainer()
	{
		return $this->_container;
	}

	/**
	 * Specifies the SQL statement to be executed.
	 * The previous SQL execution (if any) will be cancelled, and [[params]] will be cleared as well.
	 * @param PropertyContainer $container the SQL statement to be set.
	 * @return static this command instance
	 */
	public function setContainer($container)
	{
		if ($container !== $this->_container) {
			$this->cancel();
			$this->_container = $container;
			$this->params = [];
		}

		return $this;
	}

	/**
	 * @var Label
	 */
	private $_label;

	/**
	 * Returns the SQL statement for this command.
	 * @return Label the SQL statement to be executed
	 */
	public function getLabel()
	{
		return $this->_label;
	}

	/**
	 * Specifies the SQL statement to be executed.
	 * The previous SQL execution (if any) will be cancelled, and [[params]] will be cleared as well.
	 *
	 * @param Label $name the SQL statement to be set.
	 *
	 * @return static this command instance
	 */
	public function setLabel($name)
	{
		if ($name !== $this->_label)
		{
			$this->_label = $this->db->client->makeLabel($name);
		}

		return $this;
	}

	/**
	 * @var string
	 */
	private $_query;

	/**
	 * Returns the SQL statement for this command.
	 * @return string the SQL statement to be executed
	 */
	public function getQuery()
	{
		return $this->_query;
	}

	/**
	 * Specifies the SQL statement to be executed.
	 * The previous SQL execution (if any) will be cancelled, and [[params]] will be cleared as well.
	 * @param string $query the SQL statement to be set.
	 * @return static this command instance
	 */
	public function setQuery($query)
	{
		if ($query !== $this->_query) {
			$this->cancel();
			$this->_query = $query;
			$this->params = [];
		}

		return $this;
	}

	/**
	 * Returns the raw Cypher Query by inserting parameter values into the corresponding placeholders in [[sql]].
	 * Note that the return value of this method should mainly be used for logging purpose.
	 * It is likely that this method returns an invalid SQL due to improper replacement of parameter placeholders.
	 * @return string the raw SQL with parameter values inserted into the corresponding placeholders in [[sql]].
	 */
	public function getRawQuery()
	{
		if (empty($this->params)) {
			return $this->_query;
		} else {
			$params = [];
			foreach ($this->params as $name => $value) {
				$name = "{{$name}}";
				if (is_string($value)) {
					$params[$name] = "'$value'";
				} elseif ($value === null) {
					$params[$name] = 'NULL';
				} else {
					$params[$name] = $value;
				}
			}

			if (isset($params[1])) {
				$query = '';
				foreach (explode('?', $this->_query) as $i => $part) {
					$query .= (isset($params[$i]) ? $params[$i] : '') . $part;
				}

				return $query;
			} else {
				return strtr($this->_query, $params);
			}
		}
	}

	/**
	 * Prepares the SQL statement to be executed.
	 * For complex SQL statement that is to be executed multiple times,
	 * this may improve performance.
	 * For SQL statement with binding parameters, this method is invoked
	 * automatically.
	 * @throws Exception if there is any DB error
	 */
	public function prepare()
	{
        if ($this->container !== null)
        {
            foreach ($this->container->getProperties() as $key => $value)
            {
                if (!mb_check_encoding($value, 'UTF-8'))
                {
                    $this->container->setProperty($key, utf8_encode($value));
                }
            }
        }

        return;
		if ($this->pdoStatement == null) {
			$queryString = $this->getSql();
			try {
				$this->pdoStatement = $this->db->pdo->prepare($queryString);
			} catch (\Exception $e) {
				$message = $e->getMessage() . "\nFailed to prepare SQL: $queryString";
				$errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
				throw new Exception($message, $errorInfo, (int) $e->getCode(), $e);
			}
		}
	}

	/**
	 * Cancels the execution of the SQL statement.
	 * This method mainly sets [[pdoStatement]] to be null.
	 */
	public function cancel()
	{
		$this->_query = null;
	}

	/**
	 * Binds a parameter to the SQL statement to be executed.
	 * @param string|integer $name parameter identifier. For a prepared statement
	 * using named placeholders, this will be a parameter name of
	 * the form `:name`. For a prepared statement using question mark
	 * placeholders, this will be the 1-indexed position of the parameter.
	 * @param mixed $value Name of the PHP variable to bind to the SQL statement parameter
	 * @param integer $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
	 * @param integer $length length of the data type
	 * @param mixed $driverOptions the driver-specific options
	 * @return static the current command being executed
	 * @see http://www.php.net/manual/en/function.PDOStatement-bindParam.php
	 */
	public function bindParam($name, &$value, $dataType = null, $length = null, $driverOptions = null)
	{
		$this->prepare();
		if ($dataType === null) {
			$dataType = $this->db->getSchema()->getPdoType($value);
		}
		if ($length === null) {
			$this->pdoStatement->bindParam($name, $value, $dataType);
		} elseif ($driverOptions === null) {
			$this->pdoStatement->bindParam($name, $value, $dataType, $length);
		} else {
			$this->pdoStatement->bindParam($name, $value, $dataType, $length, $driverOptions);
		}
		$this->params[$name] =& $value;

		return $this;
	}

	/**
	 * Binds a value to a parameter.
	 * @param string|integer $name Parameter identifier. For a prepared statement
	 * using named placeholders, this will be a parameter name of
	 * the form `:name`. For a prepared statement using question mark
	 * placeholders, this will be the 1-indexed position of the parameter.
	 * @param mixed $value The value to bind to the parameter
	 * @param integer $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
	 * @return static the current command being executed
	 * @see http://www.php.net/manual/en/function.PDOStatement-bindValue.php
	 */
	public function bindValue($name, $value, $dataType = null)
	{
		$this->prepare();
		if ($dataType === null) {
			#$dataType = $this->db->getSchema()->getPdoType($value);
		}
		#$this->pdoStatement->bindValue($name, $value, $dataType);
		$this->params[$name] = $value;

		return $this;
	}

	/**
	 * Binds a list of values to the corresponding parameters.
	 * This is similar to [[bindValue()]] except that it binds multiple values at a time.
	 * Note that the SQL data type of each value is determined by its PHP type.
	 * @param array $values the values to be bound. This must be given in terms of an associative
	 * array with array keys being the parameter names, and array values the corresponding parameter values,
	 * e.g. `[':name' => 'John', ':age' => 25]`. By default, the PDO type of each value is determined
	 * by its PHP type. You may explicitly specify the PDO type by using an array: `[value, type]`,
	 * e.g. `[':name' => 'John', ':profile' => [$profile, \PDO::PARAM_LOB]]`.
	 * @return static the current command being executed
	 */
	public function bindValues($values)
	{
		if (!empty($values)) {
			$this->prepare();
			foreach ($values as $name => $value) {
				if (is_array($value)) {
					$type = $value[1];
					$value = $value[0];
				}
				else {
					$type = null;
				}
				$this->bindValue($name, $value, $type);
			}
		}

		return $this;
	}

	/**
	 * Executes the SQL statement.
	 * This method should only be used for executing non-query SQL statement, such as `INSERT`, `DELETE`, `UPDATE` SQLs.
	 * No result set will be returned.
	 * @return array number of rows affected by the execution.
	 * @throws Exception execution failed
	 */
	public function execute()
	{
		$token = get_class($this->_container);

		Yii::info(json_encode($this->_container), __METHOD__);

		try {
			Yii::beginProfile($token, __METHOD__);

			$this->prepare();
			$result = $this->executeInternal();

			Yii::endProfile($token, __METHOD__);

			return $result;
		}
		catch (\Exception $e) {
            throw $e;

			Yii::endProfile($token, __METHOD__);
			if ($e instanceof Exception) {
				throw $e;
			} else {
				$message = $e->getMessage() . "\nThe command being executed was: $token";
				$errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
				throw new Exception($message, $errorInfo, (int) $e->getCode(), $e);
			}
		}
	}

	public function executeInternal()
	{
		$result = false;

		if ($this->_query)
		{
			$result = $this->executeCypherQuery();
		}
		elseif ($this->_container)
		{
			$result = $this->executeContainer();
		}

		return $result;
	}

	protected function executeContainer()
	{
		foreach ($this->_container->getProperties() as $property => $value)
		{
			if ($value instanceof Expression)
			{
				$this->_container->setProperty($property, $value->expression);
			}
		}

		$result = $this->_container->save();

		if ($this->_container instanceof Node && $this->label)
		{
			$this->_container->addLabels([$this->label]);
		}

		return $result;
	}

	/**
	 * @return array
	 * @author bk
	 */
	protected function executeCypherQuery()
	{
        $query = new Query($this->db->client, $this->_query, $this->params);
        $command = new ExecuteCypherQuery($this->db->client, $query);

		return $command->execute();
	}

	/**
	 * Executes the SQL statement and returns query result.
	 * This method is for executing a SQL query that returns result set, such as `SELECT`.
	 * @return ResultSet the reader object for fetching the query result
	 * @throws Exception execution failed
	 */
	public function query()
	{
		return $this->queryInternal('');
	}

	/**
	 * Executes the SQL statement and returns ALL rows at once.
	 * @param integer $fetchMode the result fetch mode. Please refer to [PHP manual](http://www.php.net/manual/en/function.PDOStatement-setFetchMode.php)
	 * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
	 * @return array|PropertyContainer all rows of the query result. Each array element is an array representing a row of data.
	 * An empty array is returned if the query results in nothing.
	 * @throws Exception execution failed
	 */
	public function queryAll($fetchMode = null)
	{
		$result = $this->queryInternal($fetchMode);

		return count($result) > 0 ? $result : [];
	}

	/**
	 * Executes the SQL statement and returns the first row of the result.
	 * This method is best used when only the first row of result is needed for a query.
	 * @param integer $fetchMode the result fetch mode. Please refer to [PHP manual](http://www.php.net/manual/en/function.PDOStatement-setFetchMode.php)
	 * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
	 * @return Row|array|boolean the first row (in terms of an array) of the query result. False is returned if the query
	 * results in nothing.
	 * @throws Exception execution failed
	 */
	public function queryOne($fetchMode = null)
	{
		$result = $this->queryInternal($fetchMode);

		return count($result) > 0 ? $result[0] : false;
	}

	/**
	 * Executes the SQL statement and returns the value of the first column in the first row of data.
	 * This method is best used when only a single value is needed for a query.
	 * @return string|null|boolean the value of the first column in the first row of the query result.
	 * False is returned if there is no value.
	 * @throws Exception execution failed
	 */
	public function queryScalar()
	{
		$result = $this->queryInternal(0);
		if (is_resource($result) && get_resource_type($result) === 'stream')
        {
			return stream_get_contents($result);
		}
        elseif (is_array($result))
        {
            if (empty($result))
            {
                return false;
            }

            return $result[0];
		}
        else
        {
            return $result;
        }
	}

	/**
	 * Executes the SQL statement and returns the first column of the result.
	 * This method is best used when only the first column of result (i.e. the first element in each row)
	 * is needed for a query.
	 * @return ResultSet the first column of the query result. Empty array is returned if the query results in nothing.
	 * @throws Exception execution failed
	 */
	public function queryColumn()
	{
		return $this->queryInternal(\PDO::FETCH_COLUMN);
	}

	/**
	 * Performs the actual DB query of a SQL statement.
	 * @param integer $fetchMode the result fetch mode. Please refer to [PHP manual](http://www.php.net/manual/en/function.PDOStatement-setFetchMode.php)
	 * for valid fetch modes. If this parameter is null, the value set in [[fetchMode]] will be used.
	 * @return mixed|array the method execution result
	 * @throws Exception if the query causes any problem
	 */
	private function queryInternal($fetchMode = null)
	{
		$db = $this->db;
		$rawSql = $this->getRawQuery();

		Yii::info($rawSql, __METHOD__);

		/** @var \yii\caching\Cache $cache */
		if ($db->enableQueryCache) {
			$cache = is_string($db->queryCache) ? Yii::$app->get($db->queryCache, false) : $db->queryCache;
		}

		if (isset($cache) && $cache instanceof Cache) {
			$cacheKey = [
				__CLASS__,
				$db->dsn,
				$db->username,
				$rawSql,
			];
			if (($result = $cache->get($cacheKey)) !== false) {
				Yii::trace('Query result served from cache', 'yii\db\Command::query');

				return $result;
			}
		}

		$token = $rawSql;
		try {
			Yii::beginProfile($token, __METHOD__);

			$this->prepare();
			$result = $this->executeInternal();

			Yii::endProfile($token, __METHOD__);

			if (isset($cache, $cacheKey) && $cache instanceof Cache) {
				$cache->set($cacheKey, $result, $db->queryCacheDuration, $db->queryCacheDependency);
				Yii::trace('Saved query result in cache', 'yii\db\Command::query');
			}

			return $result;
		} catch (\Exception $e) {
			Yii::endProfile($token, __METHOD__);
			if ($e instanceof Exception) {
				throw $e;
			} else {
				$message = $e->getMessage()  . "\nThe SQL being executed was: $rawSql";
				$errorInfo = $e instanceof \PDOException ? $e->errorInfo : null;
				throw new Exception($message, $errorInfo, (int) $e->getCode(), $e);
			}
		}
	}

	/**
	 * Creates an INSERT command.
	 * For example,
	 *
	 * ~~~
	 * $connection->createCommand()->insert('user', [
	 *     'name' => 'Sam',
	 *     'age' => 30,
	 * ])->execute();
	 * ~~~
	 *
	 * The method will properly escape the column names, and bind the values to be inserted.
	 *
	 * Note that the created command is not executed until [[execute()]] is called.
	 *
	 * @param string $label the table that new rows will be inserted into.
	 * @param array $properties the column data (name => value) to be inserted into the table.
	 *
	 * @return Command the command object itself
	 */
	public function insert($label, $properties)
	{
        $node = $this->db->client->makeNode($properties);

		return $this->setLabel($label)->setContainer($node);
	}

	/**
	 * Creates a batch INSERT command.
	 * For example,
	 *
	 * ~~~
	 * $connection->createCommand()->batchInsert('user', ['name', 'age'], [
	 *     ['Tom', 30],
	 *     ['Jane', 20],
	 *     ['Linda', 25],
	 * ])->execute();
	 * ~~~
	 *
	 * Note that the values in each row must match the corresponding column names.
	 *
	 * @param string $table the table that new rows will be inserted into.
	 * @param array $columns the column names
	 * @param array $rows the rows to be batch inserted into the table
	 * @return Command the command object itself
	 */
	public function batchInsert($table, $columns, $rows)
	{
		$queryString = $this->db->getQueryBuilder()->batchInsert($table, $columns, $rows);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates an UPDATE command.
	 * For example,
	 *
	 * ~~~
	 * $connection->createCommand()->update('user', ['status' => 1], 'age > 30')->execute();
	 * ~~~
	 *
	 * The method will properly escape the column names and bind the values to be updated.
	 *
	 * Note that the created command is not executed until [[execute()]] is called.
	 *
	 * @param string $label the label to be updated.
	 * @param integer $id
	 * @param array $properties the column data (name => value) to be updated.
	 * @param array $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the parameters to be bound to the command
	 * @return Command the command object itself
	 */
	public function update($label, $properties, $id, $condition = [], $params = [])
	{
		$node = $this->db->client->getNode($id);

        if ($node === null)
        {
            return null;
        }
        else
        {
            $node->setProperties($properties);

            return $this->setLabel($label)->setContainer($node)->bindValues($params);

            $queryString = $this->db->getQueryBuilder()->update($table, $columns, $condition, $params);

            return $this->setQuery($queryString)->bindValues($params);
        }
	}

	/**
	 * Creates a DELETE command.
	 * For example,
	 *
	 * ~~~
	 * $connection->createCommand()->delete('user', 'status = 0')->execute();
	 * ~~~
	 *
	 * The method will properly escape the table and column names.
	 *
	 * Note that the created command is not executed until [[execute()]] is called.
	 *
	 * @param string $label the table where the data will be deleted from.
	 * @param string|array $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the parameters to be bound to the command
	 * @return Command the command object itself
	 */
	public function delete($label, $condition = '', $params = [])
	{
		list($query, $params) = $this->db->getQueryBuilder()->delete($label, $condition, $params);

		return $this->setQuery($query)->bindValues($params);
	}

	/**
	 * Creates a SQL command for renaming a DB table.
	 * @param string $table the table to be renamed. The name will be properly quoted by the method.
	 * @param string $newName the new table name. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function renameTable($table, $newName)
	{
		$queryString = $this->db->getQueryBuilder()->renameTable($table, $newName);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for dropping a DB table.
	 * @param string $table the table to be dropped. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function dropTable($table)
	{
		$queryString = $this->db->getQueryBuilder()->dropTable($table);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for truncating a DB table.
	 * @param string $table the table to be truncated. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function truncateTable($table)
	{
		$queryString = $this->db->getQueryBuilder()->truncateTable($table);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for adding a new DB column.
	 * @param string $table the table that the new column will be added to. The table name will be properly quoted by the method.
	 * @param string $column the name of the new column. The name will be properly quoted by the method.
	 * @param string $type the column type. [[\yii\db\QueryBuilder::getColumnType()]] will be called
	 * to convert the give column type to the physical one. For example, `string` will be converted
	 * as `varchar(255)`, and `string not null` becomes `varchar(255) not null`.
	 * @return Command the command object itself
	 */
	public function addColumn($table, $column, $type)
	{
		$queryString = $this->db->getQueryBuilder()->addColumn($table, $column, $type);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for dropping a DB column.
	 * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
	 * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function dropColumn($table, $column)
	{
		$queryString = $this->db->getQueryBuilder()->dropColumn($table, $column);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for renaming a column.
	 * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
	 * @param string $oldName the old name of the column. The name will be properly quoted by the method.
	 * @param string $newName the new name of the column. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function renameColumn($table, $oldName, $newName)
	{
		$queryString = $this->db->getQueryBuilder()->renameColumn($table, $oldName, $newName);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for changing the definition of a column.
	 * @param string $table the table whose column is to be changed. The table name will be properly quoted by the method.
	 * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
	 * @param string $type the column type. [[\yii\db\QueryBuilder::getColumnType()]] will be called
	 * to convert the give column type to the physical one. For example, `string` will be converted
	 * as `varchar(255)`, and `string not null` becomes `varchar(255) not null`.
	 * @return Command the command object itself
	 */
	public function alterColumn($table, $column, $type)
	{
        $queryString = $this->db->getQueryBuilder()->alterColumn($table, $column, $type);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for adding a primary key constraint to an existing table.
	 * The method will properly quote the table and column names.
	 * @param string $name the name of the primary key constraint.
	 * @param string $table the table that the primary key constraint will be added to.
	 * @param string|array $columns comma separated string or array of columns that the primary key will consist of.
	 * @return Command the command object itself.
	 */
	public function addPrimaryKey($name, $table, $columns)
	{
        $queryString = $this->db->getQueryBuilder()->addPrimaryKey($name, $table, $columns);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for removing a primary key constraint to an existing table.
	 * @param string $name the name of the primary key constraint to be removed.
	 * @param string $table the table that the primary key constraint will be removed from.
	 * @return Command the command object itself
	 */
	public function dropPrimaryKey($name, $table)
	{
        $queryString = $this->db->getQueryBuilder()->dropPrimaryKey($name, $table);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for adding a foreign key constraint to an existing table.
	 * The method will properly quote the table and column names.
	 * @param string $name the name of the foreign key constraint.
	 * @param string $table the table that the foreign key constraint will be added to.
	 * @param string $columns the name of the column to that the constraint will be added on. If there are multiple columns, separate them with commas.
	 * @param string $refTable the table that the foreign key references to.
	 * @param string $refColumns the name of the column that the foreign key references to. If there are multiple columns, separate them with commas.
	 * @param string $delete the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @param string $update the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @return Command the command object itself
	 */
	public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
	{
        $queryString = $this->db->getQueryBuilder()->addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete, $update);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for dropping a foreign key constraint.
	 * @param string $name the name of the foreign key constraint to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function dropForeignKey($name, $table)
	{
        $queryString = $this->db->getQueryBuilder()->dropForeignKey($name, $table);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for creating a new index.
	 * @param string $name the name of the index. The name will be properly quoted by the method.
	 * @param string $table the table that the new index will be created for. The table name will be properly quoted by the method.
	 * @param string|array $columns the column(s) that should be included in the index. If there are multiple columns, please separate them
	 * by commas. The column names will be properly quoted by the method.
	 * @param boolean $unique whether to add UNIQUE constraint on the created index.
	 * @return Command the command object itself
	 */
	public function createIndex($label, $property, $unique)
	{
		$queryString = $this->db->getQueryBuilder()->createIndex($label, $property, $unique);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for dropping an index.
	 * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function dropIndex($label, $property)
	{
		$queryString = $this->db->getQueryBuilder()->dropIndex($label, $property);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for creating a new index.
	 * @param string $name the name of the index. The name will be properly quoted by the method.
	 * @param string $table the table that the new index will be created for. The table name will be properly quoted by the method.
	 * @param string|array $columns the column(s) that should be included in the index. If there are multiple columns, please separate them
	 * by commas. The column names will be properly quoted by the method.
	 * @param boolean $unique whether to add UNIQUE constraint on the created index.
	 * @return Command the command object itself
	 */
	public function createUniqueNodeConstraint($label, $property)
	{
		$queryString = $this->db->getQueryBuilder()->createUniqueNodeConstraint($label, $property);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for dropping an index.
	 * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
	 * @return Command the command object itself
	 */
	public function dropUniqueNodeConstraint($label, $property)
	{
		$queryString = $this->db->getQueryBuilder()->dropIndex($label, $property);

		return $this->setQuery($queryString);
	}

	/**
	 * Creates a SQL command for resetting the sequence value of a table's primary key.
	 * The sequence will be reset such that the primary key of the next new row inserted
	 * will have the specified value or 1.
	 * @param string $table the name of the table whose primary key sequence will be reset
	 * @param mixed $value the value for the primary key of the next new row inserted. If this is not set,
	 * the next new row's primary key will have a value 1.
	 * @return Command the command object itself
	 * @throws NotSupportedException if this is not supported by the underlying DBMS
	 */
	public function resetSequence($table, $value = null)
	{
		$queryString = $this->db->getQueryBuilder()->resetSequence($table, $value);

		return $this->setQuery($queryString);
	}

	/**
	 * Builds a SQL command for enabling or disabling integrity check.
	 * @param boolean $check whether to turn on or off the integrity check.
	 * @param string $schema the schema name of the tables. Defaults to empty string, meaning the current
	 * or default schema.
	 * @param string $table the table name.
	 * @return Command the command object itself
	 * @throws NotSupportedException if this is not supported by the underlying DBMS
	 */
	public function checkIntegrity($check = true, $schema = '', $table = '')
	{
		$queryString = $this->db->getQueryBuilder()->checkIntegrity($check, $schema, $table);

		return $this->setQuery($queryString);
	}
}
