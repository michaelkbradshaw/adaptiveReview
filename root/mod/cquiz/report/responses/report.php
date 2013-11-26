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
 * This file defines the cquiz responses report class.
 *
 * @package   cquiz_responses
 * @copyright 2006 Jean-Michel Vedrine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/cquiz/report/responses/responses_options.php');
require_once($CFG->dirroot . '/mod/cquiz/report/responses/responses_form.php');
require_once($CFG->dirroot . '/mod/cquiz/report/responses/responses_table.php');


/**
 * Quiz report subclass for the responses report.
 *
 * This report lists some combination of
 *  * what question each student saw (this makes sense if random questions were used).
 *  * the response they gave,
 *  * and what the right answer is.
 *
 * Like the overview report, there are options for showing students with/without
 * attempts, and for deleting selected attempts.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquiz_responses_report extends cquiz_attempts_report {

    public function display($cquiz, $cm, $course) {
        global $CFG, $DB, $OUTPUT;

        list($currentgroup, $students, $groupstudents, $allowed) =
                $this->init('responses', 'cquiz_responses_settings_form', $cquiz, $cm, $course);
        $options = new cquiz_responses_options('responses', $cquiz, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);

        } else {
            $options->process_settings_from_params();
        }

        $this->form->set_data($options->get_initial_form_data());

        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups in
            // groups mode, so setting allowed to empty (which means all cquiz attempts
            // are accessible, is not a security porblem.
            $allowed = array();
        }

        // Load the required questions.
        $questions = cquiz_report_get_significant_questions($cquiz);

        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                array('context' => context_course::instance($course->id)));
        $table = new cquiz_responses_table($cquiz, $this->context, $this->qmsubselect,
                $options, $groupstudents, $students, $questions, $options->get_url());
        $filename = cquiz_report_download_filename(get_string('responsesfilename', 'cquiz_responses'),
                $courseshortname, $cquiz->name);
        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($cquiz->name, true));
        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->process_actions($cquiz, $cm, $currentgroup, $groupstudents, $allowed, $options->get_url());

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
            $this->print_header_and_tabs($cm, $course, $cquiz, $this->mode);
        }

        if ($groupmode = groups_get_activity_groupmode($cm)) {
            // Groups are being used, so output the group selector if we are not downloading.
            if (!$table->is_downloading()) {
                groups_print_activity_menu($cm, $options->get_url());
            }
        }

        // Print information on the number of existing attempts.
        if (!$table->is_downloading()) {
            // Do not print notices when downloading.
            if ($strattemptnum = cquiz_num_attempt_summary($cquiz, $cm, true, $currentgroup)) {
                echo '<div class="cquizattemptcounts">' . $strattemptnum . '</div>';
            }
        }

        $hasquestions = cquiz_questions_in_cquiz($cquiz->questions);
        if (!$table->is_downloading()) {
            if (!$hasquestions) {
                echo cquiz_no_questions_message($cquiz, $cm, $this->context);
            } else if (!$students) {
                echo $OUTPUT->notification(get_string('nostudentsyet'));
            } else if ($currentgroup && !$groupstudents) {
                echo $OUTPUT->notification(get_string('nostudentsingroup'));
            }

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $students && (!$currentgroup || $groupstudents);
        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {

            list($fields, $from, $where, $params) = $table->base_sql($allowed);

            $table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

            $table->set_sql($fields, $from, $where, $params);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = cquiz_report_highlighting_grading_method(
                        $cquiz, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="cquizattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = array();
            $headers = array();

            if (!$table->is_downloading() && $options->checkboxcolumn) {
                $columns[] = 'checkbox';
                $headers[] = null;
            }

            $this->add_user_columns($table, $columns, $headers);
            $this->add_state_column($columns, $headers);

            if ($table->is_downloading()) {
                $this->add_time_columns($columns, $headers);
            }

            $this->add_grade_columns($cquiz, $options->usercanseegrades, $columns, $headers);

            foreach ($questions as $id => $question) {
                if ($options->showqtext) {
                    $columns[] = 'question' . $id;
                    $headers[] = get_string('questionx', 'question', $question->number);
                }
                if ($options->showresponses) {
                    $columns[] = 'response' . $id;
                    $headers[] = get_string('responsex', 'cquiz_responses', $question->number);
                }
                if ($options->showright) {
                    $columns[] = 'right' . $id;
                    $headers[] = get_string('rightanswerx', 'cquiz_responses', $question->number);
                }
            }

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->sortable(true, 'uniqueid');

            // Set up the table.
            $table->define_baseurl($options->get_url());

            $this->configure_user_columns($table);

            $table->no_sorting('feedbacktext');
            $table->column_class('sumgrades', 'bold');

            $table->set_attribute('id', 'attempts');

            $table->collapsible(true);

            $table->out($options->pagesize, true);
        }
        return true;
    }
}
