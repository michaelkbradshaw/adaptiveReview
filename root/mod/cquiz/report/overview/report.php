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
 * This file defines the cquiz overview report class.
 *
 * @package   cquiz_overview
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/cquiz/report/overview/overview_options.php');
require_once($CFG->dirroot . '/mod/cquiz/report/overview/overview_form.php');
require_once($CFG->dirroot . '/mod/cquiz/report/overview/overview_table.php');


/**
 * Quiz report subclass for the overview (grades) report.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquiz_overview_report extends cquiz_attempts_report {

    public function display($cquiz, $cm, $course) {
        global $CFG, $DB, $OUTPUT, $PAGE;

        list($currentgroup, $students, $groupstudents, $allowed) =
                $this->init('overview', 'cquiz_overview_settings_form', $cquiz, $cm, $course);
        $options = new cquiz_overview_options('overview', $cquiz, $cm, $course);

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
        $table = new cquiz_overview_table($cquiz, $this->context, $this->qmsubselect,
                $options, $groupstudents, $students, $questions, $options->get_url());
        $filename = cquiz_report_download_filename(get_string('overviewfilename', 'cquiz_overview'),
                $courseshortname, $cquiz->name);
        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($cquiz->name, true));
        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->course = $course; // Hack to make this available in process_actions.
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
            // Construct the SQL.
            $fields = $DB->sql_concat('u.id', "'#'", 'COALESCE(cquiza.attempt, 0)') .
                    ' AS uniqueid, ';
            if ($this->qmsubselect) {
                $fields .=
                    "(CASE " .
                    "   WHEN {$this->qmsubselect} THEN 1" .
                    "   ELSE 0 " .
                    "END) AS gradedattempt, ";
            }

            list($fields, $from, $where, $params) = $table->base_sql($allowed);

            $table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

            // Test to see if there are any regraded attempts to be listed.
            $fields .= ", COALESCE((
                                SELECT MAX(qqr.regraded)
                                  FROM {cquiz_overview_regrades} qqr
                                 WHERE qqr.questionusageid = cquiza.uniqueid
                          ), -1) AS regraded";
            if ($options->onlyregraded) {
                $where .= " AND COALESCE((
                                    SELECT MAX(qqr.regraded)
                                      FROM {cquiz_overview_regrades} qqr
                                     WHERE qqr.questionusageid = cquiza.uniqueid
                                ), -1) <> -1";
            }
            $table->set_sql($fields, $from, $where, $params);

            if (!$table->is_downloading()) {
                // Output the regrade buttons.
                if (has_capability('mod/cquiz:regrade', $this->context)) {
                    $regradesneeded = $this->count_question_attempts_needing_regrade(
                            $cquiz, $groupstudents);
                    if ($currentgroup) {
                        $a= new stdClass();
                        $a->groupname = groups_get_group_name($currentgroup);
                        $a->coursestudents = get_string('participants');
                        $a->countregradeneeded = $regradesneeded;
                        $regradealldrydolabel =
                                get_string('regradealldrydogroup', 'cquiz_overview', $a);
                        $regradealldrylabel =
                                get_string('regradealldrygroup', 'cquiz_overview', $a);
                        $regradealllabel =
                                get_string('regradeallgroup', 'cquiz_overview', $a);
                    } else {
                        $regradealldrydolabel =
                                get_string('regradealldrydo', 'cquiz_overview', $regradesneeded);
                        $regradealldrylabel =
                                get_string('regradealldry', 'cquiz_overview');
                        $regradealllabel =
                                get_string('regradeall', 'cquiz_overview');
                    }
                    $displayurl = new moodle_url($options->get_url(), array('sesskey' => sesskey()));
                    echo '<div class="mdl-align">';
                    echo '<form action="'.$displayurl->out_omit_querystring().'">';
                    echo '<div>';
                    echo html_writer::input_hidden_params($displayurl);
                    echo '<input type="submit" name="regradeall" value="'.$regradealllabel.'"/>';
                    echo '<input type="submit" name="regradealldry" value="' .
                            $regradealldrylabel . '"/>';
                    if ($regradesneeded) {
                        echo '<input type="submit" name="regradealldrydo" value="' .
                                $regradealldrydolabel . '"/>';
                    }
                    echo '</div>';
                    echo '</form>';
                    echo '</div>';
                }
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
            $this->add_time_columns($columns, $headers);

            $this->add_grade_columns($cquiz, $options->usercanseegrades, $columns, $headers, false);

            if (!$table->is_downloading() && has_capability('mod/cquiz:regrade', $this->context) &&
                    $this->has_regraded_questions($from, $where, $params)) {
                $columns[] = 'regraded';
                $headers[] = get_string('regrade', 'cquiz_overview');
            }

            if ($options->slotmarks) {
                foreach ($questions as $slot => $question) {
                    // Ignore questions of zero length.
                    $columns[] = 'qsgrade' . $slot;
                    $header = get_string('qbrief', 'cquiz', $question->number);
                    if (!$table->is_downloading()) {
                        $header .= '<br />';
                    } else {
                        $header .= ' ';
                    }
                    $header .= '/' . cquiz_rescale_grade($question->maxmark, $cquiz, 'question');
                    $headers[] = $header;
                }
            }

            $this->set_up_table_columns($table, $columns, $headers, $this->get_base_url(), $options, false);
            $table->set_attribute('class', 'generaltable generalbox grades');

            $table->out($options->pagesize, true);
        }

        if (!$table->is_downloading() && $options->usercanseegrades) {
            $output = $PAGE->get_renderer('mod_cquiz');
            if ($currentgroup && $groupstudents) {
                list($usql, $params) = $DB->get_in_or_equal($groupstudents);
                $params[] = $cquiz->id;
                if ($DB->record_exists_select('cquiz_grades', "userid $usql AND cquiz = ?",
                        $params)) {
                    $imageurl = new moodle_url('/mod/cquiz/report/overview/overviewgraph.php',
                            array('id' => $cquiz->id, 'groupid' => $currentgroup));
                    $graphname = get_string('overviewreportgraphgroup', 'cquiz_overview',
                            groups_get_group_name($currentgroup));
                    echo $output->graph($imageurl, $graphname);
                }
            }

            if ($DB->record_exists('cquiz_grades', array('cquiz'=> $cquiz->id))) {
                $imageurl = new moodle_url('/mod/cquiz/report/overview/overviewgraph.php',
                        array('id' => $cquiz->id));
                $graphname = get_string('overviewreportgraph', 'cquiz_overview');
                echo $output->graph($imageurl, $graphname);
            }
        }
        return true;
    }

    protected function process_actions($cquiz, $cm, $currentgroup, $groupstudents, $allowed, $redirecturl) {
        parent::process_actions($cquiz, $cm, $currentgroup, $groupstudents, $allowed, $redirecturl);

        if (empty($currentgroup) || $groupstudents) {
            if (optional_param('regrade', 0, PARAM_BOOL) && confirm_sesskey()) {
                if ($attemptids = optional_param_array('attemptid', array(), PARAM_INT)) {
                    $this->start_regrade($cquiz, $cm);
                    $this->regrade_attempts($cquiz, false, $groupstudents, $attemptids);
                    $this->finish_regrade($redirecturl);
                }
            }
        }

        if (optional_param('regradeall', 0, PARAM_BOOL) && confirm_sesskey()) {
            $this->start_regrade($cquiz, $cm);
            $this->regrade_attempts($cquiz, false, $groupstudents);
            $this->finish_regrade($redirecturl);

        } else if (optional_param('regradealldry', 0, PARAM_BOOL) && confirm_sesskey()) {
            $this->start_regrade($cquiz, $cm);
            $this->regrade_attempts($cquiz, true, $groupstudents);
            $this->finish_regrade($redirecturl);

        } else if (optional_param('regradealldrydo', 0, PARAM_BOOL) && confirm_sesskey()) {
            $this->start_regrade($cquiz, $cm);
            $this->regrade_attempts_needing_it($cquiz, $groupstudents);
            $this->finish_regrade($redirecturl);
        }
    }

    /**
     * Check necessary capabilities, and start the display of the regrade progress page.
     * @param object $cquiz the cquiz settings.
     * @param object $cm the cm object for the cquiz.
     */
    protected function start_regrade($cquiz, $cm) {
        global $OUTPUT, $PAGE;
        require_capability('mod/cquiz:regrade', $this->context);
        $this->print_header_and_tabs($cm, $this->course, $cquiz, $this->mode);
    }

    /**
     * Finish displaying the regrade progress page.
     * @param moodle_url $nexturl where to send the user after the regrade.
     * @uses exit. This method never returns.
     */
    protected function finish_regrade($nexturl) {
        global $OUTPUT, $PAGE;
        echo $OUTPUT->heading(get_string('regradecomplete', 'cquiz_overview'));
        echo $OUTPUT->continue_button($nexturl);
        echo $OUTPUT->footer();
        die();
    }

    /**
     * Unlock the session and allow the regrading process to run in the background.
     */
    protected function unlock_session() {
        session_get_instance()->write_close();
        ignore_user_abort(true);
    }

    /**
     * Regrade a particular cquiz attempt. Either for real ($dryrun = false), or
     * as a pretend regrade to see which fractions would change. The outcome is
     * stored in the cquiz_overview_regrades table.
     *
     * Note, $attempt is not upgraded in the database. The caller needs to do that.
     * However, $attempt->sumgrades is updated, if this is not a dry run.
     *
     * @param object $attempt the cquiz attempt to regrade.
     * @param bool $dryrun if true, do a pretend regrade, otherwise do it for real.
     * @param array $slots if null, regrade all questions, otherwise, just regrade
     *      the quetsions with those slots.
     */
    protected function regrade_attempt($attempt, $dryrun = false, $slots = null) {
        global $DB;
        // Need more time for a cquiz with many questions.
        set_time_limit(300);

        $transaction = $DB->start_delegated_transaction();

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        if (is_null($slots)) {
            $slots = $quba->get_slots();
        }

        $finished = $attempt->state == cquiz_attempt::FINISHED;
        foreach ($slots as $slot) {
            $qqr = new stdClass();
            $qqr->oldfraction = $quba->get_question_fraction($slot);

            $quba->regrade_question($slot, $finished);

            $qqr->newfraction = $quba->get_question_fraction($slot);

            if (abs($qqr->oldfraction - $qqr->newfraction) > 1e-7) {
                $qqr->questionusageid = $quba->get_id();
                $qqr->slot = $slot;
                $qqr->regraded = empty($dryrun);
                $qqr->timemodified = time();
                $DB->insert_record('cquiz_overview_regrades', $qqr, false);
            }
        }

        if (!$dryrun) {
            question_engine::save_questions_usage_by_activity($quba);
        }

        $transaction->allow_commit();

        // Really, PHP should not need this hint, but without this, we just run out of memory.
        $quba = null;
        $transaction = null;
        gc_collect_cycles();
    }

    /**
     * Regrade attempts for this cquiz, exactly which attempts are regraded is
     * controlled by the parameters.
     * @param object $cquiz the cquiz settings.
     * @param bool $dryrun if true, do a pretend regrade, otherwise do it for real.
     * @param array $groupstudents blank for all attempts, otherwise regrade attempts
     * for these users.
     * @param array $attemptids blank for all attempts, otherwise only regrade
     * attempts whose id is in this list.
     */
    protected function regrade_attempts($cquiz, $dryrun = false,
            $groupstudents = array(), $attemptids = array()) {
        global $DB;
        $this->unlock_session();

        $where = "cquiz = ? AND preview = 0";
        $params = array($cquiz->id);

        if ($groupstudents) {
            list($usql, $uparams) = $DB->get_in_or_equal($groupstudents);
            $where .= " AND userid $usql";
            $params = array_merge($params, $uparams);
        }

        if ($attemptids) {
            list($asql, $aparams) = $DB->get_in_or_equal($attemptids);
            $where .= " AND id $asql";
            $params = array_merge($params, $aparams);
        }

        $attempts = $DB->get_records_select('cquiz_attempts', $where, $params);
        if (!$attempts) {
            return;
        }

        $this->clear_regrade_table($cquiz, $groupstudents);

        $progressbar = new progress_bar('cquiz_overview_regrade', 500, true);
        $a = array(
            'count' => count($attempts),
            'done'  => 0,
        );
        foreach ($attempts as $attempt) {
            $this->regrade_attempt($attempt, $dryrun);
            $a['done']++;
            $progressbar->update($a['done'], $a['count'],
                    get_string('regradingattemptxofy', 'cquiz_overview', $a));
        }

        if (!$dryrun) {
            $this->update_overall_grades($cquiz);
        }
    }

    /**
     * Regrade those questions in those attempts that are marked as needing regrading
     * in the cquiz_overview_regrades table.
     * @param object $cquiz the cquiz settings.
     * @param array $groupstudents blank for all attempts, otherwise regrade attempts
     * for these users.
     */
    protected function regrade_attempts_needing_it($cquiz, $groupstudents) {
        global $DB;
        $this->unlock_session();

        $where = "cquiza.cquiz = ? AND cquiza.preview = 0 AND qqr.regraded = 0";
        $params = array($cquiz->id);

        // Fetch all attempts that need regrading.
        if ($groupstudents) {
            list($usql, $uparams) = $DB->get_in_or_equal($groupstudents);
            $where .= " AND cquiza.userid $usql";
            $params += $uparams;
        }

        $toregrade = $DB->get_records_sql("
                SELECT cquiza.uniqueid, qqr.slot
                FROM {cquiz_attempts} cquiza
                JOIN {cquiz_overview_regrades} qqr ON qqr.questionusageid = cquiza.uniqueid
                WHERE $where", $params);

        if (!$toregrade) {
            return;
        }

        $attemptquestions = array();
        foreach ($toregrade as $row) {
            $attemptquestions[$row->uniqueid][] = $row->slot;
        }
        $attempts = $DB->get_records_list('cquiz_attempts', 'uniqueid',
                array_keys($attemptquestions));

        $this->clear_regrade_table($cquiz, $groupstudents);

        $progressbar = new progress_bar('cquiz_overview_regrade', 500, true);
        $a = array(
            'count' => count($attempts),
            'done'  => 0,
        );
        foreach ($attempts as $attempt) {
            $this->regrade_attempt($attempt, false, $attemptquestions[$attempt->uniqueid]);
            $a['done']++;
            $progressbar->update($a['done'], $a['count'],
                    get_string('regradingattemptxofy', 'cquiz_overview', $a));
        }

        $this->update_overall_grades($cquiz);
    }

    /**
     * Count the number of attempts in need of a regrade.
     * @param object $cquiz the cquiz settings.
     * @param array $groupstudents user ids. If this is given, only data relating
     * to these users is cleared.
     */
    protected function count_question_attempts_needing_regrade($cquiz, $groupstudents) {
        global $DB;

        $usertest = '';
        $params = array();
        if ($groupstudents) {
            list($usql, $params) = $DB->get_in_or_equal($groupstudents);
            $usertest = "cquiza.userid $usql AND ";
        }

        $params[] = $cquiz->id;
        $sql = "SELECT COUNT(DISTINCT cquiza.id)
                FROM {cquiz_attempts} cquiza
                JOIN {cquiz_overview_regrades} qqr ON cquiza.uniqueid = qqr.questionusageid
                WHERE
                    $usertest
                    cquiza.cquiz = ? AND
                    cquiza.preview = 0 AND
                    qqr.regraded = 0";
        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Are there any pending regrades in the table we are going to show?
     * @param string $from tables used by the main query.
     * @param string $where where clause used by the main query.
     * @param array $params required by the SQL.
     * @return bool whether there are pending regrades.
     */
    protected function has_regraded_questions($from, $where, $params) {
        global $DB;
        $qubaids = new qubaid_join($from, 'uniqueid', $where, $params);
        return $DB->record_exists_select('cquiz_overview_regrades',
                'questionusageid ' . $qubaids->usage_id_in(),
                $qubaids->usage_id_in_params());
    }

    /**
     * Remove all information about pending/complete regrades from the database.
     * @param object $cquiz the cquiz settings.
     * @param array $groupstudents user ids. If this is given, only data relating
     * to these users is cleared.
     */
    protected function clear_regrade_table($cquiz, $groupstudents) {
        global $DB;

        // Fetch all attempts that need regrading.
        $where = '';
        $params = array();
        if ($groupstudents) {
            list($usql, $params) = $DB->get_in_or_equal($groupstudents);
            $where = "userid $usql AND ";
        }

        $params[] = $cquiz->id;
        $DB->delete_records_select('cquiz_overview_regrades',
                "questionusageid IN (
                    SELECT uniqueid
                    FROM {cquiz_attempts}
                    WHERE $where cquiz = ?
                )", $params);
    }

    /**
     * Update the final grades for all attempts. This method is used following
     * a regrade.
     * @param object $cquiz the cquiz settings.
     * @param array $userids only update scores for these userids.
     * @param array $attemptids attemptids only update scores for these attempt ids.
     */
    protected function update_overall_grades($cquiz) {
        cquiz_update_all_attempt_sumgrades($cquiz);
        cquiz_update_all_final_grades($cquiz);
        cquiz_update_grades($cquiz);
    }
}
