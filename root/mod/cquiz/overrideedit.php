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
 * This page handles editing and creation of cquiz overrides
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  2010 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/cquiz/lib.php');
require_once($CFG->dirroot.'/mod/cquiz/locallib.php');
require_once($CFG->dirroot.'/mod/cquiz/override_form.php');


$cmid = optional_param('cmid', 0, PARAM_INT);
$overrideid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', null, PARAM_ALPHA);
$reset = optional_param('reset', false, PARAM_BOOL);

$override = null;
if ($overrideid) {

    if (! $override = $DB->get_record('cquiz_overrides', array('id' => $overrideid))) {
        print_error('invalidoverrideid', 'cquiz');
    }
    if (! $cquiz = $DB->get_record('cquiz', array('id' => $override->cquiz))) {
        print_error('invalidcoursemodule');
    }
    if (! $cm = get_coursemodule_from_instance("cquiz", $cquiz->id, $cquiz->course)) {
        print_error('invalidcoursemodule');
    }
} else if ($cmid) {

    if (! $cm = get_coursemodule_from_id('cquiz', $cmid)) {
        print_error('invalidcoursemodule');
    }
    if (! $cquiz = $DB->get_record('cquiz', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }
} else {
    print_error('invalidcoursemodule');
}
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$url = new moodle_url('/mod/cquiz/overrideedit.php');
if ($action) {
    $url->param('action', $action);
}
if ($overrideid) {
    $url->param('id', $overrideid);
} else {
    $url->param('cmid', $cmid);
}

$PAGE->set_url($url);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Add or edit an override.
require_capability('mod/cquiz:manageoverrides', $context);

if ($overrideid) {
    // Editing an override.
    $data = clone $override;
} else {
    // Creating a new override.
    $data = new stdClass();
}

// Merge cquiz defaults with data.
$keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password');
foreach ($keys as $key) {
    if (!isset($data->{$key}) || $reset) {
        $data->{$key} = $cquiz->{$key};
    }
}

// If we are duplicating an override, then clear the user/group and override id
// since they will change.
if ($action === 'duplicate') {
    $override->id = null;
    $override->userid = null;
    $override->groupid = null;
}

// True if group-based override.
$groupmode = !empty($data->groupid) || ($action === 'addgroup' && empty($overrideid));

$overridelisturl = new moodle_url('/mod/cquiz/overrides.php', array('cmid'=>$cm->id));
if (!$groupmode) {
    $overridelisturl->param('mode', 'user');
}

// Setup the form.
$mform = new cquiz_override_form($url, $cm, $cquiz, $context, $groupmode, $override);
$mform->set_data($data);

if ($mform->is_cancelled()) {
    redirect($overridelisturl);

} else if (optional_param('resetbutton', 0, PARAM_ALPHA)) {
    $url->param('reset', true);
    redirect($url);

} else if ($fromform = $mform->get_data()) {
    // Process the data.
    $fromform->cquiz = $cquiz->id;

    // Replace unchanged values with null.
    foreach ($keys as $key) {
        if ($fromform->{$key} == $cquiz->{$key}) {
            $fromform->{$key} = null;
        }
    }

    // See if we are replacing an existing override.
    $userorgroupchanged = false;
    if (empty($override->id)) {
        $userorgroupchanged = true;
    } else if (!empty($fromform->userid)) {
        $userorgroupchanged = $fromform->userid !== $override->userid;
    } else {
        $userorgroupchanged = $fromform->groupid !== $override->groupid;
    }

    if ($userorgroupchanged) {
        $conditions = array(
                'cquiz' => $cquiz->id,
                'userid' => empty($fromform->userid)? null : $fromform->userid,
                'groupid' => empty($fromform->groupid)? null : $fromform->groupid);
        if ($oldoverride = $DB->get_record('cquiz_overrides', $conditions)) {
            // There is an old override, so we merge any new settings on top of
            // the older override.
            foreach ($keys as $key) {
                if (is_null($fromform->{$key})) {
                    $fromform->{$key} = $oldoverride->{$key};
                }
            }
            // Delete the old override.
            $DB->delete_records('cquiz_overrides', array('id' => $oldoverride->id));
        }
    }

    if (!empty($override->id)) {
        $fromform->id = $override->id;
        $DB->update_record('cquiz_overrides', $fromform);
    } else {
        unset($fromform->id);
        $fromform->id = $DB->insert_record('cquiz_overrides', $fromform);
    }

    cquiz_update_open_attempts(array('cquizid'=>$cquiz->id));
    cquiz_update_events($cquiz, $fromform);

    add_to_log($cm->course, 'cquiz', 'edit override',
            "overrideedit.php?id=$fromform->id", $cquiz->id, $cm->id);

    if (!empty($fromform->submitbutton)) {
        redirect($overridelisturl);
    }

    // The user pressed the 'again' button, so redirect back to this page.
    $url->remove_params('cmid');
    $url->param('action', 'duplicate');
    $url->param('id', $fromform->id);
    redirect($url);

}

// Print the form.
$pagetitle = get_string('editoverride', 'cquiz');
$PAGE->navbar->add($pagetitle);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);

$mform->display();

echo $OUTPUT->footer();
