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
 * This file defines the areview manual grading report class.
 *
 * @package   areview_grading
 * @copyright 2006 Gustav Delius
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/report/grading/gradingsettings_form.php');


/**
 * Quiz report to help teachers manually grade questions that need it.
 *
 * This report basically provides two screens:
 * - List question that might need manual grading (or optionally all questions).
 * - Provide an efficient UI to grade all attempts at a particular question.
 *
 * @copyright 2006 Gustav Delius
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class areview_grading_report extends areview_default_report {
    const DEFAULT_PAGE_SIZE = 5;
    const DEFAULT_ORDER = 'random';

    protected $viewoptions = array();
    protected $questions;
    protected $cm;
    protected $areview;
    protected $context;

    public function display($areview, $cm, $course) {
        global $CFG, $DB, $PAGE;

        $this->areview = $areview;
        $this->cm = $cm;
        $this->course = $course;

        // Get the URL options.
        $slot = optional_param('slot', null, PARAM_INT);
        $questionid = optional_param('qid', null, PARAM_INT);
        $grade = optional_param('grade', null, PARAM_ALPHA);

        $includeauto = optional_param('includeauto', false, PARAM_BOOL);
        if (!in_array($grade, array('all', 'needsgrading', 'autograded', 'manuallygraded'))) {
            $grade = null;
        }
        $pagesize = optional_param('pagesize', self::DEFAULT_PAGE_SIZE, PARAM_INT);
        $page = optional_param('page', 0, PARAM_INT);
        $order = optional_param('order', self::DEFAULT_ORDER, PARAM_ALPHA);

        // Assemble the options requried to reload this page.
        $optparams = array('includeauto', 'page');
        foreach ($optparams as $param) {
            if ($$param) {
                $this->viewoptions[$param] = $$param;
            }
        }
        if ($pagesize != self::DEFAULT_PAGE_SIZE) {
            $this->viewoptions['pagesize'] = $pagesize;
        }
        if ($order != self::DEFAULT_ORDER) {
            $this->viewoptions['order'] = $order;
        }

        // Check permissions.
        $this->context = context_module::instance($cm->id);
        require_capability('mod/areview:grade', $this->context);
        $shownames = has_capability('areview/grading:viewstudentnames', $this->context);
        $showidnumbers = has_capability('areview/grading:viewidnumber', $this->context);

        // Validate order.
        if (!in_array($order, array('random', 'date', 'student', 'idnumber'))) {
            $order = self::DEFAULT_ORDER;
        } else if (!$shownames && $order == 'student') {
            $order = self::DEFAULT_ORDER;
        } else if (!$showidnumbers && $order == 'idnumber') {
            $order = self::DEFAULT_ORDER;
        }
        if ($order == 'random') {
            $page = 0;
        }

        // Get the list of questions in this areview.
        $this->questions = areview_report_get_significant_questions($areview);
        if ($slot && !array_key_exists($slot, $this->questions)) {
            throw new moodle_exception('unknownquestion', 'areview_grading');
        }

        // Process any submitted data.
        if ($data = data_submitted() && confirm_sesskey() && $this->validate_submitted_marks()) {
            $this->process_submitted_data();

            redirect($this->grade_question_url($slot, $questionid, $grade, $page + 1));
        }

        // Get the group, and the list of significant users.
        $this->currentgroup = $this->get_current_group($cm, $course, $this->context);
        if ($this->currentgroup == self::NO_GROUPS_ALLOWED) {
            $this->users = array();
        } else {
            $this->users = get_users_by_capability($this->context,
                    array('mod/areview:reviewmyattempts', 'mod/areview:attempt'), '', '', '', '',
                    $this->currentgroup, '', false);
        }

        $questionsinareview = areview_questions_in_areview($areview->questions);
        $counts = null;
        if ($slot && $questionsinareview) {
            // Make sure there is something to do.
            $statecounts = $this->get_question_state_summary(array($slot));
            foreach ($statecounts as $record) {
                if ($record->questionid == $questionid) {
                    $counts = $record;
                    break;
                }
            }
            // If not, redirect back to the list.
            if (!$counts || $counts->$grade == 0) {
                redirect($this->list_questions_url(), get_string('alldoneredirecting', 'areview_grading'));
            }
        }

        // Start output.
        $this->print_header_and_tabs($cm, $course, $areview, 'grading');

        // What sort of page to display?
        if (!$questionsinareview) {
            echo areview_no_questions_message($areview, $cm, $this->context);

        } else if (!$slot) {
            $this->display_index($includeauto);

        } else {
            $this->display_grading_interface($slot, $questionid, $grade,
                    $pagesize, $page, $shownames, $showidnumbers, $order, $counts);
        }
        return true;
    }

    protected function get_qubaids_condition() {
        global $DB;

        $where = "areviewa.areview = :mangrareviewid AND
                areviewa.preview = 0 AND
                areviewa.state = :statefinished";
        $params = array('mangrareviewid' => $this->cm->instance, 'statefinished' => areview_attempt::FINISHED);

        $currentgroup = groups_get_activity_group($this->cm, true);
        if ($currentgroup) {
            $users = get_users_by_capability($this->context,
                    array('mod/areview:reviewmyattempts', 'mod/areview:attempt'), 'u.id, u.id', '', '', '',
                    $currentgroup, '', false);
            if (empty($users)) {
                $where .= ' AND areviewa.userid = 0';
            } else {
                list($usql, $uparam) = $DB->get_in_or_equal(array_keys($users),
                        SQL_PARAMS_NAMED, 'mangru');
                $where .= ' AND areviewa.userid ' . $usql;
                $params += $uparam;
            }
        }

        return new qubaid_join('{areview_attempts} areviewa', 'areviewa.uniqueid', $where, $params);
    }

    protected function load_attempts_by_usage_ids($qubaids) {
        global $DB;

        list($asql, $params) = $DB->get_in_or_equal($qubaids);
        $params[] = areview_attempt::FINISHED;
        $params[] = $this->areview->id;

        $attemptsbyid = $DB->get_records_sql("
                SELECT areviewa.*, u.firstname, u.lastname, u.idnumber
                FROM {areview_attempts} areviewa
                JOIN {user} u ON u.id = areviewa.userid
                WHERE areviewa.uniqueid $asql AND areviewa.state = ? AND areviewa.areview = ?",
                $params);

        $attempts = array();
        foreach ($attemptsbyid as $attempt) {
            $attempts[$attempt->uniqueid] = $attempt;
        }
        return $attempts;
    }

    /**
     * Get the URL of the front page of the report that lists all the questions.
     * @param $includeauto if not given, use the current setting, otherwise,
     *      force a paricular value of includeauto in the URL.
     * @return string the URL.
     */
    protected function base_url() {
        return new moodle_url('/mod/areview/report.php',
                array('id' => $this->cm->id, 'mode' => 'grading'));
    }

    /**
     * Get the URL of the front page of the report that lists all the questions.
     * @param $includeauto if not given, use the current setting, otherwise,
     *      force a paricular value of includeauto in the URL.
     * @return string the URL.
     */
    protected function list_questions_url($includeauto = null) {
        $url = $this->base_url();

        $url->params($this->viewoptions);

        if (!is_null($includeauto)) {
            $url->param('includeauto', $includeauto);
        }

        return $url;
    }

    /**
     * @param int $slot
     * @param int $questionid
     * @param string $grade
     * @param mixed $page = true, link to current page. false = omit page.
     *      number = link to specific page.
     */
    protected function grade_question_url($slot, $questionid, $grade, $page = true) {
        $url = $this->base_url();
        $url->params(array('slot' => $slot, 'qid' => $questionid, 'grade' => $grade));
        $url->params($this->viewoptions);

        $options = $this->viewoptions;
        if (!$page) {
            $url->remove_params('page');
        } else if (is_integer($page)) {
            $url->param('page', $page);
        }

        return $url;
    }

    protected function format_count_for_table($counts, $type, $gradestring) {
        $result = $counts->$type;
        if ($counts->$type > 0) {
            $result .= ' ' . html_writer::link($this->grade_question_url(
                    $counts->slot, $counts->questionid, $type),
                    get_string($gradestring, 'areview_grading'),
                    array('class' => 'gradetheselink'));
        }
        return $result;
    }

    protected function display_index($includeauto) {
        global $OUTPUT;

        if ($groupmode = groups_get_activity_groupmode($this->cm)) {
            // Groups is being used.
            groups_print_activity_menu($this->cm, $this->list_questions_url());
        }

        echo $OUTPUT->heading(get_string('questionsthatneedgrading', 'areview_grading'));
        if ($includeauto) {
            $linktext = get_string('hideautomaticallygraded', 'areview_grading');
        } else {
            $linktext = get_string('alsoshowautomaticallygraded', 'areview_grading');
        }
        echo html_writer::tag('p', html_writer::link($this->list_questions_url(!$includeauto),
                $linktext), array('class' => 'toggleincludeauto'));

        $statecounts = $this->get_question_state_summary(array_keys($this->questions));

        $data = array();
        foreach ($statecounts as $counts) {
            if ($counts->all == 0) {
                continue;
            }
            if (!$includeauto && $counts->needsgrading == 0 && $counts->manuallygraded == 0) {
                continue;
            }

            $row = array();

            $row[] = $this->questions[$counts->slot]->number;

            $row[] = format_string($counts->name);

            $row[] = $this->format_count_for_table($counts, 'needsgrading', 'grade');

            $row[] = $this->format_count_for_table($counts, 'manuallygraded', 'updategrade');

            if ($includeauto) {
                $row[] = $this->format_count_for_table($counts, 'autograded', 'updategrade');
            }

            $row[] = $this->format_count_for_table($counts, 'all', 'gradeall');

            $data[] = $row;
        }

        if (empty($data)) {
            echo $OUTPUT->heading(get_string('nothingfound', 'areview_grading'));
            return;
        }

        $table = new html_table();
        $table->class = 'generaltable';
        $table->id = 'questionstograde';

        $table->head[] = get_string('qno', 'areview_grading');
        $table->head[] = get_string('questionname', 'areview_grading');
        $table->head[] = get_string('tograde', 'areview_grading');
        $table->head[] = get_string('alreadygraded', 'areview_grading');
        if ($includeauto) {
            $table->head[] = get_string('automaticallygraded', 'areview_grading');
        }
        $table->head[] = get_string('total', 'areview_grading');

        $table->data = $data;
        echo html_writer::table($table);
    }

    protected function display_grading_interface($slot, $questionid, $grade,
            $pagesize, $page, $shownames, $showidnumbers, $order, $counts) {
        global $OUTPUT;

        if ($pagesize * $page >= $counts->$grade) {
            $page = 0;
        }

        list($qubaids, $count) = $this->get_usage_ids_where_question_in_state(
                $grade, $slot, $questionid, $order, $page, $pagesize);
        $attempts = $this->load_attempts_by_usage_ids($qubaids);

        // Prepare the form.
        $hidden = array(
            'id' => $this->cm->id,
            'mode' => 'grading',
            'slot' => $slot,
            'qid' => $questionid,
            'page' => $page,
        );
        if (array_key_exists('includeauto', $this->viewoptions)) {
            $hidden['includeauto'] = $this->viewoptions['includeauto'];
        }
        $mform = new areview_grading_settings_form($hidden, $counts, $shownames, $showidnumbers);

        // Tell the form the current settings.
        $settings = new stdClass();
        $settings->grade = $grade;
        $settings->pagesize = $pagesize;
        $settings->order = $order;
        $mform->set_data($settings);

        // Print the heading and form.
        echo question_engine::initialise_js();

        $a = new stdClass();
        $a->number = $this->questions[$slot]->number;
        $a->questionname = format_string($counts->name);
        echo $OUTPUT->heading(get_string('gradingquestionx', 'areview_grading', $a));
        echo html_writer::tag('p', html_writer::link($this->list_questions_url(),
                get_string('backtothelistofquestions', 'areview_grading')),
                array('class' => 'mdl-align'));

        $mform->display();

        // Paging info.
        $a = new stdClass();
        $a->from = $page * $pagesize + 1;
        $a->to = min(($page + 1) * $pagesize, $count);
        $a->of = $count;
        echo $OUTPUT->heading(get_string('gradingattemptsxtoyofz', 'areview_grading', $a), 3);

        if ($count > $pagesize && $order != 'random') {
            echo $OUTPUT->paging_bar($count, $page, $pagesize,
                    $this->grade_question_url($slot, $questionid, $grade, false));
        }

        // Display the form with one section for each attempt.
        $usehtmleditor = can_use_html_editor();
        $sesskey = sesskey();
        $qubaidlist = implode(',', $qubaids);
        echo html_writer::start_tag('form', array('method' => 'post',
                'action' => $this->grade_question_url($slot, $questionid, $grade, $page),
                'class' => 'mform', 'id' => 'manualgradingform')) .
                html_writer::start_tag('div') .
                html_writer::input_hidden_params(new moodle_url('', array(
                'qubaids' => $qubaidlist, 'slots' => $slot, 'sesskey' => $sesskey)));

        foreach ($qubaids as $qubaid) {
            $attempt = $attempts[$qubaid];
            $quba = question_engine::load_questions_usage_by_activity($qubaid);
            $displayoptions = areview_get_review_options($this->areview, $attempt, $this->context);
            $displayoptions->hide_all_feedback();
            $displayoptions->history = question_display_options::HIDDEN;
            $displayoptions->manualcomment = question_display_options::EDITABLE;

            $heading = $this->get_question_heading($attempt, $shownames, $showidnumbers);
            if ($heading) {
                echo $OUTPUT->heading($heading, 4);
            }
            echo $quba->render_question($slot, $displayoptions, $this->questions[$slot]->number);
        }

        echo html_writer::tag('div', html_writer::empty_tag('input', array(
                'type' => 'submit', 'value' => get_string('saveandnext', 'areview_grading'))),
                array('class' => 'mdl-align')) .
                html_writer::end_tag('div') . html_writer::end_tag('form');
    }

    protected function get_question_heading($attempt, $shownames, $showidnumbers) {
        $a = new stdClass();
        $a->attempt = $attempt->attempt;
        $a->fullname = fullname($attempt);
        $a->idnumber = $attempt->idnumber;

        $showidnumbers &= !empty($attempt->idnumber);

        if ($shownames && $showidnumbers) {
            return get_string('gradingattemptwithidnumber', 'areview_grading', $a);
        } else if ($shownames) {
            return get_string('gradingattempt', 'areview_grading', $a);
        } else if ($showidnumbers) {
            $a->fullname = $attempt->idnumber;
            return get_string('gradingattempt', 'areview_grading', $a);
        } else {
            return '';
        }
    }

    protected function validate_submitted_marks() {

        $qubaids = optional_param('qubaids', null, PARAM_SEQUENCE);
        if (!$qubaids) {
            return false;
        }
        $qubaids = clean_param_array(explode(',', $qubaids), PARAM_INT);

        $slots = optional_param('slots', '', PARAM_SEQUENCE);
        if (!$slots) {
            $slots = array();
        } else {
            $slots = explode(',', $slots);
        }

        foreach ($qubaids as $qubaid) {
            foreach ($slots as $slot) {
                if (!question_behaviour::is_manual_grade_in_range($qubaid, $slot)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function process_submitted_data() {
        global $DB;

        $qubaids = optional_param('qubaids', null, PARAM_SEQUENCE);
        if (!$qubaids) {
            return;
        }

        $qubaids = clean_param_array(explode(',', $qubaids), PARAM_INT);
        $attempts = $this->load_attempts_by_usage_ids($qubaids);

        $transaction = $DB->start_delegated_transaction();
        foreach ($qubaids as $qubaid) {
            $attempt = $attempts[$qubaid];
            $attemptobj = new areview_attempt($attempt, $this->areview, $this->cm, $this->course);
            $attemptobj->process_submitted_actions(time());
        }
        $transaction->allow_commit();
    }

    /**
     * Load information about the number of attempts at various questions in each
     * summarystate.
     *
     * The results are returned as an two dimensional array $qubaid => $slot => $dataobject
     *
     * @param array $slots A list of slots for the questions you want to konw about.
     * @return array The array keys are slot,qestionid. The values are objects with
     * fields $slot, $questionid, $inprogress, $name, $needsgrading, $autograded,
     * $manuallygraded and $all.
     */
    protected function get_question_state_summary($slots) {
        $dm = new question_engine_data_mapper();
        return $dm->load_questions_usages_question_state_summary(
                $this->get_qubaids_condition(), $slots);
    }

    /**
     * Get a list of usage ids where the question with slot $slot, and optionally
     * also with question id $questionid, is in summary state $summarystate. Also
     * return the total count of such states.
     *
     * Only a subset of the ids can be returned by using $orderby, $limitfrom and
     * $limitnum. A special value 'random' can be passed as $orderby, in which case
     * $limitfrom is ignored.
     *
     * @param int $slot The slot for the questions you want to konw about.
     * @param int $questionid (optional) Only return attempts that were of this specific question.
     * @param string $summarystate 'all', 'needsgrading', 'autograded' or 'manuallygraded'.
     * @param string $orderby 'random', 'date', 'student' or 'idnumber'.
     * @param int $page implements paging of the results.
     *      Ignored if $orderby = random or $pagesize is null.
     * @param int $pagesize implements paging of the results. null = all.
     */
    protected function get_usage_ids_where_question_in_state($summarystate, $slot,
            $questionid = null, $orderby = 'random', $page = 0, $pagesize = null) {
        global $CFG, $DB;
        $dm = new question_engine_data_mapper();

        if ($pagesize && $orderby != 'random') {
            $limitfrom = $page * $pagesize;
        } else {
            $limitfrom = 0;
        }

        $qubaids = $this->get_qubaids_condition();

        $params = array();
        if ($orderby == 'date') {
            list($statetest, $params) = $dm->in_summary_state_test(
                    'manuallygraded', false, 'mangrstate');
            $orderby = "(
                    SELECT MAX(sortqas.timecreated)
                    FROM {question_attempt_steps} sortqas
                    WHERE sortqas.questionattemptid = qa.id
                        AND sortqas.state $statetest
                    )";
        } else if ($orderby == 'student' || $orderby == 'idnumber') {
            $qubaids->from .= " JOIN {user} u ON areviewa.userid = u.id ";
            if ($orderby == 'student') {
                $orderby = $DB->sql_fullname('u.firstname', 'u.lastname');
            }
        }

        return $dm->load_questions_usages_where_question_in_state($qubaids, $summarystate,
                $slot, $questionid, $orderby, $params, $limitfrom, $pagesize);
    }
}
