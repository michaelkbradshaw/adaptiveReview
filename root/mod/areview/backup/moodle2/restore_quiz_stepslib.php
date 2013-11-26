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
 * @package    moodlecore
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Structure step to restore one areview activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_areview_activity_structure_step extends restore_questions_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $areview = new restore_path_element('areview', '/activity/areview');
        $paths[] = $areview;

        // A chance for access subplugings to set up their areview data.
        $this->add_subplugin_structure('areviewaccess', $areview);

        $paths[] = new restore_path_element('areview_question_instance',
                '/activity/areview/question_instances/question_instance');
        $paths[] = new restore_path_element('areview_feedback', '/activity/areview/feedbacks/feedback');
        $paths[] = new restore_path_element('areview_override', '/activity/areview/overrides/override');

        if ($userinfo) {
            $paths[] = new restore_path_element('areview_grade', '/activity/areview/grades/grade');

            if ($this->task->get_old_moduleversion() > 2011010100) {
                // Restoring from a version 2.1 dev or later.
                // Process the new-style attempt data.
                $areviewattempt = new restore_path_element('areview_attempt',
                        '/activity/areview/attempts/attempt');
                $paths[] = $areviewattempt;

                // Add states and sessions.
                $this->add_question_usages($areviewattempt, $paths);

                // A chance for access subplugings to set up their attempt data.
                $this->add_subplugin_structure('areviewaccess', $areviewattempt);

            } else {
                // Restoring from a version 2.0.x+ or earlier.
                // Upgrade the legacy attempt data.
                $areviewattempt = new restore_path_element('areview_attempt_legacy',
                        '/activity/areview/attempts/attempt',
                        true);
                $paths[] = $areviewattempt;
                $this->add_legacy_question_attempt_data($areviewattempt, $paths);
            }
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_areview($data) {
        global $CFG, $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Needed by {@link process_areview_attempt_legacy}.
        $this->oldareviewlayout = $data->questions;
        $data->questions = $this->questions_recode_layout($data->questions);

        // The setting areview->attempts can come both in data->attempts and
        // data->attempts_number, handle both. MDL-26229.
        if (isset($data->attempts_number)) {
            $data->attempts = $data->attempts_number;
            unset($data->attempts_number);
        }

        // The old optionflags and penaltyscheme from 2.0 need to be mapped to
        // the new preferredbehaviour. See MDL-20636.
        if (!isset($data->preferredbehaviour)) {
            if (empty($data->optionflags)) {
                $data->preferredbehaviour = 'deferredfeedback';
            } else if (empty($data->penaltyscheme)) {
                $data->preferredbehaviour = 'adaptivenopenalty';
            } else {
                $data->preferredbehaviour = 'adaptive';
            }
            unset($data->optionflags);
            unset($data->penaltyscheme);
        }

        // The old review column from 2.0 need to be split into the seven new
        // review columns. See MDL-20636.
        if (isset($data->review)) {
            require_once($CFG->dirroot . '/mod/areview/locallib.php');

            if (!defined('areview_OLD_IMMEDIATELY')) {
                define('areview_OLD_IMMEDIATELY', 0x3c003f);
                define('areview_OLD_OPEN',        0x3c00fc0);
                define('areview_OLD_CLOSED',      0x3c03f000);

                define('areview_OLD_RESPONSES',        1*0x1041);
                define('areview_OLD_SCORES',           2*0x1041);
                define('areview_OLD_FEEDBACK',         4*0x1041);
                define('areview_OLD_ANSWERS',          8*0x1041);
                define('areview_OLD_SOLUTIONS',       16*0x1041);
                define('areview_OLD_GENERALFEEDBACK', 32*0x1041);
                define('areview_OLD_OVERALLFEEDBACK',  1*0x4440000);
            }

            $oldreview = $data->review;

            $data->reviewattempt =
                    mod_areview_display_options::DURING |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_RESPONSES ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_RESPONSES ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_RESPONSES ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewcorrectness =
                    mod_areview_display_options::DURING |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_SCORES ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_SCORES ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_SCORES ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewmarks =
                    mod_areview_display_options::DURING |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_SCORES ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_SCORES ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_SCORES ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewspecificfeedback =
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_FEEDBACK ?
                            mod_areview_display_options::DURING : 0) |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_FEEDBACK ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_FEEDBACK ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_FEEDBACK ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewgeneralfeedback =
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_GENERALFEEDBACK ?
                            mod_areview_display_options::DURING : 0) |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_GENERALFEEDBACK ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_GENERALFEEDBACK ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_GENERALFEEDBACK ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewrightanswer =
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_ANSWERS ?
                            mod_areview_display_options::DURING : 0) |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_ANSWERS ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_ANSWERS ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_ANSWERS ?
                            mod_areview_display_options::AFTER_CLOSE : 0);

            $data->reviewoverallfeedback =
                    0 |
                    ($oldreview & areview_OLD_IMMEDIATELY & areview_OLD_OVERALLFEEDBACK ?
                            mod_areview_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & areview_OLD_OPEN & areview_OLD_OVERALLFEEDBACK ?
                            mod_areview_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & areview_OLD_CLOSED & areview_OLD_OVERALLFEEDBACK ?
                            mod_areview_display_options::AFTER_CLOSE : 0);
        }

        // The old popup column from from <= 2.1 need to be mapped to
        // the new browsersecurity. See MDL-29627.
        if (!isset($data->browsersecurity)) {
            if (empty($data->popup)) {
                $data->browsersecurity = '-';
            } else if ($data->popup == 1) {
                $data->browsersecurity = 'securewindow';
            } else if ($data->popup == 2) {
                $data->browsersecurity = 'safebrowser';
            } else {
                $data->preferredbehaviour = '-';
            }
            unset($data->popup);
        }

        // Insert the areview record.
        $newitemid = $DB->insert_record('areview', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    protected function process_areview_question_instance($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->areview = $this->get_new_parentid('areview');

        $data->question = $this->get_mappingid('question', $data->question);

        $DB->insert_record('areview_question_instances', $data);
    }

    protected function process_areview_feedback($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->areviewid = $this->get_new_parentid('areview');

        $newitemid = $DB->insert_record('areview_feedback', $data);
        $this->set_mapping('areview_feedback', $oldid, $newitemid, true); // Has related files.
    }

    protected function process_areview_override($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Based on userinfo, we'll restore user overides or no.
        $userinfo = $this->get_setting_value('userinfo');

        // Skip user overrides if we are not restoring userinfo.
        if (!$userinfo && !is_null($data->userid)) {
            return;
        }

        $data->areview = $this->get_new_parentid('areview');

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        $newitemid = $DB->insert_record('areview_overrides', $data);

        // Add mapping, restore of logs needs it.
        $this->set_mapping('areview_override', $oldid, $newitemid);
    }

    protected function process_areview_grade($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->areview = $this->get_new_parentid('areview');

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->grade = $data->gradeval;

        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('areview_grades', $data);
    }

    protected function process_areview_attempt($data) {
        $data = (object)$data;

        $data->areview = $this->get_new_parentid('areview');
        $data->attempt = $data->attemptnum;

        $data->userid = $this->get_mappingid('user', $data->userid);

        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $this->apply_date_offset($data->timefinish);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!empty($data->timecheckstate)) {
            $data->timecheckstate = $this->apply_date_offset($data->timecheckstate);
        } else {
            $data->timecheckstate = 0;
        }

        // Deals with up-grading pre-2.3 back-ups to 2.3+.
        if (!isset($data->state)) {
            if ($data->timefinish > 0) {
                $data->state = 'finished';
            } else {
                $data->state = 'inprogress';
            }
        }

        // The data is actually inserted into the database later in inform_new_usage_id.
        $this->currentareviewattempt = clone($data);
    }

    protected function process_areview_attempt_legacy($data) {
        global $DB;

        $this->process_areview_attempt($data);

        $areview = $DB->get_record('areview', array('id' => $this->get_new_parentid('areview')));
        $areview->oldquestions = $this->oldareviewlayout;
        $this->process_legacy_areview_attempt_data($data, $areview);
    }

    protected function inform_new_usage_id($newusageid) {
        global $DB;

        $data = $this->currentareviewattempt;

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('areview_attempts', $data);

        // Save areview_attempt->id mapping, because logs use it.
        $this->set_mapping('areview_attempt', $oldid, $newitemid, false);
    }

    protected function after_execute() {
        parent::after_execute();
        // Add areview related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_areview', 'intro', null);
        // Add feedback related files, matching by itemname = 'areview_feedback'.
        $this->add_related_files('mod_areview', 'feedback', 'areview_feedback');
    }
}
