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

	//{{{ VARIABLES

    /**
     * This model's default database table. Automatically
     * guessed by pluralising the model name.
     */
    protected $table_name;
    
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

	protected $_row_cache = array();

    /**
     * An array of validation rules. This needs to be the same format
     * as validation rules passed to the Form_validation library.
     */
    protected $validates = array();
	//}}}

	// {{{ GENERIC METHODS

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

        array_unshift($this->before_create, 'protect_attributes', 'validate_insert');
        array_unshift($this->before_update, 'protect_attributes', 'validate_update');

		if ($this->soft_delete)
		{
			array_unshift($this->before_get, 'soft_delete');
		}
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
        
		$this->_set_where($where);
        $this->trigger('before_get');

        if ($row = $this->database->limit(1)->get($this->table_name)->row_array())
		{
			$row = $this->trigger('after_get', $row);
		}

		$this->_reset_temporary();
        return $row;
    }

	public function get_field($primary_key, $field = null)
	{
		if (isset($this->_row_cache[$primary_key]))
		{
			$row = $this->_row_cache[$primary_key];
		}
		else
		{
			$row = $this->get($primary_key);
			if ($row)
			{
				$this->_row_cache[$primary_key] = $row;
			}
		}

		if ( ! isset($field))
		{
			return $row;
		}

		return isset($row[$field]) ? $row[$field] : '';
	}

    /**
     * Fetch an array of records based on an array of primary values.
     */
    public function get_many($values)
    {
		return $this->get_many_by($this->primary_key, $values);
    }

    /**
     * Fetch an array of records based on an arbitrary WHERE call.
     */
    public function get_many_by()
    {
		$where = func_get_args();
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
        
        $result = $this->database->get($this->table_name)->result_array();

		$result = $this->batch_relate($result);

        foreach ($result as $key => &$row)
        {
            $row = $this->trigger('after_get', $row);
        }

		$this->_reset_temporary();
        return $result;
    }


    /**
     * Insert a new row into the table. $data should be an associative array
     * of data to be inserted. Returns newly created ID.
     */
    public function insert($data)
    {
		$data = $this->trigger('before_create', $data);

		if ($data !== FALSE && $this->database->insert($this->table_name, $data))
		{
			$insert_id = $this->database->insert_id();

			$this->trigger('after_create', $data, $insert_id);

			return $insert_id ? $insert_id : TRUE;
		}

		return FALSE;
    }

    /**
     * Insert multiple rows into the table. Returns an array of multiple IDs.
     */
    public function insert_many($data)
    {
        $ids = array();

        foreach ($data as $key => $row)
        {
            $ids[] = $this->insert($row);
        }

        return $ids;
    }

    /**
     * Updated a record based on the primary value.
     */
    public function update($primary_value, $data = array())
    {
		return $this->update_by($this->primary_key, $primary_value, $data);
    }

    /**
     * Updated a record based on an arbitrary WHERE clause.
     */
    public function update_by()
    {
        $args = func_get_args();
        $data = array_pop($args);

        $data = $this->trigger('before_update', $data);

        if ($data !== FALSE)
        {
            $this->_set_where($args);
            $result = $this->database->set($data)
                               ->update($this->table_name);
			if ($result)
			{
				$this->trigger('after_update', $data);
			}

            return $result;
        }

		return FALSE;
    }

    /**
     * Delete a row from the table by the primary value
     */
    public function delete($primary_value)
    {
		return $this->delete_by($this->primary_key, $primary_value);
    }

    /**
     * Delete a row from the database table by an arbitrary WHERE clause
     */
    public function delete_by()
    {
        $where = func_get_args();

		$this->_set_where($where);
	    $this->trigger('before_delete');

		if ($this->soft_delete)
		{
			$result = $this->database->update($this->table_name, array($this->soft_delete_key => TRUE));
		}
		else
		{
			$result = $this->database->delete($this->table_name);
		}

		if ($result)
		{
			$this->trigger('after_delete', $where);
		}

		return $result;
    }

    /**
     * Truncates the table
     */
    public function truncate()
    {
        return $this->database->truncate($this->table_name);
    }

    public function with($relationship)
    {
		$parameters = func_get_args();
		array_shift($parameters);
        $this->_with[$relationship] = $parameters;

        if ( ! in_array('relate', $this->after_get))
        {
            $this->after_get[] = 'relate';
        }

        return $this;
    }
	//}}}

	//{{{ UTILITY METHODS
    /**
     * Retrieve and generate a form_dropdown friendly array
     */
    public function dropdown()
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

		$this->trigger('before_get');
        $result = $this->database->select(array($key, $value))
                           ->get($this->table_name)
                           ->result();

        $options = array();

        foreach ($result as $row)
        {
            $options[$row->{$key}] = $row->{$value};
        }

        return $options;
    }

    /**
     * Fetch a count of rows based on an arbitrary WHERE call.
     */
    public function count_by()
    {
        $where = func_get_args();
        $this->_set_where($where);

		$this->trigger('before_get');
        return $this->database->count_all_results($this->table_name);
    }

    /**
     * Fetch a total count of rows, disregarding any previous conditions
     */
    public function count_all()
    {
        return $this->database->count_all($this->table_name);
    }

    /**
     * Return the next auto increment of the table. Only tested on MySQL.
     */
    public function get_next_id()
    {
        return (int) $this->database->select('AUTO_INCREMENT')
            ->from('information_schema.TABLES')
            ->where('TABLE_NAME', $this->table_name)
            ->where('TABLE_SCHEMA', $this->database->database)->get()->row()->AUTO_INCREMENT;
    }

	public function primary_key()
	{
		return $this->primary_key;
	}
	//}}}

	//{{{ CHAINED METHODS
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

    /**
     * A wrapper to $this->database->order_by()
     */
    public function order_by()
    {
		$order_by = func_get_args();
		call_user_func_array(array($this->database, 'order_by'), $order_by);
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

	public function search($keyword, $fields)
	{
		if ( ! is_array($fields))
		{
			$fields = array_filter(array_map('trim', explode(',', $fields)));
		}

		if (empty($fields) OR empty($keyword))
		{
			return $this;
		}

		$keyword = "%" . addslashes($keyword) . "%";

		$likes = array();
		foreach($fields as $field)
		{
			$likes[] = "{$this->table_name}.$field like '$keyword'";
		}

		$likes = implode(' OR ', $likes);

		//(email like '$q' OR name like '$q' OR mobile like '$q');
		$where = "($likes)";

		$this->database->where($where);
		return $this;
	}
	//}}}

	//{{{ OBSERVERS
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

	protected function validate_insert($row)
	{
		return $this->validate($row);
	}

	protected function validate_update($row)
	{
		return $this->validate($row, false);
	}

	protected function soft_delete()
	{
        if ($this->_temporary_with_deleted !== TRUE)
        {
            $this->database->where($this->soft_delete_key, (bool)$this->_temporary_only_deleted);
        }
	}
	//}}}

	//{{{ RELATIONSHIPS
    protected function relate($row)
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

	//solve n+1 problems
	protected function batch_relate($result)
	{
		if (empty($result))
		{
			return $result;
		}

		foreach($this->_with as $relationship => $parameters)
		{
			if (isset($this->belongs_to[$relationship]) OR in_array($relationship, $this->belongs_to))
			{
				$result = $this->batch_relate_belongs_to($result, $relationship, $parameters);
				unset($this->_with[$relationship]);
				continue;
			}

			if (isset($this->has_one[$relationship]) OR in_array($relationship, $this->has_one))
			{
				$result = $this->batch_relate_has_one($result, $relationship, $parameters);
				unset($this->_with[$relationship]);
				continue;
			}
		}

		return $result;
	}


	//with('relation_name', 'field1, field2');
	protected function relate_belongs_to($row, $relationship, $parameters, $batch_relate = FALSE)
	{
		$default_options = array(
			'foreign_key' => $relationship . '_id',
			'model' => $this->_model_name($relationship)
		);

		$options = isset($this->belongs_to[$relationship]) ? $this->belongs_to[$relationship] : array();
		$options = array_merge($default_options, $options);

		$this->load->model($options['model']);

		if (isset($parameters[0]))
		{
			$this->{$options['model']}->field($parameters[0]);
		}

		if ($batch_relate)
		{
			return $options;
		}

		$row[$relationship] = $this->{$options['model']}->get($row[$options['foreign_key']]);
		return $row;
	}

	protected function batch_relate_belongs_to($result, $relationship, $parameters)
	{
		$options = $this->relate_belongs_to(NULL, $relationship, $parameters, TRUE);

		$relation_model = $this->{$options['model']};
		$foreign_key = $options['foreign_key'];

		$foreign_ids = array_collect($result, $foreign_key);
		$relation_result = array_associate($relation_model->get_many($foreign_ids), $relation_model->primary_key());

		foreach($result as &$row)
		{
			if (isset($relation_result[$row[$foreign_key]]))
			{
				$row[$relationship] = $relation_result[$row[$foreign_key]];
			}
			else
			{
				$row[$relationship] = array();
			}
		}

		return $result;
	}

	//with('relation_name', 'field1, field2', 'limit, offset', 'id desc');
	protected function relate_has_many($row, $relationship, $parameters)
	{
		$default_options = array(
			'foreign_key' => singular($this->table_name) . '_id',
			'model' => $this->_model_name(singular($relationship))
		);
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
	protected function relate_has_one($row, $relationship, $parameters, $batch_relate = FALSE)
	{
		$default_options = array(
			'foreign_key' => singular($this->table_name) . '_id', 
			'model' => $this->_model_name($relationship)
		);

		$options = isset($this->has_one[$relationship]) ? $this->has_one[$relationship] : array();
		$options = array_merge($default_options, $options);

		$this->load->model($options['model']);

		if (isset($parameters[0]))
		{
			$this->{$options['model']}->field($parameters[0]);
		}

		if ($batch_relate)
		{
			return $options;
		}

		$row[$relationship] = $this->{$options['model']}->get_by($options['foreign_key'], $row[$this->primary_key]);
		return $row;
	}

	protected function batch_relate_has_one($result, $relationship, $parameters)
	{
		$options = $this->relate_has_one(NULL, $relationship, $parameters, TRUE);

		$relation_model = $this->{$options['model']};
		$foreign_key = $options['foreign_key'];

		$primary_ids = array_collect($result, $this->primary_key);
		$relation_result = array_associate($relation_model->get_many_by($foreign_key, $primary_ids), $foreign_key);

		foreach($result as &$row)
		{
			if (isset($relation_result[$row[$this->primary_key]]))
			{
				$row[$relationship] = $relation_result[$row[$this->primary_key]];
			}
			else
			{
				$row[$relationship] = array();
			}
		}

		return $result;
	}
	//}}}

	//{{{ INTERNAL METHODS
	
    /**
     * Trigger an event and call its observers. Pass through the event name
     * (which looks for an instance variable $this->event_name), an array of
     * parameters to pass through and an optional 'last in interation' boolean
     */
    protected function trigger($event, $data = array())
    {
		$args = func_get_args();
		$args = array_slice($args, 2);
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

				if ($data === FALSE)
				{
					break;
				}
            }
        }

		return $data;
    }

    /**
     * Run validation on the passed data
     */
    protected function validate($data, $strict = true)
    {
		if ( ! is_array($data))
		{
			return FALSE;
		}

		$validates = $this->validates;

		// bypass rules that are not specified in $data
		if ( ! $strict)
		{
			foreach($validates as $key => $validate)
			{
				if ( ! array_key_exists($validate['field'], $data))
				{
					unset($validates[$key]);
				}
			}
		}

        if( ! empty($validates))
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
        if ($this->table_name == NULL)
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

			$this->table_name = plural(strtolower($table_name));
        }
    }

    /**
     * Set WHERE parameters, cleverly
     */
    protected function _set_where($params)
    {
		if (empty($params))
		{
			return;
		}

		$where = array();
		if (is_array($params[0]))
		{
			$where = $params[0];
		}
		elseif (is_string($params[0]) && isset($params[1]))
		{
			$where[$params[0]] = $params[1];
		}
		else
		{
			show_error('where: wrong parameters');
		}

		foreach($where as $field => $condition)
		{
			//$where = array('user_id' => array(1,2,3))
			if (is_array($condition))
			{
				$condition = empty($condition) ? array('') : $condition;
				$this->database->where_in($field, $condition);
				continue;
			}

			$this->database->where($field, $condition);
		}
    }

	protected function _reset_temporary()
	{
        $this->_with = array();
		$this->_temporary_only_deleted = FALSE;
		$this->_temporary_with_deleted = FALSE;
	}

    /**
     * Returns the loadable model name based on
     * the model formatting string
     */
    protected function _model_name($model)
    {
        return str_replace('%', $model, $this->model_string);
    }
	//}}}
}
