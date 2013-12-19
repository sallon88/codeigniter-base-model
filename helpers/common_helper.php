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
