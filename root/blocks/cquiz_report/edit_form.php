<?php

class block_cquiz_report_edit_form extends block_edit_form 
{

	protected function specific_definition($mform) 
	{

		// Section header title according to language file.
		$mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

				
		// A sample string variable with a default value.
		$mform->addElement('text', 'config_title', get_string('blocktitle', 'block_cquiz_report'));
		$mform->setDefault('config_title', get_string('cquiz_report', 'block_cquiz_report'));
		$mform->setType('config_title', PARAM_MULTILANG);
		
		global $DB,$COURSE;
		
		$conditions = array("course"=>$COURSE->id);
		$fields = "id,name";
		$cquizes = $DB->get_records_menu('cquiz',$conditions,"id",$fields);
		
		//now add a drop down for cquiz
		$mform->addElement('select', 'config_cquiz_id', get_string('which_cquiz', 'block_cquiz_report'),
				 $cquizes);
		

	}
}