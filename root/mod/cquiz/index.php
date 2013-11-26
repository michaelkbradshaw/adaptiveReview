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
 * This script lists all the instances of cquiz in a particular course
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/cquiz/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

add_to_log($course->id, "cquiz", "view all", "index.php?id=$course->id", "");

// Print the header.
$strcquizzes = get_string("modulenameplural", "cquiz");
$streditquestions = '';
$editqcontexts = new question_edit_contexts($coursecontext);
if ($editqcontexts->have_one_edit_tab_cap('questions')) {
    $streditquestions =
            "<form target=\"_parent\" method=\"get\" action=\"$CFG->wwwroot/question/edit.php\">
               <div>
               <input type=\"hidden\" name=\"courseid\" value=\"$course->id\" />
               <input type=\"submit\" value=\"".get_string("editquestions", "cquiz")."\" />
               </div>
             </form>";
}
$PAGE->navbar->add($strcquizzes);
$PAGE->set_title($strcquizzes);
$PAGE->set_button($streditquestions);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// Get all the appropriate data.
if (!$cquizzes = get_all_instances_in_course("cquiz", $course)) {
    notice(get_string('thereareno', 'moodle', $strcquizzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the closing date header.
$showclosingheader = false;
$showfeedback = false;
foreach ($cquizzes as $cquiz) {
    if ($cquiz->timeclose!=0) {
        $showclosingheader=true;
    }
    if (cquiz_has_feedback($cquiz)) {
        $showfeedback=true;
    }
    if ($showclosingheader && $showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

if ($showclosingheader) {
    array_push($headings, get_string('cquizcloses', 'cquiz'));
    array_push($align, 'left');
}

array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/cquiz:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'cquiz'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/cquiz:reviewmyattempts', 'mod/cquiz:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'cquiz'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'cquiz'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.cquiz, qg.grade
            FROM {cquiz_grades} qg
            JOIN {cquiz} q ON q.id = qg.cquiz
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
foreach ($cquizzes as $cquiz) {
    $cm = get_coursemodule_from_instance('cquiz', $cquiz->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($cquiz->section != $currentsection) {
        if ($cquiz->section) {
            $strsection = $cquiz->section;
            $strsection = get_section_name($course, $cquiz->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $cquiz->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$cquiz->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$cquiz->coursemodule\">" .
            format_string($cquiz->name, true) . '</a>';

    // Close date.
    if ($cquiz->timeclose) {
        $data[] = userdate($cquiz->timeclose);
    } else if ($showclosingheader) {
        $data[] = '';
    }

    if ($showing == 'stats') {
        // The $cquiz objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = cquiz_attempt_summary_link_to_reports($cquiz, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = cquiz_get_user_attempts($cquiz->id, $USER->id, 'all');
        list($someoptions, $alloptions) = cquiz_get_combined_reviewoptions(
                $cquiz, $attempts, $context);

        $grade = '';
        $feedback = '';
        if ($cquiz->grade && array_key_exists($cquiz->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = cquiz_format_grade($cquiz, $grades[$cquiz->id]);
                $a->maxgrade = cquiz_format_grade($cquiz, $cquiz->grade);
                $grade = get_string('outofshort', 'cquiz', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = cquiz_feedback_for_grade($grades[$cquiz->id], $cquiz, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over cquiz instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
