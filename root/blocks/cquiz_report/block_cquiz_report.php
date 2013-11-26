<?php
class block_cquiz_report extends block_list 
{
	public function init() 
	{
		$this->title = get_string('cquiz_report', 'block_cquiz_report');
	}
	
	
	//called right after config has been filled out
	public function specialization() 
	{
		if (!empty($this->config->title)) 
		{
			$this->title = $this->config->title;
		}
	}
	
	public function get_content() {
		if ($this->content !== null) {
			return $this->content;
		}
	
		$this->content         = new stdClass;
		$this->content->items  = array();
		$this->content->icons  = array();
		$this->content->footer = '';#Footer here...';
	

		// Add list items here
	
		global $COURSE, $DB,$USER,$CFG;
		include_once($CFG->dirroot.'/mod/cquiz/locallib.php');
		//FIXME cquiz should be part of private data...
		
		if(!empty($this->config->cquiz_id) )
		{
			$scores = cquiz_get_cummulative_scores($this->config->cquiz_id,$USER->id);
			
			// Iterate over the instances
			foreach ($scores as $score) 
			{
				//print_object($question);
	//			$this->content->items[] = "$question[1] has $question[2] with answer $question[3]";
				if($score->name !== "EASTER_EGG")
				{
				$text = <<<TEXT
				<h4>$score->name</h4>
				<progress value='$score->last_score' max='$score->max_score' >
	     		 current $score->last_score ,best $score->max_score"</progress>
				<div> current $score->last_score /best $score->max_score</div>
TEXT;
				
				
				$this->content->items[] = $text; 
				//$this->content->icons[] = html_writer::empty_tag('img', array('src' => '../pix/i/tick_green_big.gif', 'class' => 'icon'));
				}			
			}
		}
		
			
		return $this->content;
	}
	
	
	
	public function instance_allow_multiple() 
	{
		return false;
	}
	
	
}

