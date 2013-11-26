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
 * Library of functions used by the cquiz module.
 *
 * This contains functions that are called from within the cquiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/lib.php');
require_once($CFG->dirroot . '/mod/cquiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/cquiz/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/cquiz/renderer.php');
require_once($CFG->dirroot . '/mod/cquiz/attemptlib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->libdir  . '/eventslib.php');
require_once($CFG->libdir . '/filelib.php');




/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the cquiz close date. (1 hour)
 */
define('CQUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the cquiz, then do not take them to the next page of the cquiz. Instead
 * close the cquiz immediately.
 */
define('CQUIZ_MIN_TIME_TO_CONTINUE', '2');



/*
 * returns true if the quiz is availible
 */
function isQuizAvailible($quiz_id,$course_id,$user_id)
{
	
	$cm = get_coursemodule_from_instance('quiz', $quiz_id, $course_id, false, MUST_EXIST);
	$ci = new condition_info($cm,CONDITION_MISSING_EVERYTHING);
	$bool = $ci->is_available($text,false,$user_id);
	
//	print("checking $quiz_id")
	
	return $bool;
	 
}



// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a cquiz
 *
 * Creates an attempt object to represent an attempt at the cquiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $cquizobj the cquiz object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $cquiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 *
 * @return object the newly created attempt object.
 */
function cquiz_create_attempt(cquiz $cquizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false) {
    global $USER;

    $cquiz = $cquizobj->get_cquiz();
    if ($cquiz->sumgrades < 0.000005 && $cquiz->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'cquiz',
                new moodle_url('/mod/cquiz/view.php', array('q' => $cquiz->id)),
                    array('grade' => cquiz_format_grade($cquiz, $cquiz->grade)));
    }

    if ($attemptnumber == 1 || !$cquiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->cquiz = $cquiz->id;
        $attempt->userid = $USER->id;
        $attempt->preview = 0;
        
        
        
//        $attempt->layout = cquiz_clean_layout($cquiz->questions, true);
		//$attempt->layout = cquiz_clean_layout($cquiz->getLayout(), true);
		
        //$cquizobj = cquiz::create($cquiz->id, $USER->id);
//		print_object($cquiz);
        $attempt->layout = cquiz_clean_layout($cquizobj->getLayout(), true);
        $attempt->num_questions = $cquizobj->getNumQuestions();
        if ($cquiz->shufflequestions) {
            $attempt->layout = cquiz_repaginate($attempt->layout, $cquiz->questionsperpage, true);
        }
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'cquiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->state = cquiz_attempt::IN_PROGRESS;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $cquizobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given cquiz. This function does not return preview attempts.
 *
 * @param int $cquizid the id of the cquiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function cquiz_get_user_attempt_unfinished($cquizid, $userid) {
    $attempts = cquiz_get_user_attempts($cquizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a cquiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the cquiz_attempts table).
 * @param object $cquiz the cquiz object.
 */
function cquiz_delete_attempt($attempt, $cquiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('cquiz_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->cquiz != $cquiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to cquiz $attempt->cquiz " .
                "but was passed cquiz $cquiz->id.");
        return;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('cquiz_attempts', array('id' => $attempt->id));

    // Search cquiz_attempts for other instances by this user.
    // If none, then delete record for this cquiz, this user from cquiz_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('cquiz_attempts', array('userid' => $userid, 'cquiz' => $cquiz->id))) {
        $DB->delete_records('cquiz_grades', array('userid' => $userid, 'cquiz' => $cquiz->id));
    } else {
        cquiz_save_best_grade($cquiz, $userid);
    }

    cquiz_update_grades($cquiz, $userid);
}

/**
 * Delete all the preview attempts at a cquiz, or possibly all the attempts belonging
 * to one user.
 * @param object $cquiz the cquiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function cquiz_delete_previews($cquiz, $userid = null) {
    global $DB;
    $conditions = array('cquiz' => $cquiz->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('cquiz_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        cquiz_delete_attempt($attempt, $cquiz);
    }
}

/**
 * @param int $cquizid The cquiz id.
 * @return bool whether this cquiz has any (non-preview) attempts.
 */
function cquiz_has_attempts($cquizid) {
    global $DB;
    return $DB->record_exists('cquiz_attempts', array('cquiz' => $cquizid, 'preview' => 0));
}

// Functions to do with cquiz layout and pages //////////////////////////////////

/**
 * Returns a comma separated list of question ids for the cquiz
 *
 * @param string $layout The string representing the cquiz layout. Each page is
 *      represented as a comma separated list of question ids and 0 indicating
 *      page breaks. So 5,2,0,3,0 means questions 5 and 2 on page 1 and question
 *      3 on page 2
 * @return string comma separated list of question ids, without page breaks.
 */
function cquiz_questions_in_cquiz($layout) {
    $questions = str_replace(',0', '', cquiz_clean_layout($layout, true));
    if ($questions === '0') {
        return '';
    } else {
        return $questions;
    }
}

/**
 * Returns the number of pages in a cquiz layout
 *
 * @param string $layout The string representing the cquiz layout. Always ends in ,0
 * @return int The number of pages in the cquiz.
 */
function cquiz_number_of_pages($layout) {
    return substr_count(',' . $layout, ',0');
}

/**
 * Returns the number of questions in the cquiz layout
 *
 * @param string $layout the string representing the cquiz layout.
 * @return int The number of questions in the cquiz.
 */
function cquiz_number_of_questions_in_cquiz($layout) {
    $layout = cquiz_questions_in_cquiz(cquiz_clean_layout($layout));
    $count = substr_count($layout, ',');
    if ($layout !== '') {
        $count++;
    }
    return $count;
}

/**
 * Re-paginates the cquiz layout
 *
 * @param string $layout  The string representing the cquiz layout. If there is
 *      if there is any doubt about the quality of the input data, call
 *      cquiz_clean_layout before you call this function.
 * @param int $perpage The number of questions per page
 * @param bool $shuffle Should the questions be reordered randomly?
 * @return string the new layout string
 */
function cquiz_repaginate($layout, $perpage, $shuffle = false) {
    $questions = cquiz_questions_in_cquiz($layout);
    if (!$questions) {
        return '0';
    }

    $questions = explode(',', cquiz_questions_in_cquiz($layout));
    if ($shuffle) {
        shuffle($questions);
    }

    $onthispage = 0;
    $layout = array();
    foreach ($questions as $question) {
        if ($perpage and $onthispage >= $perpage) {
            $layout[] = 0;
            $onthispage = 0;
        }
        $layout[] = $question;
        $onthispage += 1;
    }

    $layout[] = 0;
    return implode(',', $layout);
}

// Functions to do with cquiz grades ////////////////////////////////////////////

/**
 * Creates an array of maximum grades for a cquiz
 *
 * The grades are extracted from the cquiz_question_instances table.
 * @param object $cquiz The cquiz settings.
 * @return array of grades indexed by question id. These are the maximum
 *      possible grades that students can achieve for each of the questions.
 */
function cquiz_get_all_question_grades($cquiz) {
    global $CFG, $DB;

    $questionlist = cquiz_questions_in_cquiz($cquiz->questions);
    if (empty($questionlist)) {
        return array();
    }

    $params = array($cquiz->id);
    $wheresql = '';
    if (!is_null($questionlist)) {
        list($usql, $question_params) = $DB->get_in_or_equal(explode(',', $questionlist));
        $wheresql = " AND question $usql ";
        $params = array_merge($params, $question_params);
    }

    $instances = $DB->get_records_sql("SELECT question, grade, id
                                    FROM {cquiz_question_instances}
                                    WHERE cquiz = ? $wheresql", $params);

    $list = explode(",", $questionlist);
    $grades = array();

    foreach ($list as $qid) {
        if (isset($instances[$qid])) {
            $grades[$qid] = $instances[$qid]->grade;
        } else {
            $grades[$qid] = 1;
        }
    }
    return $grades;
}

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this cquiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $cquiz the cquiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @param float $total out of how many 
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function cquiz_rescale_grade($rawgrade, $cquiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($cquiz->sumgrades >= 0.000005) {
//        $grade = $rawgrade * $cquiz->grade / $cquiz->sumgrades;
    	$grade = $rawgrade * $cquiz->grade;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = cquiz_format_question_grade($cquiz, $grade);
    } else if ($format) {
        $grade = cquiz_format_grade($cquiz, $grade);
    }
    return $grade;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this cquiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this cquiz.
 * @param object $cquiz the cquiz settings.
 * @param object $context the cquiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function cquiz_feedback_for_grade($grade, $cquiz, $context) {
    global $DB;

    if (is_null($grade)) {
        return '';
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('cquiz_feedback',
            'cquizid = ? AND mingrade <= ? AND ? < maxgrade', array($cquiz->id, $grade, $grade));

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_cquiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $cquiz the cquiz database row.
 * @return bool Whether this cquiz has any non-blank feedback text.
 */
function cquiz_has_feedback($cquiz) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($cquiz->id, $cache)) {
        $cache[$cquiz->id] = cquiz_has_grades($cquiz) &&
                $DB->record_exists_select('cquiz_feedback', "cquizid = ? AND " .
                    $DB->sql_isnotempty('cquiz_feedback', 'feedbacktext', false, true),
                array($cquiz->id));
    }
    return $cache[$cquiz->id];
}

/**
 * Update the sumgrades field of the cquiz. This needs to be called whenever
 * the grading structure of the cquiz is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link cquiz_delete_previews()} before you call this function.
 *
 * @param object $cquiz a cquiz.
 */
function cquiz_update_sumgrades($cquiz) {
    global $DB;

    $sql = 'UPDATE {cquiz}
            SET sumgrades = COALESCE((
                SELECT SUM(grade)
                FROM {cquiz_question_instances}
                WHERE cquiz = {cquiz}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($cquiz->id));
    $cquiz->sumgrades = $DB->get_field('cquiz', 'sumgrades', array('id' => $cquiz->id));

    if ($cquiz->sumgrades < 0.000005 && cquiz_has_attempts($cquiz->id)) {
        // If the cquiz has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        cquiz_set_grade(0, $cquiz);
    }
}

/**
 * Update the sumgrades field of the attempts at a cquiz.
 *
 * @param object $cquiz a cquiz.
 */
function cquiz_update_all_attempt_sumgrades($cquiz) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {cquiz_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE cquiz = :cquizid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'cquizid' => $cquiz->id,
            'finishedstate' => cquiz_attempt::FINISHED));
}

/**
 * The cquiz grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in cquiz_grades and cquiz_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * cquiz_update_all_attempt_sumgrades, cquiz_update_all_final_grades and
 * cquiz_update_grades.
 *
 * @param float $newgrade the new maximum grade for the cquiz.
 * @param object $cquiz the cquiz we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function cquiz_set_grade($newgrade, $cquiz) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($cquiz->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $cquiz->grade;
    $cquiz->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the cquiz table.
    $DB->set_field('cquiz', 'grade', $newgrade, array('id' => $cquiz->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        cquiz_update_all_final_grades($cquiz);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {cquiz_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE cquiz = ?
        ", array($newgrade/$oldgrade, $timemodified, $cquiz->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the cquiz_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {cquiz_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE cquizid = ?
        ", array($factor, $factor, $cquiz->id));
    }

    // Update grade item and send all grades to gradebook.
    cquiz_grade_item_update($cquiz);
    cquiz_update_grades($cquiz);

    $transaction->allow_commit();
    return true;
}


/**
 * Returns an array of arrays that provide score information for a particular user on a particulular cquiz
 * 
 *  info of note
 *  	- name - name of quiz
 *  	- quiz_id id of quiz scores are taken from
 *  	- max_score
 *  	- last_score
 *  	- last_date
 *  
 * @param unknown $cquiz - id of the cquiz
 * @param unknown $userid - id of the user
 */
function cquiz_get_cummulative_scores($cquiz,$userid)
{
	$sql = <<<SQL
	SELECT 	allscores.*,
				q.name,
				allscores.current_score as last_score,
				sub_t.last_date as last_date,
				sub_t.max_score as max_score     #MAX(allscores.date_taken) as last_date
	
	FROM mdl_cquiz_cummulative_scores allscores
		JOIN mdl_quiz q ON q.id = allscores.quiz_id
		JOIN
		(
			SELECT   MAX(current_score) as max_score,MAX(date_taken) as last_date,
					m.user_id, m.quiz_id, m.cquiz_id
	
						   
			FROM     mdl_cquiz_cummulative_scores as m
			GROUP BY m.user_id, m.quiz_id, m.cquiz_id
		) sub_t ON 
	
		 allscores.user_id = sub_t.user_id AND
		 allscores.quiz_id = sub_t.quiz_id AND
		 allscores.cquiz_id = sub_t.cquiz_id AND
		 sub_t.last_date = allscores.date_taken
	WHERE
		allscores.user_id =:user_id AND
		allscores.cquiz_id =:cquiz_id
SQL;
	
	
	//do database call
	global $DB;
	
	
	return $DB->get_records_sql($sql, array("user_id"=>$userid, "cquiz_id"=>$cquiz) ); 
}


function cquiz_set_Cummulative_Grade($course,$quiz_id,$newScore,$user)
{
	global $DB,$CFG;

	$sql=<<<SQL
	Select a.id, a.duedate
	FROM 	{assign} a,
			{quiz} q  
	WHERE 	q.id = :quizid AND
			a.course = :courseid AND
			( 	UCASE(a.name)=UCASE(CONCAT('Cumulative ',q.name)) OR	
				UCASE(a.name)=UCASE(CONCAT('Completed ',:newscore,' for ',q.name))
			)	
SQL;
	
	$sql =<<<SQL
	Select a.id, a.duedate, a.name, q.name
	FROM 	{assign} a,
			{quiz} q,
			(
				SELECT CONCAT(prefix,suffix) as target
				FROM
				(
					SELECT UCASE(CONCAT('Cummulative ',q.name)) as prefix
					FROM {quiz} q
					WHERE q.id = :quizid1
				
				UNION
		
					SELECT UCASE(CONCAT('Completed ',:newscore,' for ',q.name)) as prefix
					FROM {quiz} q
					WHERE q.id = :quizid2

				) as P,
				(SELECT CONCAT(" ",UPPER(g.name)) as suffix
				FROM {groups_members} m, {groups} g
				WHERE g.id = m.groupid AND g.courseid = :courseid1 AND m.userid=:userid
				UNION
				Select "" as suffix
				) as S
			) as t
	WHERE 	
			q.id = :quizid3 AND
			a.course = :courseid2 AND
			UCASE(a.name) = t.target
SQL;

	

	$params =  array("quizid1"=>$quiz_id,
						"quizid2"=>$quiz_id,
						"quizid3"=>$quiz_id,
						"courseid1"=>$course,
						"courseid2"=>$course,
						"newscore"=>strval($newScore),
						"userid"=>$user	);
//	print "SQL"+$sql+" with ";
//	print_object($params);
/*	if(!$DB->record_exists_sql($sql,$params))
	{
		return;
	}
	*/
	$assignments = $DB->get_records_sql($sql,$params);
	
	require_once($CFG->dirroot.'/lib/gradelib.php');
	
	foreach($assignments as $assignment)
	{
		$grade = new stdClass();
		$grade->userid = $user;

		$grade->dategraded = time();
		$grade->datesubmitted = time();
	
	
		if($assignment->duedate == 0 or time() <$assignment->duedate )
		{
			$grade->rawgrade = $newScore;
		}
		else 
		{
			$grade->rawgrade = 0;
		}
		$grades= array();
		$grades[$user] = $grade;
		
		
		grade_update('mod/assign', $course, 'mod', 'assign', $assignment->id, 0, $grades);
	}
}



/**
 * Stores the cummulative data for each subType from the attempts
 * MKB
 */
function cquiz_store_cummulative_data($cquiz,$userid,$attempts)
{
	
	
	$sql = <<<SQL
	Select cumm.*, 
			AVG(fraction) as avg,
			l.last_score,
			l.last_date,
			l.max_score
	FROM	{cquiz_question_bridge} bridge 
			JOIN {cquiz_cummulative_scores} cumm ON cumm.id = bridge.cumm_id
			JOIN {cquiz_attempts} catt ON catt.id = cumm.attempt_id
			JOIN {question_usages} qa ON qa.id = catt.uniqueid 
			JOIN {question_attempts} att ON qa.id = att.questionusageid and att.slot = bridge.slot
			JOIN {question_attempt_steps} steps ON steps.questionattemptid = att.id
			JOIN
			(
				SELECT allscores.user_id,allscores.quiz_id,allscores.cquiz_id,
						allscores.current_score as last_score, sub_t.max_date as last_date,
						sub_t.max_score
				FROM {cquiz_cummulative_scores} allscores
					JOIN
					(
						SELECT  MAX(date_taken) as max_date,
								MAX(current_score) as max_score,
								m.user_id, m.quiz_id, m.cquiz_id

						FROM     {cquiz_cummulative_scores} as m
						GROUP BY m.user_id, m.quiz_id, m.cquiz_id
					) sub_t 
						ON 		allscores.user_id = sub_t.user_id AND
								allscores.quiz_id = sub_t.quiz_id AND
								allscores.cquiz_id = sub_t.cquiz_id AND
								allscores.date_taken = sub_t.max_date
		

			) l
	WHERE cumm.attempt_id=:attemptid AND
       		sequencenumber =2 AND
       		l.user_id  = cumm.user_id AND
	   		l.quiz_id  = cumm.quiz_id AND
	   		l.cquiz_id = cumm.cquiz_id AND
			cumm.date_taken=0  #ensure that the quiz has not been recorded previously

	GROUP BY cumm.id,cumm.quiz_id
SQL;
	//do database call
	global $DB,$COURSE;
	
	
	
	
	foreach($attempts as $attempt)
	{

		$rows = $DB->get_records_sql($sql, array("attemptid"=>$attempt->id) );
		
		foreach($rows as $row)
		{
/*		see if we can reuse the object..	
 * 	$newRow = new stdClass();
			$newRow->
	*/		
			
			
		
			if($row->avg >.999) //success!
			{
				$reciprical = 1.0 / (int)$row->last_score;
				$row->current_score = $row->last_score+$reciprical;
				
				//send new grade to assignment
				if( ((int) $row->current_score ) > $row->max_score)
				{	

					cquiz_set_cummulative_grade($COURSE->id,$row->quiz_id,(int)$row->current_score,$userid);
				}
				
			}
			else //fails
			{
				$row->current_score = 1.0;
			}
			//print "Score has changed from $row->last_score to $row->current_score <br /> for";
			//print_object($row);
		
			$row->date_taken = time();
			
			$DB->update_record("cquiz_cummulative_scores",$row);
			//will need to calc and store this data.
			//print $slot;
			//print_object($attemptobj->get_question_attempt($slot));
			//print_object($attemptobj->get_question_mark($slot));
		}
		
	}
	
	//$scores  = cquiz_get_cummulative_scores($cquiz,userid);
	//print_object($scores);
	
	
	
	
	
}


/**
 * Save the overall grade for a user at a cquiz in the cquiz_grades table
 *
 * @param object $cquiz The cquiz for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function cquiz_save_best_grade($cquiz, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = cquiz_get_user_attempts($cquiz->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = cquiz_calculate_best_grade($cquiz, $attempts);
    $bestgrade = cquiz_rescale_grade($bestgrade, $cquiz, false);

    
    //Store all performance data
    cquiz_store_cummulative_data($cquiz,$userid,$attempts);
    
    
    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('cquiz_grades', array('cquiz' => $cquiz->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('cquiz_grades',
            array('cquiz' => $cquiz->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('cquiz_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->cquiz = $cquiz->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('cquiz_grades', $grade);
    }

    cquiz_update_grades($cquiz, $userid);
}

/**
 * Calculate the overall grade for a cquiz given a number of attempts by a particular user.
 *
 * @param object $cquiz    the cquiz settings object.
 * @param array $attempts an array of all the user's attempts at this cquiz in order.
 * @return float          the overall grade
 */
function cquiz_calculate_best_grade($cquiz, $attempts) {

    switch ($cquiz->grademethod) {

        case CQUIZ_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades/(float)$firstattempt->num_questions;

        case CQUIZ_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades/(float)$lastattempt->num_questions;

        case CQUIZ_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades/(float)$attempt->num_questions;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case CQUIZ_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
            	$next = $attempt->sumgrades/(float)$attempt->num_questions;
                if ($next > $max) {
                    $max = $next;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this cquiz for all students.
 *
 * This function is equivalent to calling cquiz_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $cquiz the cquiz settings.
 */
function cquiz_update_all_final_grades($cquiz) {
    global $DB;

    if (!$cquiz->sumgrades) {
        return;
    }

    $param = array('icquizid' => $cquiz->id, 'istatefinished' => cquiz_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                icquiza.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {cquiz_attempts} icquiza

            WHERE
                icquiza.state = :istatefinished AND
                icquiza.preview = 0 AND
                icquiza.cquiz = :icquizid

            GROUP BY icquiza.userid
        ) first_last_attempts ON first_last_attempts.userid = cquiza.userid";

    switch ($cquiz->grademethod) {
        case CQUIZ_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(cquiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'cquiza.attempt = first_last_attempts.firstattempt AND';
            break;

        case CQUIZ_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(cquiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'cquiza.attempt = first_last_attempts.lastattempt AND';
            break;

        case CQUIZ_GRADEAVERAGE:
            $select = 'AVG(cquiza.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case CQUIZ_GRADEHIGHEST:
            $select = 'MAX(cquiza.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($cquiz->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($cquiz->grade / $cquiz->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['cquizid'] = $cquiz->id;
    $param['cquizid2'] = $cquiz->id;
    $param['cquizid3'] = $cquiz->id;
    $param['cquizid4'] = $cquiz->id;
    $param['statefinished'] = cquiz_attempt::FINISHED;
    $param['statefinished2'] = cquiz_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT cquiza.userid, $finalgrade AS newgrade
            FROM {cquiz_attempts} cquiza
            $join
            WHERE
                $where
                cquiza.state = :statefinished AND
                cquiza.preview = 0 AND
                cquiza.cquiz = :cquizid3
            GROUP BY cquiza.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {cquiz_grades} qg
                WHERE cquiz = :cquizid
            UNION
                SELECT DISTINCT userid
                FROM {cquiz_attempts} cquiza2
                WHERE
                    cquiza2.state = :statefinished2 AND
                    cquiza2.preview = 0 AND
                    cquiza2.cquiz = :cquizid2
            ) users

            LEFT JOIN {cquiz_grades} qg ON qg.userid = users.userid AND qg.cquiz = :cquizid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->cquiz = $cquiz->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('cquiz_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('cquiz_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('cquiz_grades', 'cquiz = ? AND userid ' . $test,
                array_merge(array($cquiz->id), $params));
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      cquizid   => (array|int) attempts in given cquiz(s)
 *                      groupid  => (array|int) cquizzes with some override for given group(s)
 *
 */
function cquiz_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("cquiza.state IN ('inprogress', 'overdue')");
    $iwheres = array("icquiza.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "cquiza.cquiz IN (SELECT q.id FROM {cquiz} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "icquiza.cquiz IN (SELECT q.id FROM {cquiz} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "cquiza.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "icquiza.userid $incond";
    }

    if (isset($conditions['cquizid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['cquizid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "cquiza.cquiz $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['cquizid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "icquiza.cquiz $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "cquiza.cquiz IN (SELECT qo.cquiz FROM {cquiz_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "icquiza.cquiz IN (SELECT qo.cquiz FROM {cquiz_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $cquizausersql = cquiz_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN cquizauser.usertimelimit = 0 AND cquizauser.usertimeclose = 0 THEN NULL
               WHEN cquizauser.usertimelimit = 0 THEN cquizauser.usertimeclose
               WHEN cquizauser.usertimeclose = 0 THEN cquiza.timestart + cquizauser.usertimelimit
               WHEN cquiza.timestart + cquizauser.usertimelimit < cquizauser.usertimeclose THEN cquiza.timestart + cquizauser.usertimelimit
               ELSE cquizauser.usertimeclose END +
          CASE WHEN cquiza.state = 'overdue' THEN cquiz.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {cquiz_attempts} cquiza
                        JOIN {cquiz} cquiz ON cquiz.id = cquiza.cquiz
                        JOIN ( $cquizausersql ) cquizauser ON cquizauser.id = cquiza.id
                         SET cquiza.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {cquiz_attempts} cquiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {cquiz} cquiz, ( $cquizausersql ) cquizauser
                       WHERE cquiz.id = cquiza.cquiz
                         AND cquizauser.id = cquiza.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE cquiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {cquiz_attempts} cquiza
                        JOIN {cquiz} cquiz ON cquiz.id = cquiza.cquiz
                        JOIN ( $cquizausersql ) cquizauser ON cquizauser.id = cquiza.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {cquiz_attempts} cquiza
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {cquiz} cquiz, ( $cquizausersql ) cquizauser
                            WHERE cquiz.id = cquiza.cquiz
                              AND cquizauser.id = cquiza.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias icquiza for the cquiz attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function cquiz_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $cquizausersql = "
          SELECT icquiza.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), icquiz.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), icquiz.timelimit) AS usertimelimit

           FROM {cquiz_attempts} icquiza
           JOIN {cquiz} icquiz ON icquiz.id = icquiza.cquiz
      LEFT JOIN {cquiz_overrides} quo ON quo.cquiz = icquiza.cquiz AND quo.userid = icquiza.userid
      LEFT JOIN {groups_members} gm ON gm.userid = icquiza.userid
      LEFT JOIN {cquiz_overrides} qgo1 ON qgo1.cquiz = icquiza.cquiz AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {cquiz_overrides} qgo2 ON qgo2.cquiz = icquiza.cquiz AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {cquiz_overrides} qgo3 ON qgo3.cquiz = icquiza.cquiz AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {cquiz_overrides} qgo4 ON qgo4.cquiz = icquiza.cquiz AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY icquiza.id, icquiz.id, icquiz.timeclose, icquiz.timelimit";
    return $cquizausersql;
}

/**
 * Return the attempt with the best grade for a cquiz
 *
 * Which attempt is the best depends on $cquiz->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $cquiz    The cquiz for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the cquiz
 */
function cquiz_calculate_best_attempt($cquiz, $attempts) {

    switch ($cquiz->grademethod) {

        case CQUIZ_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case CQUIZ_GRADEAVERAGE: // We need to do something with it.
        case CQUIZ_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case CQUIZ_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the cquiz grade
 *      from the individual attempt grades.
 */
function cquiz_get_grading_options() {
    return array(
        CQUIZ_GRADEHIGHEST => get_string('gradehighest', 'cquiz'),
        CQUIZ_GRADEAVERAGE => get_string('gradeaverage', 'cquiz'),
        CQUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'cquiz'),
        CQUIZ_ATTEMPTLAST  => get_string('attemptlast', 'cquiz')
    );
}

/**
 * @param int $option one of the values CQUIZ_GRADEHIGHEST, CQUIZ_GRADEAVERAGE,
 *      CQUIZ_ATTEMPTFIRST or CQUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function cquiz_get_grading_option_name($option) {
    $strings = cquiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue cquiz
 *      attempts.
 */
function cquiz_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'cquiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'cquiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'cquiz'),
    );
}

/**
 * @param string $state one of the state constants like IN_PROGRESS.
 * @return string the human-readable state name.
 */
function cquiz_attempt_state_name($state) {
    switch ($state) {
        case cquiz_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'cquiz');
        case cquiz_attempt::OVERDUE:
            return get_string('stateoverdue', 'cquiz');
        case cquiz_attempt::FINISHED:
            return get_string('statefinished', 'cquiz');
        case cquiz_attempt::ABANDONED:
            return get_string('stateabandoned', 'cquiz');
        default:
            throw new coding_exception('Unknown cquiz attempt state.');
    }
}

// Other cquiz functions ////////////////////////////////////////////////////////

/**
 * @param object $cquiz the cquiz.
 * @param int $cmid the course_module object for this cquiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function cquiz_question_action_icons($cquiz, $cmid, $question, $returnurl) {
    $html = cquiz_question_preview_button($cquiz, $question) . ' ' .
            cquiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this cquiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function cquiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $cquiz the cquiz settings
 * @param object $question the question
 * @return moodle_url to preview this question with the options from this cquiz.
 */
function cquiz_question_preview_url($cquiz, $question) {
    // Get the appropriate display options.
    $displayoptions = mod_cquiz_display_options::make_from_cquiz($cquiz,
            mod_cquiz_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $cquiz->preferredbehaviour,
            $maxmark, $displayoptions);
}

/**
 * @param object $cquiz the cquiz settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @return the HTML for a preview question icon.
 */
function cquiz_question_preview_button($cquiz, $question, $label = false) {
    global $CFG, $OUTPUT;
    if (!question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    $url = cquiz_question_preview_url($cquiz, $question);

    // Do we want a label?
    $strpreviewlabel = '';
    if ($label) {
        $strpreviewlabel = get_string('preview', 'cquiz');
    }

    // Build the icon.
    $strpreviewquestion = get_string('previewquestion', 'cquiz');
    $image = $OUTPUT->pix_icon('t/preview', $strpreviewquestion);

    $action = new popup_action('click', $url, 'questionpreview',
            question_preview_popup_params());

    return $OUTPUT->action_link($url, $image, $action, array('title' => $strpreviewquestion));
}

/**
 * @param object $attempt the attempt.
 * @param object $context the cquiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function cquiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this cquiz attempt is in - in the sense used by
 * cquiz_get_review_options, not in the sense of $attempt->state.
 * @param object $cquiz the cquiz settings
 * @param object $attempt the cquiz_attempt database row.
 * @return int one of the mod_cquiz_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function cquiz_attempt_state($cquiz, $attempt) {
    if ($attempt->state == cquiz_attempt::IN_PROGRESS) {
        return mod_cquiz_display_options::DURING;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_cquiz_display_options::IMMEDIATELY_AFTER;
    } else if (!$cquiz->timeclose || time() < $cquiz->timeclose) {
        return mod_cquiz_display_options::LATER_WHILE_OPEN;
    } else {
        return mod_cquiz_display_options::AFTER_CLOSE;
    }
}

/**
 * The the appropraite mod_cquiz_display_options object for this attempt at this
 * cquiz right now.
 *
 * @param object $cquiz the cquiz instance.
 * @param object $attempt the attempt in question.
 * @param $context the cquiz context.
 *
 * @return mod_cquiz_display_options
 */
function cquiz_get_review_options($cquiz, $attempt, $context) {
    $options = mod_cquiz_display_options::make_from_cquiz($cquiz, cquiz_attempt_state($cquiz, $attempt));

    $options->readonly = true;
    $options->flags = cquiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/cquiz/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == cquiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/cquiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/cquiz/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/cquiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different cquiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = cquiz_get_combined_reviewoptions(...)
 *
 * @param object $cquiz the cquiz instance.
 * @param array $attempts an array of attempt objects.
 * @param $context the roles and permissions context,
 *          normally the context for the cquiz module instance.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function cquiz_get_combined_reviewoptions($cquiz, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_cquiz_display_options::make_from_cquiz($cquiz,
                cquiz_attempt_state($cquiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

/**
 * Clean the question layout from various possible anomalies:
 * - Remove consecutive ","'s
 * - Remove duplicate question id's
 * - Remove extra "," from beginning and end
 * - Finally, add a ",0" in the end if there is none
 *
 * @param $string $layout the cquiz layout to clean up, usually from $cquiz->questions.
 * @param bool $removeemptypages If true, remove empty pages from the cquiz. False by default.
 * @return $string the cleaned-up layout
 */
function cquiz_clean_layout($layout, $removeemptypages = false) {
    // Remove repeated ','s. This can happen when a restore fails to find the right
    // id to relink to.
    $layout = preg_replace('/,{2,}/', ',', trim($layout, ','));

    // Remove duplicate question ids.
    $layout = explode(',', $layout);
    $cleanerlayout = array();
    $seen = array();
    foreach ($layout as $item) {
        if ($item == 0) {
            $cleanerlayout[] = '0';
        } else if (!in_array($item, $seen)) {
            $cleanerlayout[] = $item;
            $seen[] = $item;
        }
    }

    if ($removeemptypages) {
        // Avoid duplicate page breaks.
        $layout = $cleanerlayout;
        $cleanerlayout = array();
        $stripfollowingbreaks = true; // Ensure breaks are stripped from the start.
        foreach ($layout as $item) {
            if ($stripfollowingbreaks && $item == 0) {
                continue;
            }
            $cleanerlayout[] = $item;
            $stripfollowingbreaks = $item == 0;
        }
    }

    // Add a page break at the end if there is none.
    if (end($cleanerlayout) !== '0') {
        $cleanerlayout[] = '0';
    }

    return implode(',', $cleanerlayout);
}

/**
 * Get the slot for a question with a particular id.
 * @param object $cquiz the cquiz settings.
 * @param int $questionid the of a question in the cquiz.
 * @return int the corresponding slot. Null if the question is not in the cquiz.
 */
function cquiz_get_slot_for_question($cquiz, $questionid) {
    $questionids = cquiz_questions_in_cquiz($cquiz->questions);
    foreach (explode(',', $questionids) as $key => $id) {
        if ($id == $questionid) {
            return $key + 1;
        }
    }
    return null;
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function cquiz_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_cquiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = get_admin();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'cquiz', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'cquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'cquiz', $a);
    $eventdata->contexturl        = $a->cquizurl;
    $eventdata->contexturlname    = $a->cquizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function cquiz_send_notification($recipient, $submitter, $a) {

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_cquiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'cquiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'cquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'cquiz', $a);
    $eventdata->contexturl        = $a->cquizreviewurl;
    $eventdata->contexturlname    = $a->cquizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a cquiz attempt is submitted.
 *
 * @param object $course the course
 * @param object $cquiz the cquiz
 * @param object $attempt this attempt just finished
 * @param object $context the cquiz context
 * @param object $cm the coursemodule for this cquiz
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function cquiz_send_notification_messages($course, $cquiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($cquiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $cquiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/cquiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.firstname, u.lastname, u.idnumber, u.email, u.emailstop, ' .
            'u.lang, u.timezone, u.mailformat, u.maildisplay';
    $groups = groups_get_all_groups($course->id, $submitter->id);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the cquiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/cquiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Quiz info.
    $a->cquizname        = $cquiz->name;
    $a->cquizreporturl   = $CFG->wwwroot . '/mod/cquiz/report.php?id=' . $cm->id;
    $a->cquizreportlink  = '<a href="' . $a->cquizreporturl . '">' .
            format_string($cquiz->name) . ' report</a>';
    $a->cquizurl         = $CFG->wwwroot . '/mod/cquiz/view.php?id=' . $cm->id;
    $a->cquizlink        = '<a href="' . $a->cquizurl . '">' . format_string($cquiz->name) . '</a>';
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->cquizreviewurl   = $CFG->wwwroot . '/mod/cquiz/review.php?attempt=' . $attempt->id;
    $a->cquizreviewlink  = '<a href="' . $a->cquizreviewurl . '">' .
            format_string($cquiz->name) . ' review</a>';
    // Student who sat the cquiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && cquiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && cquiz_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when a cquiz attempt becomes overdue.
 *
 * @param object $course the course
 * @param object $cquiz the cquiz
 * @param object $attempt this attempt just finished
 * @param object $context the cquiz context
 * @param object $cm the coursemodule for this cquiz
 */
function cquiz_send_overdue_message($course, $cquiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($cquiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $cquiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    if (!has_capability('mod/cquiz:emailwarnoverdue', $context, $submitter, false)) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $cquizname = format_string($cquiz->name);

    $deadlines = array();
    if ($cquiz->timelimit) {
        $deadlines[] = $attempt->timestart + $cquiz->timelimit;
    }
    if ($cquiz->timeclose) {
        $deadlines[] = $cquiz->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $cquiz->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->coursename         = $course->fullname;
    $a->courseshortname    = $course->shortname;
    // Quiz info.
    $a->cquizname           = $cquizname;
    $a->cquizurl            = $CFG->wwwroot . '/mod/cquiz/view.php?id=' . $cm->id;
    $a->cquizlink           = '<a href="' . $a->cquizurl . '">' . $cquizname . '</a>';
    // Attempt info.
    $a->attemptduedate    = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $CFG->wwwroot . '/mod/cquiz/summary.php?attempt=' . $attempt->id;
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $cquizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_cquiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = get_admin();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'cquiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'cquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'cquiz', $a);
    $eventdata->contexturl        = $a->cquizurl;
    $eventdata->contexturlname    = $a->cquizname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the cquiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function cquiz_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $cquiz    = $DB->get_record('cquiz', array('id' => $event->cquizid));
    $cm      = get_coursemodule_from_id('cquiz', $event->cmid, $event->courseid);
    $attempt = $DB->get_record('cquiz_attempts', array('id' => $event->attemptid));

    if (!($course && $cquiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    return cquiz_send_notification_messages($course, $cquiz, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle the cquiz_attempt_overdue event.
 *
 * For cquizzes with applicable settings, this sends a message to the user, reminding
 * them that they forgot to submit, and that they have another chance to do so.
 *
 * @param object $event the event object.
 */
function cquiz_attempt_overdue_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $cquiz    = $DB->get_record('cquiz', array('id' => $event->cquizid));
    $cm      = get_coursemodule_from_id('cquiz', $event->cmid, $event->courseid);
    $attempt = $DB->get_record('cquiz_attempts', array('id' => $event->attemptid));

    if (!($course && $cquiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    return cquiz_send_overdue_message($course, $cquiz, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 */
function cquiz_groups_member_added_handler($event) {
    cquiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 */
function cquiz_groups_member_removed_handler($event) {
    cquiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 */
function cquiz_groups_group_deleted_handler($event) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all cquizzes with orphaned group overrides
    $sql = "SELECT o.id, o.cquiz
              FROM {cquiz_overrides} o
              JOIN {cquiz} cquiz ON cquiz.id = o.cquiz
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE cquiz.course = :courseid AND grp.id IS NULL";
    $params = array('courseid'=>$event->courseid);
    $records = $DB->get_records_sql_menu($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('cquiz_overrides', 'id', array_keys($records));
    cquiz_update_open_attempts(array('cquizid'=>array_unique(array_values($records))));
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 */
function cquiz_groups_members_removed_handler($event) {
    if ($event->userid == 0) {
        cquiz_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        cquiz_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard cquiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function cquiz_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_cquiz',
        'fullpath' => '/mod/cquiz/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'cquiz'),
            array('startattempt', 'cquiz'),
            array('timesup', 'cquiz'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the cquiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_cquiz_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * cquiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the cquiz settings, and a time constant.
     * @param object $cquiz the cquiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_cquiz_display_options set up appropriately.
     */
    public static function make_from_cquiz($cquiz, $when) {
        $options = new self();

        $options->attempt = self::extract($cquiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($cquiz->reviewcorrectness, $when);
        $options->marks = self::extract($cquiz->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($cquiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($cquiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($cquiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($cquiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;

        if ($cquiz->questiondecimalpoints != -1) {
            $options->markdp = $cquiz->questiondecimalpoints;
        } else {
            $options->markdp = $cquiz->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular cquiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_cquiz extends qubaid_join {
    public function __construct($cquizid, $includepreviews = true, $onlyfinished = false) {
        $where = 'cquiza.cquiz = :cquizacquiz';
        $params = array('cquizacquiz' => $cquizid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state == :statefinished';
            $params['statefinished'] = cquiz_attempt::FINISHED;
        }

        parent::__construct('{cquiz_attempts} cquiza', 'cquiza.uniqueid', $where, $params);
    }
}
