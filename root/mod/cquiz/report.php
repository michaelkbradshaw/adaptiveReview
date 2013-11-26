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
 * This script controls the display of the cquiz reports.
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/cquiz/locallib.php');
require_once($CFG->dirroot . '/mod/cquiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/cquiz/report/default.php');

$id = optional_param('id', 0, PARAM_INT);
$q = optional_param('q', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);

if ($id) {
    if (!$cm = get_coursemodule_from_id('cquiz', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
    if (!$cquiz = $DB->get_record('cquiz', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
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

$url = new moodle_url('/mod/cquiz/report.php', array('id' => $cm->id));
if ($mode !== '') {
    $url->param('mode', $mode);
}
$PAGE->set_url($url);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('report');

$reportlist = cquiz_report_list($context);
if (empty($reportlist)) {
    print_error('erroraccessingreport', 'cquiz');
}

// Validate the requested report name.
if ($mode == '') {
    // Default to first accessible report and redirect.
    $url->param('mode', reset($reportlist));
    redirect($url);
} else if (!in_array($mode, $reportlist)) {
    print_error('erroraccessingreport', 'cquiz');
}
if (!is_readable("report/$mode/report.php")) {
    print_error('reportnotfound', 'cquiz', '', $mode);
}

add_to_log($course->id, 'cquiz', 'report', 'report.php?id=' . $cm->id . '&mode=' . $mode,
        $cquiz->id, $cm->id);

// Open the selected cquiz report and display it.
$file = $CFG->dirroot . '/mod/cquiz/report/' . $mode . '/report.php';
if (is_readable($file)) {
    include_once($file);
}
$reportclassname = 'cquiz_' . $mode . '_report';
if (!class_exists($reportclassname)) {
    print_error('preprocesserror', 'cquiz');
}

$report = new $reportclassname();
$report->display($cquiz, $cm, $course);

// Print footer.
echo $OUTPUT->footer();
