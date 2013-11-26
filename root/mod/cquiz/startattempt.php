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
 * This script deals with starting a new attempt at a cquiz.
 *
 * Normally, it will end up redirecting to attempt.php - unless a password form is displayed.
 *
 * This code used to be at the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_cquiz
 * @copyright 2009 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id
$forcenew = optional_param('forcenew', false, PARAM_BOOL); // Used to force a new preview
$page = optional_param('page', -1, PARAM_INT); // Page to jump to in the attempt.

if (!$cm = get_coursemodule_from_id('cquiz', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$cquizobj = cquiz::create($cm->instance, $USER->id);
// This script should only ever be posted to, so set page URL to the view page.
$PAGE->set_url($cquizobj->view_url());

// Check login and sesskey.
require_login($cquizobj->get_course(), false, $cquizobj->get_cm());
require_sesskey();

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$cquizobj->has_questions()) {
    if ($cquizobj->has_capability('mod/cquiz:manage')) {
        redirect($cquizobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'cquiz', $cquizobj->view_url());
    }
}

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = $cquizobj->get_access_manager($timenow);
if ($cquizobj->is_preview_user() && $forcenew) {
    $accessmanager->current_attempt_finished();
}

// Check capabilities.
if (!$cquizobj->is_preview_user()) {
    $cquizobj->require_capability('mod/cquiz:attempt');
}

// Check to see if a new preview was requested.
if ($cquizobj->is_preview_user() && $forcenew) {
    // To force the creation of a new preview, we mark the current attempt (if any)
    // as finished. It will then automatically be deleted below.
    $DB->set_field('cquiz_attempts', 'state', cquiz_attempt::FINISHED,
            array('cquiz' => $cquizobj->get_cquizid(), 'userid' => $USER->id));
}

// Look for an existing attempt.
$attempts = cquiz_get_user_attempts($cquizobj->get_cquizid(), $USER->id, 'all', true);
$lastattempt = end($attempts);

// If an in-progress attempt exists, check password then redirect to it.
if ($lastattempt && ($lastattempt->state == cquiz_attempt::IN_PROGRESS ||
        $lastattempt->state == cquiz_attempt::OVERDUE)) {
    $currentattemptid = $lastattempt->id;
    $messages = $accessmanager->prevent_access();

    // If the attempt is now overdue, deal with that.
    $cquizobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

    // And, if the attempt is now no longer in progress, redirect to the appropriate place.
    if ($lastattempt->state == cquiz_attempt::OVERDUE) {
        redirect($cquizobj->summary_url($lastattempt->id));
    } else if ($lastattempt->state != cquiz_attempt::IN_PROGRESS) {
        redirect($cquizobj->review_url($lastattempt->id));
    }

    // If the page number was not explicitly in the URL, go to the current page.
    if ($page == -1) {
        $page = $lastattempt->currentpage;
    }

} else {
    while ($lastattempt && $lastattempt->preview) {
        $lastattempt = array_pop($attempts);
    }

    // Get number for the next or unfinished attempt.
    if ($lastattempt) {
        $attemptnumber = $lastattempt->attempt + 1;
    } else {
        $lastattempt = false;
        $attemptnumber = 1;
    }
    $currentattemptid = null;

    $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

    if ($page == -1) {
        $page = 0;
    }
}

// Check access.
$output = $PAGE->get_renderer('mod_cquiz');
if (!$cquizobj->is_preview_user() && $messages) {
    print_error('attempterror', 'cquiz', $cquizobj->view_url(),
            $output->access_messages($messages));
}

if ($accessmanager->is_preflight_check_required($currentattemptid)) {
    // Need to do some checks before allowing the user to continue.
    $mform = $accessmanager->get_preflight_check_form(
            $cquizobj->start_attempt_url($page), $currentattemptid);

    if ($mform->is_cancelled()) {
        $accessmanager->back_to_view_page($output);

    } else if (!$mform->get_data()) {

        // Form not submitted successfully, re-display it and stop.
        $PAGE->set_url($cquizobj->start_attempt_url($page));
        $PAGE->set_title(format_string($cquizobj->get_cquiz_name()));
        $accessmanager->setup_attempt_page($PAGE);
        if (empty($cquizobj->get_cquiz()->showblocks)) {
            $PAGE->blocks->show_only_fake_blocks();
        }

        echo $output->start_attempt_page($cquizobj, $mform);
        die();
    }

    // Pre-flight check passed.
    $accessmanager->notify_preflight_check_passed($currentattemptid);
}
if ($currentattemptid) {
    redirect($cquizobj->attempt_url($currentattemptid, $page));
}

// Delete any previous preview attempts belonging to this user.
cquiz_delete_previews($cquizobj->get_cquiz(), $USER->id);

$quba = question_engine::make_questions_usage_by_activity('mod_cquiz', $cquizobj->get_context());
$quba->set_preferred_behaviour($cquizobj->get_cquiz()->preferredbehaviour);

// Create the new attempt and initialize the question sessions
$timenow = time(); // Update time now, in case the server is running really slowly.
$attempt = cquiz_create_attempt($cquizobj, $attemptnumber, $lastattempt, $timenow,
        $cquizobj->is_preview_user());
$questionQuizMap = array();

if (!($cquizobj->get_cquiz()->attemptonlast && $lastattempt)) {
    // Starting a normal, new, cquiz attempt.

    // Fully load all the questions in this cquiz.
    $cquizobj->preload_questions();
    $cquizobj->load_questions();

    // Add them all to the $quba.
    $idstoslots = array();
    $questionsinuse = array_keys($cquizobj->get_questions());
    
    foreach ($cquizobj->get_questions() as $i => $questiondata) {

    	    	
        if ($questiondata->qtype != 'random') {
            if (!$cquizobj->get_cquiz()->shuffleanswers) {
                $questiondata->options->shuffleanswers = false;
            }
            $question = question_bank::make_question($questiondata);

        } else {
            $question = question_bank::get_qtype('random')->choose_other_question(
                    $questiondata, $questionsinuse, $cquizobj->get_cquiz()->shuffleanswers);
            if (is_null($question)) {
                throw new moodle_exception('notenoughrandomquestions', 'cquiz',
                        $cquizobj->view_url(), $questiondata);
            }
        }
        $quizid = $cquizobj->questionFromQuiz($questiondata->id); //get it before it gets altered ?
		//print("here is quizID $quizid and question $questiondata->id for question $question->id<br /> \n");
		if(!array_key_exists($quizid, $questionQuizMap))
		{
			//print "Creating new Array for $quizid <br /> \n";
			$row = array();
			$questionQuizMap[$quizid] = $row;
		} 
		
		$row = $questionQuizMap[$quizid];
		$row[] = $i;//$question->id; //object in hash should be updated..
		$questionQuizMap[$quizid] = $row; //seems to need this
		


        $idstoslots[$i] = $quba->add_question($question, $questiondata->maxmark);
        $questionsinuse[] = $question->id;
    }
    
    //print_object($questionQuizMap);

    // Start all the questions.
    if ($attempt->preview) {
        $variantoffset = rand(1, 100);
    } else {
        $variantoffset = $attemptnumber;
    }
    $quba->start_all_questions(
            new question_variant_pseudorandom_no_repeats_strategy($variantoffset), $timenow);

    // Update attempt layout.
    $newlayout = array();
//    print_object($attempt);
//    print_object($idstoslots);
    foreach (explode(',', $attempt->layout) as $qid) {
        if ($qid != 0) {
            $newlayout[] = $idstoslots[$qid];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
   // print "layout 1 \n";
    //print_object($attempt->layout);
    //print_object($idstoslots);

} else {
    // Starting a subsequent attempt in each attempt builds on last mode.

    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    //print "layout 2 \n";
    //print_object($attempt->layout);
}

// Save the attempt in the database.
$transaction = $DB->start_delegated_transaction();
question_engine::save_questions_usage_by_activity($quba);
$attempt->uniqueid = $quba->get_id();
$attempt->id = $DB->insert_record('cquiz_attempts', $attempt);

//check for easter egg

$easter = $DB->get_field("quiz", "id", array("course"=>$course->id,"name"=>"EASTER_EGG" ));
if(!$easter) { $easter = -1; }




//MKB here I can save the ids to DB
foreach($questionQuizMap as $quizid=>$row)
{	//1 create row for each quiz and link to attempt
	
	$cumm_row = new stdClass();
	$cumm_row->cquiz_id = $cquizobj->get_cquizid();
	$cumm_row->quiz_id=$quizid;
	$cumm_row->user_id=$attempt->userid;
	$cumm_row->attempt_id=$attempt->id;
	$cumm_row->date_taken =0; //will update when valid
	if($quizid==$easter) 	{ $cumm_row->current_score=2; } //always random
	else 					{ $cumm_row->current_score=1;}
	$cumm_row->id = $DB->insert_record('cquiz_cummulative_scores', $cumm_row);
	
	//2 create rows for each question in each quiz and link
	foreach($row as $q)
	{
		$bridge = new stdClass();
		$bridge->cumm_id=$cumm_row->id;
		$bridge->slot = $idstoslots[$q]; //now slots?
		$bridge->id = $DB->insert_record('cquiz_question_bridge', $bridge);
		//print_object($bridge);
		
	}
}


// Log the new attempt.
if ($attempt->preview) {
    add_to_log($course->id, 'cquiz', 'preview', 'view.php?id=' . $cquizobj->get_cmid(),
            $cquizobj->get_cquizid(), $cquizobj->get_cmid());
} else {
    add_to_log($course->id, 'cquiz', 'attempt', 'review.php?attempt=' . $attempt->id,
            $cquizobj->get_cquizid(), $cquizobj->get_cmid());
}

// Trigger event.
$eventdata = new stdClass();
$eventdata->component = 'mod_cquiz';
$eventdata->attemptid = $attempt->id;
$eventdata->timestart = $attempt->timestart;
$eventdata->timestamp = $attempt->timestart;
$eventdata->userid    = $attempt->userid;
$eventdata->cquizid    = $cquizobj->get_cquizid();
$eventdata->cmid      = $cquizobj->get_cmid();
$eventdata->courseid  = $cquizobj->get_courseid();
events_trigger('cquiz_attempt_started', $eventdata);

$transaction->allow_commit();

// Redirect to the attempt page.
redirect($cquizobj->attempt_url($attempt->id, $page));
