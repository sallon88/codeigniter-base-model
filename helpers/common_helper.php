<?php

function model($name)
{
	$CI =& get_instance();
	$model_name = strtolower($name) . '_model';
	if ( ! isset($CI->$model_name))
	{
		$CI->load->model($model_name);
	}

	return $CI->$model_name;
}

function array_associate($array, $key = 'id')
{
	$ret = array();

	if ( ! is_array($array))
	{
		return $ret;
	}

	foreach($array as $value)
	{
		if (isset($value[$key]))
		{
			$ret[$value[$key]] = $value;
		}
	}

	return $ret;
}

function array_collect($array, $key = 'id')
{
	$ret = array();
	foreach ($array as $one)
	{
		if (isset($one[$key]))
		{
			$ret[] = $one[$key];
		}
	}

	return array_unique($ret);
}

function array_key_rename(&$data, $old_name, $new_name = null)
{
	if (is_string($old_name) && is_string($new_name))
	{
		$rename = array($old_name => $new_name);
	}
	elseif (is_array($old_name))
	{
		$rename = $old_name;
	}
	else
	{
		return $data;
	}

	foreach ($rename as $old_name => $new_name)
	{
		if (empty($new_name))
		{
			continue;
		}

		if (isset($data[$old_name]))
		{
			$data[$new_name] = $data[$old_name];
			unset($data[$old_name]);
		}
	}

	return $data;
}
