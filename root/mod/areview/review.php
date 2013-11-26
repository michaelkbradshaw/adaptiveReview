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
 * This page prints a review of a particular areview attempt
 *
 * It is used either by the student whose attempts this is, after the attempt,
 * or by a teacher reviewing another's attempt during or afterwards.
 *
 * @package   mod_areview
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/areview/locallib.php');
require_once($CFG->dirroot . '/mod/areview/report/reportlib.php');

$attemptid = required_param('attempt', PARAM_INT);
$page      = optional_param('page', 0, PARAM_INT);
$showall   = optional_param('showall', 0, PARAM_BOOL);

$url = new moodle_url('/mod/areview/review.php', array('attempt'=>$attemptid));
if ($page !== 0) {
    $url->param('page', $page);
}
if ($showall !== 0) {
    $url->param('showall', $showall);
}
$PAGE->set_url($url);

$attemptobj = areview_attempt::create($attemptid);
$page = $attemptobj->force_page_number_into_range($page);

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
$attemptobj->check_review_capability();

// Create an object to manage all the other (non-roles) access rules.
$accessmanager = $attemptobj->get_access_manager(time());
$accessmanager->setup_attempt_page($PAGE);

$options = $attemptobj->get_display_options(true);

// Check permissions.
if ($attemptobj->is_own_attempt()) {
    if (!$attemptobj->is_finished()) {
        redirect($attemptobj->attempt_url(null, $page));

    } else if (!$options->attempt) {
        $accessmanager->back_to_view_page($PAGE->get_renderer('mod_areview'),
                $attemptobj->cannot_review_message());
    }

} else if (!$attemptobj->is_review_allowed()) {
    throw new moodle_areview_exception($attemptobj->get_areviewobj(), 'noreviewattempt');
}

// Load the questions and states needed by this page.
if ($showall) {
    $questionids = $attemptobj->get_slots();
} else {
    $questionids = $attemptobj->get_slots($page);
}

// Save the flag states, if they are being changed.
if ($options->flags == question_display_options::EDITABLE && optional_param('savingflags', false,
        PARAM_BOOL)) {
    require_sesskey();
    $attemptobj->save_question_flags();
    redirect($attemptobj->review_url(null, $page, $showall));
}

// Log this review.
add_to_log($attemptobj->get_courseid(), 'areview', 'review', 'review.php?attempt=' .
        $attemptobj->get_attemptid(), $attemptobj->get_areviewid(), $attemptobj->get_cmid());

// Work out appropriate title and whether blocks should be shown.
if ($attemptobj->is_preview_user() && $attemptobj->is_own_attempt()) {
    $strreviewtitle = get_string('reviewofpreview', 'areview');
    navigation_node::override_active_url($attemptobj->start_attempt_url());

} else {
    $strreviewtitle = get_string('reviewofattempt', 'areview', $attemptobj->get_attempt_number());
    if (empty($attemptobj->get_areview()->showblocks) && !$attemptobj->is_preview_user()) {
        $PAGE->blocks->show_only_fake_blocks();
    }
}

// Set up the page header.
$headtags = $attemptobj->get_html_head_contributions($page, $showall);
$PAGE->set_title(format_string($attemptobj->get_areview_name()));
$PAGE->set_heading($attemptobj->get_course()->fullname);

// Summary table start. ============================================================================

// Work out some time-related things.
$attempt = $attemptobj->get_attempt();
$areview = $attemptobj->get_areview();
$overtime = 0;

if ($attempt->state == areview_attempt::FINISHED) {
    if ($timetaken = ($attempt->timefinish - $attempt->timestart)) {
        if ($areview->timelimit && $timetaken > ($areview->timelimit + 60)) {
            $overtime = $timetaken - $areview->timelimit;
            $overtime = format_time($overtime);
        }
        $timetaken = format_time($timetaken);
    } else {
        $timetaken = "-";
    }
} else {
    $timetaken = get_string('unfinished', 'areview');
}

// Prepare summary informat about the whole attempt.
$summarydata = array();
if (!$attemptobj->get_areview()->showuserpicture && $attemptobj->get_userid() != $USER->id) {
    // If showuserpicture is true, the picture is shown elsewhere, so don't repeat it.
    $student = $DB->get_record('user', array('id' => $attemptobj->get_userid()));
    $usrepicture = new user_picture($student);
    $usrepicture->courseid = $attemptobj->get_courseid();
    $summarydata['user'] = array(
        'title'   => $usrepicture,
        'content' => new action_link(new moodle_url('/user/view.php', array(
                                'id' => $student->id, 'course' => $attemptobj->get_courseid())),
                          fullname($student, true)),
    );
}

if ($attemptobj->has_capability('mod/areview:viewreports')) {
    $attemptlist = $attemptobj->links_to_other_attempts($attemptobj->review_url(null, $page,
            $showall));
    if ($attemptlist) {
        $summarydata['attemptlist'] = array(
            'title'   => get_string('attempts', 'areview'),
            'content' => $attemptlist,
        );
    }
}

// Timing information.
$summarydata['startedon'] = array(
    'title'   => get_string('startedon', 'areview'),
    'content' => userdate($attempt->timestart),
);

$summarydata['state'] = array(
    'title'   => get_string('attemptstate', 'areview'),
    'content' => areview_attempt::state_name($attempt->state),
);

if ($attempt->state == areview_attempt::FINISHED) {
    $summarydata['completedon'] = array(
        'title'   => get_string('completedon', 'areview'),
        'content' => userdate($attempt->timefinish),
    );
    $summarydata['timetaken'] = array(
        'title'   => get_string('timetaken', 'areview'),
        'content' => $timetaken,
    );
}

if (!empty($overtime)) {
    $summarydata['overdue'] = array(
        'title'   => get_string('overdue', 'areview'),
        'content' => $overtime,
    );
}

// Show marks (if the user is allowed to see marks at the moment).
$grade = areview_rescale_grade($attempt->sumgrades, $areview, false);
if ($options->marks >= question_display_options::MARK_AND_MAX && areview_has_grades($areview)) {

    if ($attempt->state != areview_attempt::FINISHED) {
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'areview'),
            'content' => get_string('attemptstillinprogress', 'areview'),
        );

    } else if (is_null($grade)) {
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'areview'),
            'content' => areview_format_grade($areview, $grade),
        );

    } else {
        // Show raw marks only if they are different from the grade (like on the view page).
        if ($areview->grade != $areview->sumgrades) {
            $a = new stdClass();
            $a->grade = areview_format_grade($areview, $attempt->sumgrades);
            $a->maxgrade = areview_format_grade($areview, $areview->sumgrades);
            $summarydata['marks'] = array(
                'title'   => get_string('marks', 'areview'),
                'content' => get_string('outofshort', 'areview', $a),
            );
        }

        // Now the scaled grade.
        $a = new stdClass();
        $a->grade = html_writer::tag('b', areview_format_grade($areview, $grade));
        $a->maxgrade = areview_format_grade($areview, $areview->grade);
        if ($areview->grade != 100) {
            $a->percent = html_writer::tag('b', format_float(
                    $attempt->sumgrades * 100 / $areview->sumgrades, 0));
            $formattedgrade = get_string('outofpercent', 'areview', $a);
        } else {
            $formattedgrade = get_string('outof', 'areview', $a);
        }
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'areview'),
            'content' => $formattedgrade,
        );
    }
}

// Feedback if there is any, and the user is allowed to see it now.
$feedback = $attemptobj->get_overall_feedback($grade);
if ($options->overallfeedback && $feedback) {
    $summarydata['feedback'] = array(
        'title'   => get_string('feedback', 'areview'),
        'content' => $feedback,
    );
}

// Summary table end. ==============================================================================

if ($showall) {
    $slots = $attemptobj->get_slots();
    $lastpage = true;
} else {
    $slots = $attemptobj->get_slots($page);
    $lastpage = $attemptobj->is_last_page($page);
}

$output = $PAGE->get_renderer('mod_areview');

// Arrange for the navigation to be displayed.
$navbc = $attemptobj->get_navigation_panel($output, 'areview_review_nav_panel', $page, $showall);
$regions = $PAGE->blocks->get_regions();
$PAGE->blocks->add_fake_block($navbc, reset($regions));

echo $output->review_page($attemptobj, $slots, $page, $showall, $lastpage, $options, $summarydata);
