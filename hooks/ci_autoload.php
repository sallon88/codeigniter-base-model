<?php
function ci_autoload($class_name)
{
	if (strpos($class_name, 'CI_') === 0)
	{
		return;
	}

	foreach(array('core/', 'libraries/') as $dir)
	{
		$file = APPPATH . $dir . $class_name . '.php';
		if (file_exists($file))
		{
			return require $file;
		}
	}
}

function ci_autoload_register()
{
	spl_autoload_register('ci_autoload');
}
