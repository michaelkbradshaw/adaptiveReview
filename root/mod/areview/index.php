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
 * This script lists all the instances of areview in a particular course
 *
 * @package    mod
 * @subpackage areview
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/areview/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

add_to_log($course->id, "areview", "view all", "index.php?id=$course->id", "");

// Print the header.
$strareviewzes = get_string("modulenameplural", "areview");
$streditquestions = '';
$editqcontexts = new question_edit_contexts($coursecontext);
if ($editqcontexts->have_one_edit_tab_cap('questions')) {
    $streditquestions =
            "<form target=\"_parent\" method=\"get\" action=\"$CFG->wwwroot/question/edit.php\">
               <div>
               <input type=\"hidden\" name=\"courseid\" value=\"$course->id\" />
               <input type=\"submit\" value=\"".get_string("editquestions", "areview")."\" />
               </div>
             </form>";
}
$PAGE->navbar->add($strareviewzes);
$PAGE->set_title($strareviewzes);
$PAGE->set_button($streditquestions);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// Get all the appropriate data.
if (!$areviewzes = get_all_instances_in_course("areview", $course)) {
    notice(get_string('thereareno', 'moodle', $strareviewzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the closing date header.
$showclosingheader = false;
$showfeedback = false;
foreach ($areviewzes as $areview) {
    if ($areview->timeclose!=0) {
        $showclosingheader=true;
    }
    if (areview_has_feedback($areview)) {
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
    array_push($headings, get_string('areviewcloses', 'areview'));
    array_push($align, 'left');
}

array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/areview:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'areview'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/areview:reviewmyattempts', 'mod/areview:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'areview'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'areview'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.areview, qg.grade
            FROM {areview_grades} qg
            JOIN {areview} q ON q.id = qg.areview
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
foreach ($areviewzes as $areview) {
    $cm = get_coursemodule_from_instance('areview', $areview->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($areview->section != $currentsection) {
        if ($areview->section) {
            $strsection = $areview->section;
            $strsection = get_section_name($course, $areview->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $areview->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$areview->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$areview->coursemodule\">" .
            format_string($areview->name, true) . '</a>';

    // Close date.
    if ($areview->timeclose) {
        $data[] = userdate($areview->timeclose);
    } else if ($showclosingheader) {
        $data[] = '';
    }

    if ($showing == 'stats') {
        // The $areview objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = areview_attempt_summary_link_to_reports($areview, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = areview_get_user_attempts($areview->id, $USER->id, 'all');
        list($someoptions, $alloptions) = areview_get_combined_reviewoptions(
                $areview, $attempts, $context);

        $grade = '';
        $feedback = '';
        if ($areview->grade && array_key_exists($areview->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = areview_format_grade($areview, $grades[$areview->id]);
                $a->maxgrade = areview_format_grade($areview, $areview->grade);
                $grade = get_string('outofshort', 'areview', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = areview_feedback_for_grade($grades[$areview->id], $areview, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over areview instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
