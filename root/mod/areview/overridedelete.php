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
 * This page handles deleting areview overrides
 *
 * @package    mod_areview
 * @copyright  2010 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/areview/lib.php');
require_once($CFG->dirroot.'/mod/areview/locallib.php');
require_once($CFG->dirroot.'/mod/areview/override_form.php');

$overrideid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);

if (! $override = $DB->get_record('areview_overrides', array('id' => $overrideid))) {
    print_error('invalidoverrideid', 'areview');
}
if (! $areview = $DB->get_record('areview', array('id' => $override->areview))) {
    print_error('invalidcoursemodule');
}
if (! $cm = get_coursemodule_from_instance("areview", $areview->id, $areview->course)) {
    print_error('invalidcoursemodule');
}
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// Check the user has the required capabilities to modify an override.
require_capability('mod/areview:manageoverrides', $context);

$url = new moodle_url('/mod/areview/overridedelete.php', array('id'=>$override->id));
$confirmurl = new moodle_url($url, array('id'=>$override->id, 'confirm'=>1));
$cancelurl = new moodle_url('/mod/areview/overrides.php', array('cmid'=>$cm->id));

if (!empty($override->userid)) {
    $cancelurl->param('mode', 'user');
}

// If confirm is set (PARAM_BOOL) then we have confirmation of intention to delete.
if ($confirm) {
    require_sesskey();

    areview_delete_override($areview, $override->id);

    add_to_log($cm->course, 'areview', 'delete override',
        "overrides.php?cmid=$cm->id", $areview->id, $cm->id);

    redirect($cancelurl);
}

// Prepare the page to show the confirmation form.
$stroverride = get_string('override', 'areview');
$title = get_string('deletecheck', null, $stroverride);

$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add($title);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

if ($override->groupid) {
    $group = $DB->get_record('groups', array('id' => $override->groupid), 'id, name');
    $confirmstr = get_string("overridedeletegroupsure", "areview", $group->name);
} else {
    $user = $DB->get_record('user', array('id' => $override->userid),
            'id, firstname, lastname');
    $confirmstr = get_string("overridedeleteusersure", "areview", fullname($user));
}

echo $OUTPUT->confirm($confirmstr, $confirmurl, $cancelurl);

echo $OUTPUT->footer();
