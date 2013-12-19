<?php
class MY_Form_validation extends CI_Form_validation {

	public function clear_last_info()
	{
		$this->_field_data = array();
	}
}
