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
 * This page is the entry page into the areview UI. Displays information about the
 * areview to students and teachers, and lets students see their previous attempts.
 *
 * @package    mod
 * @subpackage areview
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/areview/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Quiz ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('areview', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
} else {
    if (!$areview = $DB->get_record('areview', array('id' => $q))) {
        print_error('invalidareviewid', 'areview');
    }
    if (!$course = $DB->get_record('course', array('id' => $areview->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("areview", $areview->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/areview:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/areview:attempt', $context);
$canreviewmine = has_capability('mod/areview:reviewmyattempts', $context);
$canpreview = has_capability('mod/areview:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$areviewobj = areview::create($cm->instance, $USER->id);
$accessmanager = new areview_access_manager($areviewobj, $timenow,
        has_capability('mod/areview:ignoretimelimits', $context, null, false));
$areview = $areviewobj->get_areview();

// Log this request.
add_to_log($course->id, 'areview', 'view', 'view.php?id=' . $cm->id, $areview->id, $cm->id);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/areview/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_areview_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine;

// Get this user's attempts.
$attempts = areview_get_user_attempts($areview->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
if ($unfinishedattempt = areview_get_user_attempt_unfinished($areview->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $areviewobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == areview_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == areview_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new areview_attempt($attempt, $areview, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = areview_get_best_grade($areview, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the areview don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = areview_rescale_grade($lastfinishedattempt->sumgrades, $areview, false);
	//$mygrade = $lastfinishedattempt->sumgrades/$attempt->num_questions;
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'areview', $areview->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($areview->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_areview');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = areview_get_combined_reviewoptions($areview, $attempts, $context);

    $viewobj->attemptcolumn  = $areview->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            areview_has_grades($areview);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($areview->grade != $areview->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = areview_has_feedback($areview) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/areview:manage', $context);
$viewobj->editurl = new moodle_url('/mod/areview/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $areviewobj->start_attempt_url();
$viewobj->startattemptwarning = $areviewobj->confirm_start_attempt_message($unfinished);
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this areview.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($areview->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'areview',
            areview_get_grading_option_name($areview->grademethod));
}

// Determine wheter a start attempt button should be displayed.
$viewobj->areviewhasquestions = (bool) areview_clean_layout($areview->questions, true);
$viewobj->preventmessages = array();
if (!$viewobj->areviewhasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptareview', 'areview');
        } else if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'areview');
        }

    } else {
        if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptareviewnow', 'areview');
            } else {
                $viewobj->buttontext = get_string('reattemptareview', 'areview');
            }

        } else if ($canpreview) {
            $viewobj->buttontext = get_string('previewareviewnow', 'areview');
        }
    }

    // If, so far, we think a button should be printed, so check if they will be
    // allowed to access it.
    if ($viewobj->buttontext) {
        if (!$viewobj->moreattempts) {
            $viewobj->buttontext = '';
        } else if ($canattempt
                && $viewobj->preventmessages = $viewobj->accessmanager->prevent_access()) {
            $viewobj->buttontext = '';
        }
    }
}

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do a areview, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $areview, $cm, $context, $viewobj->infomessages);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $areview, $cm, $context, $viewobj->infomessages);
} else {
    echo $output->view_page($course, $areview, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
