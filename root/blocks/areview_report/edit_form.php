<?php

class block_areview_report_edit_form extends block_edit_form 
{

	protected function specific_definition($mform) 
	{

		// Section header title according to language file.
		$mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

				
		// A sample string variable with a default value.
		$mform->addElement('text', 'config_title', get_string('blocktitle', 'block_areview_report'));
		$mform->setDefault('config_title', get_string('areview_report', 'block_areview_report'));
		$mform->setType('config_title', PARAM_MULTILANG);
		
		global $DB,$COURSE;
		
		$conditions = array("course"=>$COURSE->id);
		$fields = "id,name";
		$areviewes = $DB->get_records_menu('areview',$conditions,"id",$fields);
		
		//now add a drop down for areview
		$mform->addElement('select', 'config_areview_id', get_string('which_areview', 'block_areview_report'),
				 $areviewes);
		

	}
}