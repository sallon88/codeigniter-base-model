<?php
/**
 * A base model with a series of CRUD functions (powered by CI's query builder),
 * validation-in-model support, event callbacks and more.
 *
 * @link http://github.com/jamierumbelow/codeigniter-base-model
 * @copyright Copyright (c) 2012, Jamie Rumbelow <http://jamierumbelow.net>
 */

class MY_Model extends CI_Model
{

    /* --------------------------------------------------------------
     * VARIABLES
     * ------------------------------------------------------------ */

    /**
     * This model's default database table. Automatically
     * guessed by pluralising the model name.
     */
    protected $table;
    
    /**
     * The database connection object. Will be set to the default
     * connection. This allows individual models to use different DBs 
     * without overwriting CI's global $this->db connection.
     */
	protected $db_group;
    public $database;

    /**
     * This model's default primary key or unique identifier.
     * Used by the get(), update() and delete() functions.
     */
    protected $primary_key = 'id';

    /**
     * A formatting string for the model autoloading feature.
     * The percent symbol (%) will be replaced with the model name.
     */
    protected $model_string = '%_model';

    /**
     * Support for soft deletes and this model's 'deleted' key
     */
    protected $soft_delete = FALSE;
    protected $soft_delete_key = 'deleted';
    protected $_temporary_with_deleted = FALSE;
    protected $_temporary_only_deleted = FALSE;

    /**
     * The various callbacks available to the model. Each are
     * simple lists of method names (methods will be run on $this).
     */
    protected $before_create = array();
    protected $after_create = array();
    protected $before_update = array();
    protected $after_update = array();
    protected $before_get = array();
    protected $after_get = array();
    protected $before_delete = array();
    protected $after_delete = array();

    protected $callback_parameters = array();

    /**
     * Protected, non-modifiable attributes
     */
    protected $protected_attributes = array();

    /**
     * Relationship arrays. Use flat strings for defaults or string
     * => array to customise the class name and primary key
     */
    protected $belongs_to = array();
    protected $has_many = array();
	protected $has_one = array();

    protected $_with = array();

    /**
     * An array of validation rules. This needs to be the same format
     * as validation rules passed to the Form_validation library.
     */
    protected $validates = array();

    /**
     * Optionally skip the validation. Used in conjunction with
     * skip_validation() to skip data validation for any future calls.
     */
    protected $skip_validation = FALSE;

    /* --------------------------------------------------------------
     * GENERIC METHODS
     * ------------------------------------------------------------ */

    /**
     * Initialise the model, tie into the CodeIgniter superobject and
     * try our best to guess the table name.
     */
    public function __construct()
    {
        parent::__construct();

		// when loaded as a instance of MY_Model, don't proceed table initiation 
		// this happened when first called load_class('Model','core');
		if (get_class($this) == get_class())
		{
			return;
		}

        $this->load->helper('inflector');

        $this->_fetch_table();

		if ($this->db_group)
		{
			
			/*
			see http://stackoverflow.com/questions/634291/codeigniter-using-multiple-databases

			when loaded another db_group, the original connection will break;

			solution:
			if you use mysql driver, set pconnect to false, or use the pdo driver
			*/

			$this->database = $this->load->database($this->db_group, true);
		}
		else
		{
			$this->database = $this->db;
		}

        array_unshift($this->before_create, 'protect_attributes');
        array_unshift($this->before_update, 'protect_attributes');
    }

    /* --------------------------------------------------------------
     * CRUD INTERFACE
     * ------------------------------------------------------------ */

    /**
     * Fetch a single record based on the primary key. 
     */
    public function get($primary_value)
    {
		return $this->get_by($this->primary_key, $primary_value);
    }

    /**
     * Fetch a single record based on an arbitrary WHERE call. Can be
     * any valid value to $this->database->where().
     */
    public function get_by()
    {
        $where = func_get_args();
        
        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, (bool)$this->_temporary_only_deleted);
        }
		
		$this->_set_where($where);
        $this->trigger('before_get');

        if ($row = $this->database->limit(1)->get($this->table)->row_array())
		{
			$row = $this->trigger('after_get', $row);
		}

        $this->_with = array();
        return $row;
    }

    /**
     * Fetch an array of records based on an array of primary values.
     */
    public function get_many($values)
    {
        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, (bool)$this->_temporary_only_deleted);
        }

        $this->database->where_in($this->primary_key, $values);

        return $this->get_all();
    }

    /**
     * Fetch an array of records based on an arbitrary WHERE call.
     */
    public function get_many_by()
    {
        $where = func_get_args();

        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, (bool)$this->_temporary_only_deleted);
        }

        $this->_set_where($where);

        return $this->get_all();
    }

    /**
     * Fetch all the records in the table. Can be used as a generic call
     * to $this->database->get() with scoped methods.
     */
    public function get_all()
    {
        $this->trigger('before_get');
        
        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, (bool)$this->_temporary_only_deleted);
        }
        
        $result = $this->database->get($this->table)->result_array();

        foreach ($result as $key => &$row)
        {
            $row = $this->trigger('after_get', $row);
        }

        $this->_with = array();
        return $result;
    }

    /**
     * Insert a new row into the table. $data should be an associative array
     * of data to be inserted. Returns newly created ID.
     */
    public function insert($data, $skip_validation = FALSE)
    {
        if ($skip_validation === FALSE)
        {
            $data = $this->validate($data, FALSE);
        }

        if ($data !== FALSE)
        {
            $data = $this->trigger('before_create', $data);

            if ($this->database->insert($this->table, $data))
			{
				$insert_id = $this->database->insert_id();

				$this->trigger('after_create', $data, $insert_id);

				return $insert_id;
			}
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Insert multiple rows into the table. Returns an array of multiple IDs.
     */
    public function insert_many($data, $skip_validation = FALSE)
    {
        $ids = array();

        foreach ($data as $key => $row)
        {
            $ids[] = $this->insert($row, $skip_validation, ($key == count($data) - 1));
        }

        return $ids;
    }

    /**
     * Updated a record based on the primary value.
     */
    public function update($primary_value, $data = array(), $skip_validation = FALSE)
    {
        $data = $this->trigger('before_update', $data);

        if ($skip_validation === FALSE)
        {
            $data = $this->validate($data);
        }

        if ($data !== FALSE)
        {
            $result = $this->database->where($this->primary_key, $primary_value)
                               ->set($data)
                               ->update($this->table);

			if ($result)
			{
				$this->trigger('after_update', $data);
			}

            return $result;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Update many records, based on an array of primary values.
     */
    public function update_many($primary_values, $data = array(), $skip_validation = FALSE)
    {
        $data = $this->trigger('before_update', $data);

        if ($skip_validation === FALSE)
        {
            $data = $this->validate($data);
        }

        if ($data !== FALSE)
        {
            $result = $this->database->where_in($this->primary_key, $primary_values)
                               ->set($data)
                               ->update($this->table);

			if ($result)
			{
				$this->trigger('after_update', $data);
			}

            return $result;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Updated a record based on an arbitrary WHERE clause.
     */
    public function update_by()
    {
        $args = func_get_args();
        $data = array_pop($args);

        $data = $this->trigger('before_update', $data);

        if ($this->validate($data) !== FALSE)
        {
            $this->_set_where($args);
            $result = $this->database->set($data)
                               ->update($this->table);
			if ($result)
			{
				$this->trigger('after_update', $data);
			}

            return $result;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * Update all records
     */
    public function update_all($data)
    {
        $data = $this->trigger('before_update', $data);
        $result = $this->database->set($data)
                           ->update($this->table);

		if ($result)
		{
			$this->trigger('after_update', $data);
		}

        return $result;
    }

    /**
     * Delete a row from the table by the primary value
     */
    public function delete($id)
    {
        $this->trigger('before_delete', $id);

        $this->database->where($this->primary_key, $id);

        if ($this->soft_delete)
        {
            $result = $this->database->update($this->table, array( $this->soft_delete_key => TRUE ));
        }
        else
        {
            $result = $this->database->delete($this->table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }

    /**
     * Delete a row from the database table by an arbitrary WHERE clause
     */
    public function delete_by()
    {
        $where = func_get_args();

	    $where = $this->trigger('before_delete', $where);

        $this->_set_where($where);


        if ($this->soft_delete)
        {
            $result = $this->database->update($this->table, array( $this->soft_delete_key => TRUE ));
        }
        else
        {
            $result = $this->database->delete($this->table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }

    /**
     * Delete many rows from the database table by multiple primary values
     */
    public function delete_many($primary_values)
    {
        $primary_values = $this->trigger('before_delete', $primary_values);

        $this->database->where_in($this->primary_key, $primary_values);

        if ($this->soft_delete)
        {
            $result = $this->database->update($this->table, array( $this->soft_delete_key => TRUE ));
        }
        else
        {
            $result = $this->database->delete($this->table);
        }

        $this->trigger('after_delete', $result);

        return $result;
    }


    /**
     * Truncates the table
     */
    public function truncate()
    {
        $result = $this->database->truncate($this->table);

        return $result;
    }

    /* --------------------------------------------------------------
     * RELATIONSHIPS
     * ------------------------------------------------------------ */

    public function with($relationship)
    {
		$parameters = func_get_args();
		array_shift($parameters);
        $this->_with[$relationship] = $parameters;

        if (!in_array('relate', $this->after_get))
        {
            $this->after_get[] = 'relate';
        }

        return $this;
    }

    public function relate($row)
    {
		if (empty($row))
        {
		    return $row;
        }

		foreach($this->_with as $relationship => $parameters)
		{
			if (isset($this->belongs_to[$relationship]) OR in_array($relationship, $this->belongs_to))
			{
				$row = $this->relate_belongs_to($row, $relationship, $parameters);
				continue;
			}

			if (isset($this->has_many[$relationship]) OR in_array($relationship, $this->has_many))
			{
				$row = $this->relate_has_many($row, $relationship, $parameters);
				continue;
			}

			if (isset($this->has_one[$relationship]) OR in_array($relationship, $this->has_one))
			{
				$row = $this->relate_has_one($row, $relationship, $parameters);
				continue;
			}

			//自定义with
			$callback_with = array($this, "with_$relationship");
			if (is_callable($callback_with))
			{
				array_unshift($parameters, $row);
				$row = call_user_func_array($callback_with, $parameters);
			}
		}

		return $row;
	}

	//with('relation_name', 'field1, field2');
	public function relate_belongs_to($row, $relationship, $parameters)
	{
		$default_options = array('foreign_key' => $relationship . '_id', 'model' => $this->_model_name($relationship));

		$options = isset($this->belongs_to[$relationship]) ? $this->belongs_to[$relationship] : array();
		$options = array_merge($default_options, $options);

		$this->load->model($options['model']);

		if (isset($parameters[0]))
		{
			$this->{$options['model']}->field($parameters[0]);
		}

		$row[$relationship] = $this->{$options['model']}->get($row[$options['foreign_key']]);
		return $row;
	}

	//with('relation_name', 'field1, field2', 'limit, offset', 'order_by');
	public function relate_has_many($row, $relationship, $parameters)
	{
		$default_options = array('foreign_key' => singular($this->table) . '_id', 'model' => $this->_model_name(singular($relationship)));
		$options = isset($this->has_many[$relationship]) ? $this->has_many[$relationship] : array();
		$options = array_merge($default_options, $options);

		$this->load->model($options['model']);

		if (isset($parameters[0]))
		{
			$this->{$options['model']}->field($parameters[0]);
		}

		if (isset($parameters[1]))
		{
			call_user_func_array(array($this->{$options['model']}, 'limit'), explode(',', $parameters[1]));
		}

		if (isset($parameters[2]))
		{
			$this->{$options['model']}->order_by($parameters[2]);
		}

		$row[$relationship] = $this->{$options['model']}->get_many_by($options['foreign_key'], $row[$this->primary_key]);
		return $row;
	}

	//with('relation_name', 'field1, field2');
	public function relate_has_one($row, $relationship, $parameters)
	{
		$default_options = array('foreign_key' => singular($this->table) . '_id', 'model' => $this->_model_name($relationship));
		$options = isset($this->has_one[$relationship]) ? $this->has_one[$relationship] : array();
		$options = array_merge($default_options, $options);

		$this->load->model($options['model']);

		if (isset($parameters[0]))
		{
			$this->{$options['model']}->field($parameters[0]);
		}

		$row[$relationship] = $this->{$options['model']}->get_by($options['foreign_key'], $row[$this->primary_key]);
		return $row;
	}
		

    /* --------------------------------------------------------------
     * UTILITY METHODS
     * ------------------------------------------------------------ */

    /**
     * Retrieve and generate a form_dropdown friendly array
     */
    function dropdown()
    {
        $args = func_get_args();

        if(count($args) == 2)
        {
            list($key, $value) = $args;
        }
        else
        {
            $key = $this->primary_key;
            $value = $args[0];
        }

        $this->trigger('before_dropdown', array( $key, $value ));

        if ($this->soft_delete && $this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, FALSE);
        }

        $result = $this->database->select(array($key, $value))
                           ->get($this->table)
                           ->result();

        $options = array();

        foreach ($result as $row)
        {
            $options[$row->{$key}] = $row->{$value};
        }

        $options = $this->trigger('after_dropdown', $options);

        return $options;
    }

    /**
     * Fetch a count of rows based on an arbitrary WHERE call.
     */
    public function count_by()
    {
        $where = func_get_args();
        $this->_set_where($where);

        return $this->database->count_all_results($this->table);
    }

    /**
     * Fetch a total count of rows, disregarding any previous conditions
     */
    public function count_all()
    {
        return $this->database->count_all($this->table);
    }

    /**
     * Tell the class to skip the insert validation
     */
    public function skip_validation()
    {
        $this->skip_validation = TRUE;
        return $this;
    }

    /**
     * Get the skip validation status
     */
    public function get_skip_validation()
    {
        return $this->skip_validation;
    }

    /**
     * Return the next auto increment of the table. Only tested on MySQL.
     */
    public function get_next_id()
    {
        return (int) $this->database->select('AUTO_INCREMENT')
            ->from('information_schema.TABLES')
            ->where('TABLE_NAME', $this->table)
            ->where('TABLE_SCHEMA', $this->database->database)->get()->row()->AUTO_INCREMENT;
    }

    /**
     * Getter for the table name
     */
    public function table()
    {
        return $this->table;
    }

    /* --------------------------------------------------------------
     * GLOBAL SCOPES
     * ------------------------------------------------------------ */

    /**
     * Don't care about soft deleted rows on the next call
     */
    public function with_deleted()
    {
        $this->_temporary_with_deleted = TRUE;
        return $this;
    }
    
    /**
     * Only get deleted rows on the next call
     */
    public function only_deleted()
    {
        $this->_temporary_only_deleted = TRUE;
        return $this;
    }

    /* --------------------------------------------------------------
     * OBSERVERS
     * ------------------------------------------------------------ */

    /**
     * MySQL DATETIME created_at and updated_at
     */
    protected function created_at($row)
    {
		$row['created_at'] = date('Y-m-d H:i:s');

        return $row;
    }

    protected function updated_at($row)
    {
		$row['updated_at'] = date('Y-m-d H:i:s');

        return $row;
    }

    /**
     * Serialises data for you automatically, allowing you to pass
     * through objects and let it handle the serialisation in the background
     */
    protected function serialize($row)
    {
        foreach ($this->callback_parameters as $column)
        {
			if (isset($row[$column]))
			{
				$row[$column] = serialize($row[$column]);
			}
        }

        return $row;
    }

    protected function unserialize($row)
    {
        foreach ($this->callback_parameters as $column)
        {
			if (isset($row[$column]))
			{
				$row[$column] = unserialize($row[$column]);
			}
        }

        return $row;
    }

    protected function json_encode($row)
    {
        foreach ($this->callback_parameters as $column)
        {
			if (isset($row[$column]))
			{
				$row[$column] = json_encode($row[$column]);
			}
        }

        return $row;
    }

    protected function json_decode($row)
    {
        foreach ($this->callback_parameters as $column)
        {
			if (isset($row[$column]))
			{
				$row[$column] = json_decode($row[$column], true);
			}
        }

        return $row;
    }

	protected function markdown_parse($row)
	{
		foreach($this->callback_parameters as $column)
		{
			if (isset($row[$column]))
			{
				$row[$column . '_markdown'] = $row[$column];
				$row[$column] = Markdown::parse($row[$column]);
			}
		}

		return $row;
	}

    /**
     * Protect attributes by removing them from $row array
     */
    protected function protect_attributes($row)
    {
        foreach ($this->protected_attributes as $attr)
        {
			unset($row[$attr]);
        }

        return $row;
    }

    /* --------------------------------------------------------------
     * QUERY BUILDER DIRECT ACCESS METHODS
     * ------------------------------------------------------------ */

    /**
     * A wrapper to $this->database->order_by()
     */
    public function order_by($criteria, $order = 'ASC')
    {
        if ( is_array($criteria) )
        {
            foreach ($criteria as $key => $value)
            {
                $this->database->order_by($key, $value);
            }
        }
        else
        {
            $this->database->order_by($criteria, $order);
        }
        return $this;
    }

    /**
     * A wrapper to $this->database->limit()
     */
    public function limit($limit, $offset = 0)
    {
        $this->database->limit($limit, $offset);
        return $this;
    }

	public function field($field, $protect_field = TRUE)
	{
		$this->database->select($field, $protect_field);
		return $this;
	}

    /* --------------------------------------------------------------
     * INTERNAL METHODS
     * ------------------------------------------------------------ */

    /**
     * Trigger an event and call its observers. Pass through the event name
     * (which looks for an instance variable $this->event_name), an array of
     * parameters to pass through and an optional 'last in interation' boolean
     */
    public function trigger($event, $data = FALSE)
    {
		$args = array_slice(func_get_args(), 2);
        if (isset($this->$event) && is_array($this->$event))
        {
            foreach ($this->$event as $method)
            {
                if (strpos($method, '('))
                {
                    preg_match('/([a-zA-Z0-9\_\-]+)(\(([a-zA-Z0-9\_\-\., ]+)\))?/', $method, $matches);

                    $method = $matches[1];
                    $this->callback_parameters = array_map('trim', explode(',', $matches[3]));
                }

				//使用临时变量$_args以防止原始$args被修改
				$_args = $args;
				array_unshift($_args, $data);
                $data = call_user_func_array(array($this, $method), $_args);
            }
        }

		return $data;
    }

    /**
     * Run validation on the passed data
     */
    public function validate($data, $update = true)
    {
        if($this->skip_validation)
        {
            return $data;
        }

		// 当为更新时,需要去除掉不存在data中的规则
		$validates = $this->validates;
		if ($update)
		{
			foreach($validates as $key => $validate)
			{
				if ( ! array_key_exists($validate['field'], $data))
				{
					unset($validates[$key]);
				}
			}
		}
        
        if(!empty($validates))
        {
			//缓存原post数据
			$post_data = $_POST;
			$_POST =& $data;

			$this->load->library('form_validation');

			//清楚可能存在的，上次运行validation的信息
			$this->form_validation->clear_last_info();
			$this->form_validation->set_rules($validates);

			if ($this->form_validation->run() === FALSE)
			{
				$data = FALSE;
			}

			//恢复原post数据
			$_POST =& $post_data;
		}

		return $data;
    }

    /**
     * Guess the table name by pluralising the model name
     */
    private function _fetch_table()
    {
        if ($this->table == NULL)
        {
			$regex = '/^'.str_replace('%', '(?P<name>.+)', $this->model_string).'$/';
			$model_name = get_class($this);
			if (preg_match($regex, $model_name, $matches))
			{
				$table_name = $matches['name'];
			}
			else
			{
				$table_name = $model_name;
			}

			$this->table = plural(strtolower($table_name));
        }
    }

    /**
     * Set WHERE parameters, cleverly
     */
    protected function _set_where($params)
    {
        if (count($params) == 1)
        {
            $this->database->where($params[0]);
        }
    	else if(count($params) == 2)
		{
			$this->database->where($params[0], $params[1]);
		}
		else if(count($params) == 3)
		{
			$this->database->where($params[0], $params[1], $params[2]);
		}
        else
        {
            $this->database->where($params);
        }
    }

    /**
     * Returns the loadable model name based on
     * the model formatting string
     */
    protected function _model_name($model)
    {
        return str_replace('%', $model, $this->model_string);
    }
}
