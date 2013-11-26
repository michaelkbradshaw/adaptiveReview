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
 * This page prints a review of a particular question attempt.
 * This page is expected to only be used in a popup window.
 *
 * @package    mod
 * @subpackage areview
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once('locallib.php');

$attemptid = required_param('attempt', PARAM_INT);
$slot = required_param('slot', PARAM_INT);
$seq = optional_param('step', null, PARAM_INT);

$baseurl = new moodle_url('/mod/areview/reviewquestion.php',
        array('attempt' => $attemptid, 'slot' => $slot));
$currenturl = new moodle_url($baseurl);
if (!is_null($seq)) {
    $currenturl->param('step', $seq);
}
$PAGE->set_url($currenturl);

$attemptobj = areview_attempt::create($attemptid);

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
$attemptobj->check_review_capability();

$accessmanager = $attemptobj->get_access_manager(time());
$options = $attemptobj->get_display_options(true);

$PAGE->set_pagelayout('popup');
$output = $PAGE->get_renderer('mod_areview');

// Check permissions.
if ($attemptobj->is_own_attempt()) {
    if (!$attemptobj->is_finished()) {
        echo $output->review_question_not_allowed(get_string('cannotreviewopen', 'areview'));
        die();
    } else if (!$options->attempt) {
        echo $output->review_question_not_allowed(
                $attemptobj->cannot_review_message());
        die();
    }

} else if (!$attemptobj->is_review_allowed()) {
    throw new moodle_areview_exception($attemptobj->get_areviewobj(), 'noreviewattempt');
}

// Prepare summary informat about this question attempt.
$summarydata = array();

// Quiz name.
$summarydata['areviewname'] = array(
    'title'   => get_string('modulename', 'areview'),
    'content' => format_string($attemptobj->get_areview_name()),
);

// Question name.
$summarydata['questionname'] = array(
    'title'   => get_string('question', 'areview'),
    'content' => $attemptobj->get_question_name($slot),
);

// Other attempts at the areview.
if ($attemptobj->has_capability('mod/areview:viewreports')) {
    $attemptlist = $attemptobj->links_to_other_attempts($baseurl);
    if ($attemptlist) {
        $summarydata['attemptlist'] = array(
            'title'   => get_string('attempts', 'areview'),
            'content' => $attemptlist,
        );
    }
}

// Timestamp of this action.
$timestamp = $attemptobj->get_question_action_time($slot);
if ($timestamp) {
    $summarydata['timestamp'] = array(
        'title'   => get_string('completedon', 'areview'),
        'content' => userdate($timestamp),
    );
}

echo $output->review_question_page($attemptobj, $slot, $seq,
        $attemptobj->get_display_options(true), $summarydata);
