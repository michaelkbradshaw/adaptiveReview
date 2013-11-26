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
 * This page is the entry page into the cquiz UI. Displays information about the
 * cquiz to students and teachers, and lets students see their previous attempts.
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/cquiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Quiz ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('cquiz', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
} else {
    if (!$cquiz = $DB->get_record('cquiz', array('id' => $q))) {
        print_error('invalidcquizid', 'cquiz');
    }
    if (!$course = $DB->get_record('course', array('id' => $cquiz->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("cquiz", $cquiz->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/cquiz:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/cquiz:attempt', $context);
$canreviewmine = has_capability('mod/cquiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/cquiz:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$cquizobj = cquiz::create($cm->instance, $USER->id);
$accessmanager = new cquiz_access_manager($cquizobj, $timenow,
        has_capability('mod/cquiz:ignoretimelimits', $context, null, false));
$cquiz = $cquizobj->get_cquiz();

// Log this request.
add_to_log($course->id, 'cquiz', 'view', 'view.php?id=' . $cm->id, $cquiz->id, $cm->id);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/cquiz/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_cquiz_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine;

// Get this user's attempts.
$attempts = cquiz_get_user_attempts($cquiz->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
if ($unfinishedattempt = cquiz_get_user_attempt_unfinished($cquiz->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $cquizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == cquiz_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == cquiz_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new cquiz_attempt($attempt, $cquiz, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = cquiz_get_best_grade($cquiz, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the cquiz don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = cquiz_rescale_grade($lastfinishedattempt->sumgrades, $cquiz, false);
	//$mygrade = $lastfinishedattempt->sumgrades/$attempt->num_questions;
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'cquiz', $cquiz->id, $USER->id);
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

$title = $course->shortname . ': ' . format_string($cquiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_cquiz');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = cquiz_get_combined_reviewoptions($cquiz, $attempts, $context);

    $viewobj->attemptcolumn  = $cquiz->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            cquiz_has_grades($cquiz);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($cquiz->grade != $cquiz->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = cquiz_has_feedback($cquiz) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/cquiz:manage', $context);
$viewobj->editurl = new moodle_url('/mod/cquiz/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $cquizobj->start_attempt_url();
$viewobj->startattemptwarning = $cquizobj->confirm_start_attempt_message($unfinished);
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this cquiz.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($cquiz->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'cquiz',
            cquiz_get_grading_option_name($cquiz->grademethod));
}

// Determine wheter a start attempt button should be displayed.
$viewobj->cquizhasquestions = (bool) cquiz_clean_layout($cquiz->questions, true);
$viewobj->preventmessages = array();
if (!$viewobj->cquizhasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptcquiz', 'cquiz');
        } else if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'cquiz');
        }

    } else {
        if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptcquiznow', 'cquiz');
            } else {
                $viewobj->buttontext = get_string('reattemptcquiz', 'cquiz');
            }

        } else if ($canpreview) {
            $viewobj->buttontext = get_string('previewcquiznow', 'cquiz');
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
    // Guests can't do a cquiz, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $cquiz, $cm, $context, $viewobj->infomessages);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $cquiz, $cm, $context, $viewobj->infomessages);
} else {
    echo $output->view_page($course, $cquiz, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
