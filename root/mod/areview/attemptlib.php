<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Back-end code for handling data about areviewzes and the current user's attempt.
 *
 * There are classes for loading all the information about a areview and attempts,
 * and for displaying the navigation panel.
 *
 * @package   mod_areview
 * @copyright 2008 onwards Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/locallib.php');
require_once($CFG->dirroot . '/lib/conditionlib.php');

/**
 * Class for areview exceptions. Just saves a couple of arguments on the
 * constructor for a moodle_exception.
 *
 * @copyright 2008 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class moodle_areview_exception extends moodle_exception {
    public function __construct($areviewobj, $errorcode, $a = null, $link = '', $debuginfo = null) {
        if (!$link) {
            $link = $areviewobj->view_url();
        }
        parent::__construct($errorcode, 'areview', $link, $a, $debuginfo);
    }
}


/**
 * A class encapsulating a areview and the questions it contains, and making the
 * information available to scripts like view.php.
 *
 * Initially, it only loads a minimal amout of information about each question - loading
 * extra information only when necessary or when asked. The class tracks which questions
 * are loaded.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class areview {
    // Fields initialised in the constructor.
    protected $course;
    protected $cm;
    protected $areview;
    protected $context;
    protected $questionids;
    protected $fromQuiz;
    

    // Fields set later if that data is needed.
    protected $questions = null;
    protected $accessmanager = null;
    protected $ispreviewuser = null;

    // Constructor =============================================================
    /**
     * Constructor, assuming we already have the necessary data loaded.
     *
     * @param object $areview the row from the areview table.
     * @param object $cm the course_module object for this areview.
     * @param object $course the row from the course table for the course we belong to.
     * @param bool $getcontext intended for testing - stops the constructor getting the context.
     */
    public function __construct($areview, $cm, $course, $getcontext = true) {
    	
    	/*print("Calling Expensive areview object<br />\n");
    	
//    	print_object(debug_backtrace());
    	$e = new Exception;
    	var_dump($e->getTraceAsString());
    	*/
    	global $DB,$USER;
        $this->areview = $areview;
        $this->cm = $cm;
        $this->areview->cmid = $this->cm->id;
        $this->course = $course;
        if ($getcontext && !empty($cm->id)) {
            $this->context = context_module::instance($cm->id);
        }
        
        //grab existing scores
       	$scores = areview_get_cummulative_scores($areview->id,$USER->id);
        
       	//MKB need to grab all questions...
       	//FIXME check for visiblity 
       	//FIXME - this is called a lot, need to cache responses, limit calls if possible.
        $sql = <<<ABC
        SELECT qi.question as id,q.id as quiz_id

		FROM 	mdl_quiz q
				JOIN mdl_quiz_question_instances qi ON q.id = qi.quiz

		WHERE	q.course=:course
ABC;
		
		
        
        
        
        
        
        
        $questions = $DB->get_records_sql($sql, array('course'=>$course->id) );
        
     
        
        
        $this->questionids=array();
        //create lookup tables...
        $toQuiz = array();       	//quiz=>array(questions)
        $this->fromQuiz = array(); //quesiton=>quiz
        foreach($questions as $question)
        {
        	$question_id = $question->id;
        	$quiz_id = $question->quiz_id;
        	$this->fromQuiz[$question_id] = $quiz_id; 

 			if(!array_key_exists($quiz_id, $toQuiz))
			{
				$row = array();
				$toQuiz[$quiz_id] = $row;
			} 
			$row = $toQuiz[$quiz_id];
			$row[] = $question_id;
			$toQuiz[$quiz_id] = $row; 
        }
        $this->questionids=array();
      
        $now = time();
        
        
        //add questions as called for
        foreach($scores as $score)
        {
        	if(array_key_exists($score->quiz_id,$toQuiz))
        	{
        		$qs = $toQuiz[$score->quiz_id];
        		unset($toQuiz[$score->quiz_id]); //remove from array
        		
        		//FIXME - check for dates
        		if($score->last_score >1 and         		
        		$score->last_date + $areview->success_wait_time > $now)
        		{   //too soon to reattempt!
        			continue;
        		}
        		
        		
        		if(isQuizAvailible($score->quiz_id,$course->id,$USER->id)) 
        		{
	        		
	        		$total = ((float) count($qs))/$score->last_score;
					if($total <1.0)
					{
						if(lcg_value() < $total) 	{ $total = 1; }
						else					 	{ $total = 0; }					
					}
					$total = (int)$total;        		
					shuffle($qs);
					//add first total to questions
					$this->questionids=array_merge($this->questionids,
	        								array_slice($qs,0,$total));
        		}
        	}        		
        }

		//any left in the toQuiz can be added as normal
        foreach($toQuiz as $q=>$qs)
        {
        	if(isQuizAvailible($q,$course->id,$USER->id))
        	{
	        	$this->questionids=array_merge($this->questionids,$qs);
        	}
        }
        shuffle($this->questionids);
        
    }
    
   
   

    public function getLayout()
    {
    	$layout = "";
    	foreach($this->questionids as $qids)
    	{
    		$layout.="$qids,";
    	}
    	return $layout."0";
    }
    
    public function getnumQuestions()
    {
    	return count($this->questionids);
    }
    
    public function questionFromQuiz($quest)
    {
    	return $this->fromQuiz[$quest];
    }
    
    /**
     * Static function to create a new areview object for a specific user.
     *
     * @param int $areviewid the the areview id.
     * @param int $userid the the userid.
     * @return areview the new areview object
     */
    public static function create($areviewid, $userid) {
        global $DB;

        $areview = areview_access_manager::load_areview_and_settings($areviewid);
        $course = $DB->get_record('course', array('id' => $areview->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('areview', $areview->id, $course->id, false, MUST_EXIST);

        // Update areview with override information.
        $areview = areview_update_effective_access($areview, $userid);

        return new areview($areview, $cm, $course);
    }

    /**
     * Create a {@link areview_attempt} for an attempt at this areview.
     * @param object $attemptdata row from the areview_attempts table.
     * @return areview_attempt the new areview_attempt object.
     */
    public function create_attempt_object($attemptdata) {
        return new areview_attempt($attemptdata, $this->areview, $this->cm, $this->course);
    }

    // Functions for loading more data =========================================

    /**
     * Load just basic information about all the questions in this areview.
     */
    public function preload_questions() {
        if (empty($this->questionids)) {
            throw new moodle_areview_exception($this, 'noquestions', $this->edit_url());
        }
        $from ='{quiz_question_instances} qqi ON q.id = qqi.question';
        $from .= ' JOIN {quiz} quiz ON qqi.quiz = quiz.id and quiz.course=:courseid ';
        
        //linkes instance ids to original quiz_question_instances
        $this->questions = question_preload_questions($this->questionids,
        		'qqi.grade AS maxmark, qqi.id AS instance', //selects
				$from,
        		array('courseid' => $this->areview->course));
        
        
      /*  $this->questions = question_preload_questions($this->questionids,
        		'1 AS maxmark, 1 AS instance',
        		'',
        		array('areviewid' => $this->areview->id));
        		*/
        /*
         * there will not be a question instance for the questions in the quiz
        $this->questions = question_preload_questions($this->questionids,
        		'qqi.grade AS maxmark, qqi.id AS instance', //selects
        		'{areview_question_instances} qqi ON qqi.areview = :areviewid AND q.id = qqi.question', //Froms
        		array('areviewid' => $this->areview->id)); //Vars
        */
        
    }

    /**
     * Fully load some or all of the questions for this areview. You must call
     * {@link preload_questions()} first.
     *
     * @param array $questionids question ids of the questions to load. null for all.
     */
    public function load_questions($questionids = null) {
        if (is_null($questionids)) {
            $questionids = $this->questionids;
        }
        $questionstoprocess = array();
        foreach ($questionids as $id) {
            if (array_key_exists($id, $this->questions)) {
                $questionstoprocess[$id] = $this->questions[$id];
            }
        }
        get_question_options($questionstoprocess);
    }

    // Simple getters ==========================================================
    /** @return int the course id. */
    public function get_courseid() {
        return $this->course->id;
    }

    /** @return object the row of the course table. */
    public function get_course() {
        return $this->course;
    }

    /** @return int the areview id. */
    public function get_areviewid() {
        return $this->areview->id;
    }

    /** @return object the row of the areview table. */
    public function get_areview() {
        return $this->areview;
    }

    /** @return string the name of this areview. */
    public function get_areview_name() {
        return $this->areview->name;
    }

    /** @return int the areview navigation method. */
    public function get_navigation_method() {
        return $this->areview->navmethod;
    }

    /** @return int the number of attempts allowed at this areview (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->areview->attempts;
    }

    /** @return int the course_module id. */
    public function get_cmid() {
        return $this->cm->id;
    }

    /** @return object the course_module object. */
    public function get_cm() {
        return $this->cm;
    }

    /** @return object the module context for this areview. */
    public function get_context() {
        return $this->context;
    }

    /**
     * @return bool wether the current user is someone who previews the areview,
     * rather than attempting it.
     */
    public function is_preview_user() {
        if (is_null($this->ispreviewuser)) {
            $this->ispreviewuser = has_capability('mod/areview:preview', $this->context);
        }
        return $this->ispreviewuser;
    }

    /**
     * @return whether any questions have been added to this areview.
     */
    public function has_questions() {
        return !empty($this->questionids);
    }

    /**
     * @param int $id the question id.
     * @return object the question object with that id.
     */
    public function get_question($id) {
        return $this->questions[$id];
    }

    /**
     * @param array $questionids question ids of the questions to load. null for all.
     */
    public function get_questions($questionids = null) {
        if (is_null($questionids)) {
            $questionids = $this->questionids;
        }
        $questions = array();
        
        //echo "ids";
        //print_object($questionids);
        //echo "questions";
        //print_object($this->questions);
        
        
        foreach ($questionids as $id) {
        	
            if (!array_key_exists($id, $this->questions)) {
            
                throw new moodle_exception('cannotstartmissingquestion', 'areview', $this->view_url());
            }
            $questions[$id] = $this->questions[$id];
            $this->ensure_question_loaded($id);
        }
        return $questions;
    }

    /**
     * @param int $timenow the current time as a unix timestamp.
     * @return areview_access_manager and instance of the areview_access_manager class
     *      for this areview at this time.
     */
    public function get_access_manager($timenow) {
        if (is_null($this->accessmanager)) {
            $this->accessmanager = new areview_access_manager($this, $timenow,
                    has_capability('mod/areview:ignoretimelimits', $this->context, null, false));
        }
        return $this->accessmanager;
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the areview context.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return has_capability($capability, $this->context, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability funciton that automatically passes in the areview context.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        return require_capability($capability, $this->context, $userid, $doanything);
    }

    // URLs related to this attempt ============================================
    /**
     * @return string the URL of this areview's view page.
     */
    public function view_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/areview/view.php?id=' . $this->cm->id;
    }

    /**
     * @return string the URL of this areview's edit page.
     */
    public function edit_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/areview/edit.php?cmid=' . $this->cm->id;
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @param int $page optional page number to go to in the attempt.
     * @return string the URL of that attempt.
     */
    public function attempt_url($attemptid, $page = 0) {
        global $CFG;
        $url = $CFG->wwwroot . '/mod/areview/attempt.php?attempt=' . $attemptid;
        if ($page) {
            $url .= '&page=' . $page;
        }
        return $url;
    }

    /**
     * @return string the URL of this areview's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($page = 0) {
        $params = array('cmid' => $this->cm->id, 'sesskey' => sesskey());
        if ($page) {
            $params['page'] = $page;
        }
        return new moodle_url('/mod/areview/startattempt.php', $params);
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @return string the URL of the review of that attempt.
     */
    public function review_url($attemptid) {
        return new moodle_url('/mod/areview/review.php', array('attempt' => $attemptid));
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @return string the URL of the review of that attempt.
     */
    public function summary_url($attemptid) {
        return new moodle_url('/mod/areview/summary.php', array('attempt' => $attemptid));
    }

    // Bits of content =========================================================

    /**
     * @param bool $unfinished whether there is currently an unfinished attempt active.
     * @return string if the areview policies merit it, return a warning string to
     *      be displayed in a javascript alert on the start attempt button.
     */
    public function confirm_start_attempt_message($unfinished) {
        if ($unfinished) {
            return '';
        }

        if ($this->areview->timelimit && $this->areview->attempts) {
            return get_string('confirmstartattempttimelimit', 'areview', $this->areview->attempts);
        } else if ($this->areview->timelimit) {
            return get_string('confirmstarttimelimit', 'areview');
        } else if ($this->areview->attempts) {
            return get_string('confirmstartattemptlimit', 'areview', $this->areview->attempts);
        }

        return '';
    }

    /**
     * If $reviewoptions->attempt is false, meaning that students can't review this
     * attempt at the moment, return an appropriate string explaining why.
     *
     * @param int $when One of the mod_areview_display_options::DURING,
     *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
     * @param bool $short if true, return a shorter string.
     * @return string an appropraite message.
     */
    public function cannot_review_message($when, $short = false) {

        if ($short) {
            $langstrsuffix = 'short';
            $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        } else {
            $langstrsuffix = '';
            $dateformat = '';
        }

        if ($when == mod_areview_display_options::DURING ||
                $when == mod_areview_display_options::IMMEDIATELY_AFTER) {
            return '';
        } else if ($when == mod_areview_display_options::LATER_WHILE_OPEN && $this->areview->timeclose &&
                $this->areview->reviewattempt & mod_areview_display_options::AFTER_CLOSE) {
            return get_string('noreviewuntil' . $langstrsuffix, 'areview',
                    userdate($this->areview->timeclose, $dateformat));
        } else {
            return get_string('noreview' . $langstrsuffix, 'areview');
        }
    }

    /**
     * @param string $title the name of this particular areview page.
     * @return array the data that needs to be sent to print_header_simple as the $navigation
     * parameter.
     */
    public function navigation($title) {
        global $PAGE;
        $PAGE->navbar->add($title);
        return '';
    }

    // Private methods =========================================================
    /**
     * Check that the definition of a particular question is loaded, and if not throw an exception.
     * @param $id a questionid.
     */
    protected function ensure_question_loaded($id) {
        if (isset($this->questions[$id]->_partiallyloaded)) {
            throw new moodle_areview_exception($this, 'questionnotloaded', $id);
        }
    }
}


/**
 * This class extends the areview class to hold data about the state of a particular attempt,
 * in addition to the data about the areview.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class areview_attempt {

    /** @var string to identify the in progress state. */
    const IN_PROGRESS = 'inprogress';
    /** @var string to identify the overdue state. */
    const OVERDUE     = 'overdue';
    /** @var string to identify the finished state. */
    const FINISHED    = 'finished';
    /** @var string to identify the abandoned state. */
    const ABANDONED   = 'abandoned';

    // Basic data.
    protected $areviewobj;
    protected $attempt;

    /** @var question_usage_by_activity the question usage for this areview attempt. */
    protected $quba;

    /** @var array page no => array of slot numbers on the page in order. */
    protected $pagelayout;

    /** @var array slot => displayed question number for this slot. (E.g. 1, 2, 3 or 'i'.) */
    protected $questionnumbers;

    /** @var array slot => page number for this slot. */
    protected $questionpages;

    /** @var mod_areview_display_options cache for the appropriate review options. */
    protected $reviewoptions = null;

    // Constructor =============================================================
    /**
     * Constructor assuming we already have the necessary data loaded.
     *
     * @param object $attempt the row of the areview_attempts table.
     * @param object $areview the areview object for this attempt and user.
     * @param object $cm the course_module object for this areview.
     * @param object $course the row from the course table for the course we belong to.
     * @param bool $loadquestions (optional) if true, the default, load all the details
     *      of the state of each question. Else just set up the basic details of the attempt.
     */
    public function __construct($attempt, $areview, $cm, $course, $loadquestions = true) {
        $this->attempt = $attempt;
        $this->areviewobj = new areview($areview, $cm, $course);

        if (!$loadquestions) {
            return;
        }

        $this->quba = question_engine::load_questions_usage_by_activity($this->attempt->uniqueid);
        $this->determine_layout();
        $this->number_questions();
    }

    /**
     * Used by {create()} and {create_from_usage_id()}.
     * @param array $conditions passed to $DB->get_record('areview_attempts', $conditions).
     */
    protected static function create_helper($conditions) {
        global $DB;

        $attempt = $DB->get_record('areview_attempts', $conditions, '*', MUST_EXIST);
        $areview = areview_access_manager::load_areview_and_settings($attempt->areview);
        $course = $DB->get_record('course', array('id' => $areview->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('areview', $areview->id, $course->id, false, MUST_EXIST);

        // Update areview with override information.
        $areview = areview_update_effective_access($areview, $attempt->userid);

        return new areview_attempt($attempt, $areview, $cm, $course);
    }

    /**
     * Static function to create a new areview_attempt object given an attemptid.
     *
     * @param int $attemptid the attempt id.
     * @return areview_attempt the new areview_attempt object
     */
    public static function create($attemptid) {
        return self::create_helper(array('id' => $attemptid));
    }

    /**
     * Static function to create a new areview_attempt object given a usage id.
     *
     * @param int $usageid the attempt usage id.
     * @return areview_attempt the new areview_attempt object
     */
    public static function create_from_usage_id($usageid) {
        return self::create_helper(array('uniqueid' => $usageid));
    }

    /**
     * @param string $state one of the state constants like IN_PROGRESS.
     * @return string the human-readable state name.
     */
    public static function state_name($state) {
        return areview_attempt_state_name($state);
    }

    private function determine_layout() {
        $this->pagelayout = array();

        // Break up the layout string into pages.
        $pagelayouts = explode(',0', areview_clean_layout($this->attempt->layout, true));

        // Strip off any empty last page (normally there is one).
        if (end($pagelayouts) == '') {
            array_pop($pagelayouts);
        }

        // File the ids into the arrays.
        $this->pagelayout = array();
        foreach ($pagelayouts as $page => $pagelayout) {
            $pagelayout = trim($pagelayout, ',');
            if ($pagelayout == '') {
                continue;
            }
            $this->pagelayout[$page] = explode(',', $pagelayout);
        }
    }

    // Number the questions.
    private function number_questions() {
        $number = 1;
        foreach ($this->pagelayout as $page => $slots) {
            foreach ($slots as $slot) {
                $question = $this->quba->get_question($slot);
                if ($question->length > 0) {
                    $this->questionnumbers[$slot] = $number;
                    $number += $question->length;
                } else {
                    $this->questionnumbers[$slot] = get_string('infoshort', 'areview');
                }
                $this->questionpages[$slot] = $page;
            }
        }
    }
    
    public function get_num_questions()
    {
    	return $this->attempt->num_questions;
    	
    	
    }

    /**
     * If the given page number is out of range (before the first page, or after
     * the last page, chnage it to be within range).
     * @param int $page the requested page number.
     * @return int a safe page number to use.
     */
    public function force_page_number_into_range($page) {
        return min(max($page, 0), count($this->pagelayout) - 1);
    }

    // Simple getters ==========================================================
    public function get_areview() {
        return $this->areviewobj->get_areview();
    }

    public function get_areviewobj() {
        return $this->areviewobj;
    }

    /** @return int the course id. */
    public function get_courseid() {
        return $this->areviewobj->get_courseid();
    }

    /** @return int the course id. */
    public function get_course() {
        return $this->areviewobj->get_course();
    }

    /** @return int the areview id. */
    public function get_areviewid() {
        return $this->areviewobj->get_areviewid();
    }

    /** @return string the name of this areview. */
    public function get_areview_name() {
        return $this->areviewobj->get_areview_name();
    }

    /** @return int the areview navigation method. */
    public function get_navigation_method() {
        return $this->areviewobj->get_navigation_method();
    }

    /** @return object the course_module object. */
    public function get_cm() {
        return $this->areviewobj->get_cm();
    }

    /** @return object the course_module object. */
    public function get_cmid() {
        return $this->areviewobj->get_cmid();
    }

    /**
     * @return bool wether the current user is someone who previews the areview,
     * rather than attempting it.
     */
    public function is_preview_user() {
        return $this->areviewobj->is_preview_user();
    }

    /** @return int the number of attempts allowed at this areview (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->areviewobj->get_num_attempts_allowed();
    }

    /** @return int number fo pages in this areview. */
    public function get_num_pages() {
        return count($this->pagelayout);
    }

    /**
     * @param int $timenow the current time as a unix timestamp.
     * @return areview_access_manager and instance of the areview_access_manager class
     *      for this areview at this time.
     */
    public function get_access_manager($timenow) {
        return $this->areviewobj->get_access_manager($timenow);
    }

    /** @return int the attempt id. */
    public function get_attemptid() {
        return $this->attempt->id;
    }

    /** @return int the attempt unique id. */
    public function get_uniqueid() {
        return $this->attempt->uniqueid;
    }

    /** @return object the row from the areview_attempts table. */
    public function get_attempt() {
        return $this->attempt;
    }

    /** @return int the number of this attemp (is it this user's first, second, ... attempt). */
    public function get_attempt_number() {
        return $this->attempt->attempt;
    }

    /** @return string one of the areview_attempt::IN_PROGRESS, FINISHED, OVERDUE or ABANDONED constants. */
    public function get_state() {
        return $this->attempt->state;
    }

    /** @return int the id of the user this attempt belongs to. */
    public function get_userid() {
        return $this->attempt->userid;
    }

    /** @return int the current page of the attempt. */
    public function get_currentpage() {
        return $this->attempt->currentpage;
    }

    public function get_sum_marks() {
        return $this->attempt->sumgrades;
    }

    /**
     * @return bool whether this attempt has been finished (true) or is still
     *     in progress (false). Be warned that this is not just state == self::FINISHED,
     *     it also includes self::ABANDONED.
     */
    public function is_finished() {
        return $this->attempt->state == self::FINISHED || $this->attempt->state == self::ABANDONED;
    }

    /** @return bool whether this attempt is a preview attempt. */
    public function is_preview() {
        return $this->attempt->preview;
    }

    /**
     * Is this a student dealing with their own attempt/teacher previewing,
     * or someone with 'mod/areview:viewreports' reviewing someone elses attempt.
     *
     * @return bool whether this situation should be treated as someone looking at their own
     * attempt. The distinction normally only matters when an attempt is being reviewed.
     */
    public function is_own_attempt() {
        global $USER;
        return $this->attempt->userid == $USER->id &&
                (!$this->is_preview_user() || $this->attempt->preview);
    }

    /**
     * @return bool whether this attempt is a preview belonging to the current user.
     */
    public function is_own_preview() {
        global $USER;
        return $this->attempt->userid == $USER->id &&
                $this->is_preview_user() && $this->attempt->preview;
    }

    /**
     * Is the current user allowed to review this attempt. This applies when
     * {@link is_own_attempt()} returns false.
     * @return bool whether the review should be allowed.
     */
    public function is_review_allowed() {
        if (!$this->has_capability('mod/areview:viewreports')) {
            return false;
        }

        $cm = $this->get_cm();
        if ($this->has_capability('moodle/site:accessallgroups') ||
                groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return true;
        }

        // Check the users have at least one group in common.
        $teachersgroups = groups_get_activity_allowed_groups($cm);
        $studentsgroups = groups_get_all_groups(
                $cm->course, $this->attempt->userid, $cm->groupingid);
        return $teachersgroups && $studentsgroups &&
                array_intersect(array_keys($teachersgroups), array_keys($studentsgroups));
    }

    /**
     * Get the overall feedback corresponding to a particular mark.
     * @param $grade a particular grade.
     */
    public function get_overall_feedback($grade) {
        return areview_feedback_for_grade($grade, $this->get_areview(),
                $this->areviewobj->get_context());
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the areview context.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return $this->areviewobj->has_capability($capability, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability funciton that automatically passes in the areview context.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        return $this->areviewobj->require_capability($capability, $userid, $doanything);
    }

    /**
     * Check the appropriate capability to see whether this user may review their own attempt.
     * If not, prints an error.
     */
    public function check_review_capability() {
        if (!$this->has_capability('mod/areview:viewreports')) {
            if ($this->get_attempt_state() == mod_areview_display_options::IMMEDIATELY_AFTER) {
                $this->require_capability('mod/areview:attempt');
            } else {
                $this->require_capability('mod/areview:reviewmyattempts');
            }
        }
    }

    /**
     * Checks whether a user may navigate to a particular slot
     */
    public function can_navigate_to($slot) {
        switch ($this->get_navigation_method()) {
            case areview_NAVMETHOD_FREE:
                return true;
                break;
            case areview_NAVMETHOD_SEQ:
                return false;
                break;
        }
        return true;
    }

    /**
     * @return int one of the mod_areview_display_options::DURING,
     *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
     */
    public function get_attempt_state() {
        return areview_attempt_state($this->get_areview(), $this->attempt);
    }

    /**
     * Wrapper that the correct mod_areview_display_options for this areview at the
     * moment.
     *
     * @return question_display_options the render options for this user on this attempt.
     */
    public function get_display_options($reviewing) {
        if ($reviewing) {
            if (is_null($this->reviewoptions)) {
                $this->reviewoptions = areview_get_review_options($this->get_areview(),
                        $this->attempt, $this->areviewobj->get_context());
            }
            return $this->reviewoptions;

        } else {
            $options = mod_areview_display_options::make_from_areview($this->get_areview(),
                    mod_areview_display_options::DURING);
            $options->flags = areview_get_flag_option($this->attempt, $this->areviewobj->get_context());
            return $options;
        }
    }

    /**
     * Wrapper that the correct mod_areview_display_options for this areview at the
     * moment.
     *
     * @param bool $reviewing true for review page, else attempt page.
     * @param int $slot which question is being displayed.
     * @param moodle_url $thispageurl to return to after the editing form is
     *      submitted or cancelled. If null, no edit link will be generated.
     *
     * @return question_display_options the render options for this user on this
     *      attempt, with extra info to generate an edit link, if applicable.
     */
    public function get_display_options_with_edit_link($reviewing, $slot, $thispageurl) {
        $options = clone($this->get_display_options($reviewing));

        if (!$thispageurl) {
            return $options;
        }

        if (!($reviewing || $this->is_preview())) {
            return $options;
        }

        $question = $this->quba->get_question($slot);
        if (!question_has_capability_on($question, 'edit', $question->category)) {
            return $options;
        }

        $options->editquestionparams['cmid'] = $this->get_cmid();
        $options->editquestionparams['returnurl'] = $thispageurl;

        return $options;
    }

    /**
     * @param int $page page number
     * @return bool true if this is the last page of the areview.
     */
    public function is_last_page($page) {
        return $page == count($this->pagelayout) - 1;
    }

    /**
     * Return the list of question ids for either a given page of the areview, or for the
     * whole areview.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the reqested list of question ids.
     */
    public function get_slots($page = 'all') {
        if ($page === 'all') {
            $numbers = array();
            foreach ($this->pagelayout as $numbersonpage) {
                $numbers = array_merge($numbers, $numbersonpage);
            }
            return $numbers;
        } else {
            return $this->pagelayout[$page];
        }
    }

    /**
     * Get the question_attempt object for a particular question in this attempt.
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt
     */
    public function get_question_attempt($slot) {
        return $this->quba->get_question_attempt($slot);
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_real_question($slot) {
        return $this->quba->get_question($slot)->length != 0;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_question_flagged($slot) {
        return $this->quba->get_question_attempt($slot)->is_flagged();
    }

    /**
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the displayed question number for the question in this slot.
     *      For example '1', '2', '3' or 'i'.
     */
    public function get_question_number($slot) {
        return $this->questionnumbers[$slot];
    }

    /**
     * @param int $slot the number used to identify this question within this attempt.
     * @return int the page of the areview this question appears on.
     */
    public function get_question_page($slot) {
        return $this->questionpages[$slot];
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the areview.
     */
    public function get_question_name($slot) {
        return $this->quba->get_question($slot)->name;
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguised.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the areview.
     */
    public function get_question_status($slot, $showcorrectness) {
        return $this->quba->get_question_state_string($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguised.
     * @return string class name for this state.
     */
    public function get_question_state_class($slot, $showcorrectness) {
        return $this->quba->get_question_state_class($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question.
     * You must previously have called  to load the state
     * data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified by the areview.
     */
    public function get_question_mark($slot) {
        return areview_format_question_grade($this->get_areview(), $this->quba->get_question_mark($slot));
    }

    /**
     * Get the time of the most recent action performed on a question.
     * @param int $slot the number used to identify this question within this usage.
     * @return int timestamp.
     */
    public function get_question_action_time($slot) {
        return $this->quba->get_question_action_time($slot);
    }

    /**
     * Get the time remaining for an in-progress attempt, if the time is short
     * enought that it would be worth showing a timer.
     * @param int $timenow the time to consider as 'now'.
     * @return int|false the number of seconds remaining for this attempt.
     *      False if there is no limit.
     */
    public function get_time_left_display($timenow) {
        if ($this->attempt->state != self::IN_PROGRESS) {
            return false;
        }
        return $this->get_access_manager($timenow)->get_time_left_display($this->attempt, $timenow);
    }


    /**
     * @return int the time when this attempt was submitted. 0 if it has not been
     * submitted yet.
     */
    public function get_submitted_date() {
        return $this->attempt->timefinish;
    }

    /**
     * If the attempt is in an applicable state, work out the time by which the
     * student should next do something.
     * @return int timestamp by which the student needs to do something.
     */
    public function get_due_date() {
        $deadlines = array();
        if ($this->areviewobj->get_areview()->timelimit) {
            $deadlines[] = $this->attempt->timestart + $this->areviewobj->get_areview()->timelimit;
        }
        if ($this->areviewobj->get_areview()->timeclose) {
            $deadlines[] = $this->areviewobj->get_areview()->timeclose;
        }
        if ($deadlines) {
            $duedate = min($deadlines);
        } else {
            return false;
        }

        switch ($this->attempt->state) {
            case self::IN_PROGRESS:
                return $duedate;

            case self::OVERDUE:
                return $duedate + $this->areviewobj->get_areview()->graceperiod;

            default:
                throw new coding_exception('Unexpected state: ' . $this->attempt->state);
        }
    }

    // URLs related to this attempt ============================================
    /**
     * @return string areview view url.
     */
    public function view_url() {
        return $this->areviewobj->view_url();
    }

    /**
     * @return string the URL of this areview's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($slot = null, $page = -1) {
        if ($page == -1 && !is_null($slot)) {
            $page = $this->get_question_page($slot);
        } else {
            $page = 0;
        }
        return $this->areviewobj->start_attempt_url($page);
    }

    /**
     * @param int $slot if speified, the slot number of a specific question to link to.
     * @param int $page if specified, a particular page to link to. If not givem deduced
     *      from $slot, or goes to the first page.
     * @param int $questionid a question id. If set, will add a fragment to the URL
     * to jump to a particuar question on the page.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to continue this attempt.
     */
    public function attempt_url($slot = null, $page = -1, $thispage = -1) {
        return $this->page_and_question_url('attempt', $slot, $page, false, $thispage);
    }

    /**
     * @return string the URL of this areview's summary page.
     */
    public function summary_url() {
        return new moodle_url('/mod/areview/summary.php', array('attempt' => $this->attempt->id));
    }

    /**
     * @return string the URL of this areview's summary page.
     */
    public function processattempt_url() {
        return new moodle_url('/mod/areview/processattempt.php');
    }

    /**
     * @param int $slot indicates which question to link to.
     * @param int $page if specified, the URL of this particular page of the attempt, otherwise
     * the URL will go to the first page.  If -1, deduce $page from $slot.
     * @param bool $showall if true, the URL will be to review the entire attempt on one page,
     * and $page will be ignored.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to review this attempt.
     */
    public function review_url($slot = null, $page = -1, $showall = false, $thispage = -1) {
        return $this->page_and_question_url('review', $slot, $page, $showall, $thispage);
    }

    // Bits of content =========================================================

    /**
     * If $reviewoptions->attempt is false, meaning that students can't review this
     * attempt at the moment, return an appropriate string explaining why.
     *
     * @param bool $short if true, return a shorter string.
     * @return string an appropraite message.
     */
    public function cannot_review_message($short = false) {
        return $this->areviewobj->cannot_review_message(
                $this->get_attempt_state(), $short);
    }

    /**
     * Initialise the JS etc. required all the questions on a page.
     * @param mixed $page a page number, or 'all'.
     */
    public function get_html_head_contributions($page = 'all', $showall = false) {
        if ($showall) {
            $page = 'all';
        }
        $result = '';
        foreach ($this->get_slots($page) as $slot) {
            $result .= $this->quba->render_question_head_html($slot);
        }
        $result .= question_engine::initialise_js();
        return $result;
    }

    /**
     * Initialise the JS etc. required by one question.
     * @param int $questionid the question id.
     */
    public function get_question_html_head_contributions($slot) {
        return $this->quba->render_question_head_html($slot) .
                question_engine::initialise_js();
    }

    /**
     * Print the HTML for the start new preview button, if the current user
     * is allowed to see one.
     */
    public function restart_preview_button() {
        global $OUTPUT;
        if ($this->is_preview() && $this->is_preview_user()) {
            return $OUTPUT->single_button(new moodle_url(
                    $this->start_attempt_url(), array('forcenew' => true)),
                    get_string('startnewpreview', 'areview'));
        } else {
            return '';
        }
    }

    /**
     * Generate the HTML that displayes the question in its current state, with
     * the appropriate display options.
     *
     * @param int $id the id of a question in this areview attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question($slot, $reviewing, $thispageurl = null) {
        return $this->quba->render_question($slot,
                $this->get_display_options_with_edit_link($reviewing, $slot, $thispageurl),
                $this->get_question_number($slot));
    }

    /**
     * Like {@link render_question()} but displays the question at the past step
     * indicated by $seq, rather than showing the latest step.
     *
     * @param int $id the id of a question in this areview attempt.
     * @param int $seq the seq number of the past state to display.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param string $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question_at_step($slot, $seq, $reviewing, $thispageurl = '') {
        return $this->quba->render_question_at_step($slot, $seq,
                $this->get_display_options_with_edit_link($reviewing, $slot, $thispageurl),
                $this->get_question_number($slot));
    }

    /**
     * Wrapper round print_question from lib/questionlib.php.
     *
     * @param int $id the id of a question in this areview attempt.
     */
    public function render_question_for_commenting($slot) {
        $options = $this->get_display_options(true);
        $options->hide_all_feedback();
        $options->manualcomment = question_display_options::EDITABLE;
        return $this->quba->render_question($slot, $options,
                $this->get_question_number($slot));
    }

    /**
     * Check wheter access should be allowed to a particular file.
     *
     * @param int $id the id of a question in this areview attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param string $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function check_file_access($slot, $reviewing, $contextid, $component,
            $filearea, $args, $forcedownload) {
        return $this->quba->check_file_access($slot, $this->get_display_options($reviewing),
                $component, $filearea, $args, $forcedownload);
    }

    /**
     * Get the navigation panel object for this attempt.
     *
     * @param $panelclass The type of panel, areview_attempt_nav_panel or areview_review_nav_panel
     * @param $page the current page number.
     * @param $showall whether we are showing the whole areview on one page. (Used by review.php)
     * @return areview_nav_panel_base the requested object.
     */
    public function get_navigation_panel(mod_areview_renderer $output,
             $panelclass, $page, $showall = false) {
        $panel = new $panelclass($this, $this->get_display_options(true), $page, $showall);

        $bc = new block_contents();
        $bc->attributes['id'] = 'mod_areview_navblock';
        $bc->title = get_string('areviewnavigation', 'areview');
        $bc->content = $output->navigation_panel($panel);
        return $bc;
    }

    /**
     * Given a URL containing attempt={this attempt id}, return an array of variant URLs
     * @param moodle_url $url a URL.
     * @return string HTML fragment. Comma-separated list of links to the other
     * attempts with the attempt number as the link text. The curent attempt is
     * included but is not a link.
     */
    public function links_to_other_attempts(moodle_url $url) {
        $attempts = areview_get_user_attempts($this->get_areview()->id, $this->attempt->userid, 'all');
        if (count($attempts) <= 1) {
            return false;
        }

        $links = new mod_areview_links_to_other_attempts();
        foreach ($attempts as $at) {
            if ($at->id == $this->attempt->id) {
                $links->links[$at->attempt] = null;
            } else {
                $links->links[$at->attempt] = new moodle_url($url, array('attempt' => $at->id));
            }
        }
        return $links;
    }

    // Methods for processing ==================================================

    /**
     * Check this attempt, to see if there are any state transitions that should
     * happen automatically.  This function will update the attempt checkstatetime.
     * @param int $timestamp the timestamp that should be stored as the modifed
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function handle_if_time_expired($timestamp, $studentisonline) {
        global $DB;

        $timeclose = $this->get_access_manager($timestamp)->get_end_time($this->attempt);

        if ($timeclose === false || $this->is_preview()) {
            $this->update_timecheckstate(null);
            return; // No time limit
        }
        if ($timestamp < $timeclose) {
            $this->update_timecheckstate($timeclose);
            return; // Time has not yet expired.
        }

        // If the attempt is already overdue, look to see if it should be abandoned ...
        if ($this->attempt->state == self::OVERDUE) {
            $timeoverdue = $timestamp - $timeclose;
            $graceperiod = $this->areviewobj->get_areview()->graceperiod;
            if ($timeoverdue >= $graceperiod) {
                $this->process_abandon($timestamp, $studentisonline);
            } else {
                // Overdue time has not yet expired
                $this->update_timecheckstate($timeclose + $graceperiod);
            }
            return; // ... and we are done.
        }

        if ($this->attempt->state != self::IN_PROGRESS) {
            $this->update_timecheckstate(null);
            return; // Attempt is already in a final state.
        }

        // Otherwise, we were in areview_attempt::IN_PROGRESS, and time has now expired.
        // Transition to the appropriate state.
        switch ($this->areviewobj->get_areview()->overduehandling) {
            case 'autosubmit':
                $this->process_finish($timestamp, false);
                return;

            case 'graceperiod':
                $this->process_going_overdue($timestamp, $studentisonline);
                return;

            case 'autoabandon':
                $this->process_abandon($timestamp, $studentisonline);
                return;
        }

        // This is an overdue attempt with no overdue handling defined, so just abandon.
        $this->process_abandon($timestamp, $studentisonline);
        return;
    }

    /**
     * Process all the actions that were submitted as part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modifed
     * time in the database for these actions. If null, will use the current time.
     */
    public function process_submitted_actions($timestamp, $becomingoverdue = false) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $this->quba->process_all_actions($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = $timestamp;
        if ($this->attempt->state == self::FINISHED) {
            $this->attempt->sumgrades = $this->quba->get_total_mark();
        }
        if ($becomingoverdue) {
            $this->process_going_overdue($timestamp, true);
        } else {
            $DB->update_record('areview_attempts', $this->attempt);
        }

        if (!$this->is_preview() && $this->attempt->state == self::FINISHED) {
            areview_save_best_grade($this->get_areview(), $this->get_userid());
        }

        $transaction->allow_commit();
    }

    /**
     * Process all the autosaved data that was part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modifed
     * time in the database for these actions. If null, will use the current time.
     */
    public function process_auto_save($timestamp) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $this->quba->process_all_autosaves($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);

        $transaction->allow_commit();
    }

    /**
     * Update the flagged state for all question_attempts in this usage, if their
     * flagged state was changed in the request.
     */
    public function save_question_flags() {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->quba->update_question_flags();
        question_engine::save_questions_usage_by_activity($this->quba);
        $transaction->allow_commit();
    }

    public function process_finish($timestamp, $processsubmitted) {
        global $DB;
        
        $transaction = $DB->start_delegated_transaction();

        if ($processsubmitted) {
            $this->quba->process_all_actions($timestamp);
        }
        $this->quba->finish_all_questions($timestamp);
        
        question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = $timestamp;
        $this->attempt->timefinish = $timestamp;
        $this->attempt->sumgrades = $this->quba->get_total_mark();
        $this->attempt->state = self::FINISHED;
        $this->attempt->timecheckstate = null;
        //print_object($this->attempt);
        
        $DB->update_record('areview_attempts', $this->attempt);

       
        if (!$this->is_preview()) {
        	
            areview_save_best_grade($this->get_areview(), $this->attempt->userid);

            // Trigger event.
            $this->fire_state_transition_event('areview_attempt_submitted', $timestamp);

            // Tell any access rules that care that the attempt is over.
            $this->get_access_manager($timestamp)->current_attempt_finished();
        }
        
        $transaction->allow_commit();
       
    }

    /**
     * Update this attempt timecheckstate if necessary.
     * @param int|null the timecheckstate
     */
    public function update_timecheckstate($time) {
        global $DB;
        if ($this->attempt->timecheckstate !== $time) {
            $this->attempt->timecheckstate = $time;
            $DB->set_field('areview_attempts', 'timecheckstate', $time, array('id'=>$this->attempt->id));
        }
    }

    /**
     * Mark this attempt as now overdue.
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_going_overdue($timestamp, $studentisonline) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::OVERDUE;
        // If we knew the attempt close time, we could compute when the graceperiod ends.
        // Instead we'll just fix it up through cron.
        $this->attempt->timecheckstate = $timestamp;
        $DB->update_record('areview_attempts', $this->attempt);

        $this->fire_state_transition_event('areview_attempt_overdue', $timestamp);

        $transaction->allow_commit();
    }

    /**
     * Mark this attempt as abandoned.
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_abandon($timestamp, $studentisonline) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::ABANDONED;
        $this->attempt->timecheckstate = null;
        $DB->update_record('areview_attempts', $this->attempt);

        $this->fire_state_transition_event('areview_attempt_abandoned', $timestamp);

        $transaction->allow_commit();
    }

    /**
     * Fire a state transition event.
     * @param string $event the type of event. Should be listed in db/events.php.
     * @param int $timestamp the timestamp to include in the event.
     */
    protected function fire_state_transition_event($event, $timestamp) {
        global $USER;

        // Trigger event.
        $eventdata = new stdClass();
        $eventdata->component   = 'mod_areview';
        $eventdata->attemptid   = $this->attempt->id;
        $eventdata->timestamp   = $timestamp;
        $eventdata->userid      = $this->attempt->userid;
        $eventdata->areviewid      = $this->get_areviewid();
        $eventdata->cmid        = $this->get_cmid();
        $eventdata->courseid    = $this->get_courseid();

        // I don't think if (CLI_SCRIPT) is really the right logic here. The
        // question is really 'is $USER currently set to a real user', but I cannot
        // see standard Moodle function to answer that question. For example,
        // cron fakes $USER.
        if (CLI_SCRIPT) {
            $eventdata->submitterid = null;
        } else {
            $eventdata->submitterid = $USER->id;
        }

        if ($event == 'areview_attempt_submitted') {
            // Backwards compatibility for this event type. $eventdata->timestamp is now preferred.
            $eventdata->timefinish = $timestamp;
        }

        events_trigger($event, $eventdata);
    }

    /**
     * Print the fields of the comment form for questions in this attempt.
     * @param $slot which question to output the fields for.
     * @param $prefix Prefix to add to all field names.
     */
    public function question_print_comment_fields($slot, $prefix) {
        // Work out a nice title.
        $student = get_record('user', 'id', $this->get_userid());
        $a = new object();
        $a->fullname = fullname($student, true);
        $a->attempt = $this->get_attempt_number();

        question_print_comment_fields($this->quba->get_question_attempt($slot),
                $prefix, $this->get_display_options(true)->markdp,
                get_string('gradingattempt', 'areview_grading', $a));
    }

    // Private methods =========================================================

    /**
     * Get a URL for a particular question on a particular page of the areview.
     * Used by {@link attempt_url()} and {@link review_url()}.
     *
     * @param string $script. Used in the URL like /mod/areview/$script.php
     * @param int $slot identifies the specific question on the page to jump to.
     *      0 to just use the $page parameter.
     * @param int $page -1 to look up the page number from the slot, otherwise
     *      the page number to go to.
     * @param bool $showall if true, return a URL with showall=1, and not page number
     * @param int $thispage the page we are currently on. Links to questions on this
     *      page will just be a fragment #q123. -1 to disable this.
     * @return The requested URL.
     */
    protected function page_and_question_url($script, $slot, $page, $showall, $thispage) {
        // Fix up $page.
        if ($page == -1) {
            if (!is_null($slot) && !$showall) {
                $page = $this->get_question_page($slot);
            } else {
                $page = 0;
            }
        }

        if ($showall) {
            $page = 0;
        }

        // Add a fragment to scroll down to the question.
        $fragment = '';
        if (!is_null($slot)) {
            if ($slot == reset($this->pagelayout[$page])) {
                // First question on page, go to top.
                $fragment = '#';
            } else {
                $fragment = '#q' . $slot;
            }
        }

        // Work out the correct start to the URL.
        if ($thispage == $page) {
            return new moodle_url($fragment);

        } else {
            $url = new moodle_url('/mod/areview/' . $script . '.php' . $fragment,
                    array('attempt' => $this->attempt->id));
            if ($showall) {
                $url->param('showall', 1);
            } else if ($page > 0) {
                $url->param('page', $page);
            }
            return $url;
        }
    }
}


/**
 * Represents a single link in the navigation panel.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.1
 */
class areview_nav_question_button implements renderable {
    public $id;
    public $number;
    public $stateclass;
    public $statestring;
    public $currentpage;
    public $flagged;
    public $url;
}


/**
 * Represents the navigation panel, and builds a {@link block_contents} to allow
 * it to be output.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
abstract class areview_nav_panel_base {
    /** @var areview_attempt */
    protected $attemptobj;
    /** @var question_display_options */
    protected $options;
    /** @var integer */
    protected $page;
    /** @var boolean */
    protected $showall;

    public function __construct(areview_attempt $attemptobj,
            question_display_options $options, $page, $showall) {
        $this->attemptobj = $attemptobj;
        $this->options = $options;
        $this->page = $page;
        $this->showall = $showall;
    }

    public function get_question_buttons() {
        $buttons = array();
        foreach ($this->attemptobj->get_slots() as $slot) {
            $qa = $this->attemptobj->get_question_attempt($slot);
            $showcorrectness = $this->options->correctness && $qa->has_marks();

            $button = new areview_nav_question_button();
            $button->id          = 'areviewnavbutton' . $slot;
            $button->number      = $this->attemptobj->get_question_number($slot);
            $button->stateclass  = $qa->get_state_class($showcorrectness);
            $button->navmethod   = $this->attemptobj->get_navigation_method();
            if (!$showcorrectness && $button->stateclass == 'notanswered') {
                $button->stateclass = 'complete';
            }
            $button->statestring = $this->get_state_string($qa, $showcorrectness);
            $button->currentpage = $this->attemptobj->get_question_page($slot) == $this->page;
            $button->flagged     = $qa->is_flagged();
            $button->url         = $this->get_question_url($slot);
            $buttons[] = $button;
        }

        return $buttons;
    }

    protected function get_state_string(question_attempt $qa, $showcorrectness) {
        if ($qa->get_question()->length > 0) {
            return $qa->get_state_string($showcorrectness);
        }

        // Special case handling for 'information' items.
        if ($qa->get_state() == question_state::$todo) {
            return get_string('notyetviewed', 'areview');
        } else {
            return get_string('viewed', 'areview');
        }
    }

    public function render_before_button_bits(mod_areview_renderer $output) {
        return '';
    }

    abstract public function render_end_bits(mod_areview_renderer $output);

    protected function render_restart_preview_link($output) {
        if (!$this->attemptobj->is_own_preview()) {
            return '';
        }
        return $output->restart_preview_button(new moodle_url(
                $this->attemptobj->start_attempt_url(), array('forcenew' => true)));
    }

    protected abstract function get_question_url($slot);

    public function user_picture() {
        global $DB;

        if (!$this->attemptobj->get_areview()->showuserpicture) {
            return null;
        }

        $user = $DB->get_record('user', array('id' => $this->attemptobj->get_userid()));
        $userpicture = new user_picture($user);
        $userpicture->courseid = $this->attemptobj->get_courseid();
        return $userpicture;
    }
}


/**
 * Specialisation of {@link areview_nav_panel_base} for the attempt areview page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class areview_attempt_nav_panel extends areview_nav_panel_base {
    public function get_question_url($slot) {
        if ($this->attemptobj->can_navigate_to($slot)) {
            return $this->attemptobj->attempt_url($slot, -1, $this->page);
        } else {
            return null;
        }
    }

    public function render_before_button_bits(mod_areview_renderer $output) {
        return html_writer::tag('div', get_string('navnojswarning', 'areview'),
                array('id' => 'areviewnojswarning'));
    }

    public function render_end_bits(mod_areview_renderer $output) {
        return html_writer::link($this->attemptobj->summary_url(),
                get_string('endtest', 'areview'), array('class' => 'endtestlink')) .
                $output->countdown_timer($this->attemptobj, time()) .
                $this->render_restart_preview_link($output);
    }
}


/**
 * Specialisation of {@link areview_nav_panel_base} for the review areview page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class areview_review_nav_panel extends areview_nav_panel_base {
    public function get_question_url($slot) {
        return $this->attemptobj->review_url($slot, -1, $this->showall, $this->page);
    }

    public function render_end_bits(mod_areview_renderer $output) {
        $html = '';
        if ($this->attemptobj->get_num_pages() > 1) {
            if ($this->showall) {
                $html .= html_writer::link($this->attemptobj->review_url(null, 0, false),
                        get_string('showeachpage', 'areview'));
            } else {
                $html .= html_writer::link($this->attemptobj->review_url(null, 0, true),
                        get_string('showall', 'areview'));
            }
        }
        $html .= $output->finish_review_link($this->attemptobj);
        $html .= $this->render_restart_preview_link($output);
        return $html;
    }
}
