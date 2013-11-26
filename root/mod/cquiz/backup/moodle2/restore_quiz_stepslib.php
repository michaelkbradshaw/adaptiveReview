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
 * Structure step to restore one cquiz activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_cquiz_activity_structure_step extends restore_questions_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $cquiz = new restore_path_element('cquiz', '/activity/cquiz');
        $paths[] = $cquiz;

        // A chance for access subplugings to set up their cquiz data.
        $this->add_subplugin_structure('cquizaccess', $cquiz);

        $paths[] = new restore_path_element('cquiz_question_instance',
                '/activity/cquiz/question_instances/question_instance');
        $paths[] = new restore_path_element('cquiz_feedback', '/activity/cquiz/feedbacks/feedback');
        $paths[] = new restore_path_element('cquiz_override', '/activity/cquiz/overrides/override');

        if ($userinfo) {
            $paths[] = new restore_path_element('cquiz_grade', '/activity/cquiz/grades/grade');

            if ($this->task->get_old_moduleversion() > 2011010100) {
                // Restoring from a version 2.1 dev or later.
                // Process the new-style attempt data.
                $cquizattempt = new restore_path_element('cquiz_attempt',
                        '/activity/cquiz/attempts/attempt');
                $paths[] = $cquizattempt;

                // Add states and sessions.
                $this->add_question_usages($cquizattempt, $paths);

                // A chance for access subplugings to set up their attempt data.
                $this->add_subplugin_structure('cquizaccess', $cquizattempt);

            } else {
                // Restoring from a version 2.0.x+ or earlier.
                // Upgrade the legacy attempt data.
                $cquizattempt = new restore_path_element('cquiz_attempt_legacy',
                        '/activity/cquiz/attempts/attempt',
                        true);
                $paths[] = $cquizattempt;
                $this->add_legacy_question_attempt_data($cquizattempt, $paths);
            }
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_cquiz($data) {
        global $CFG, $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Needed by {@link process_cquiz_attempt_legacy}.
        $this->oldcquizlayout = $data->questions;
        $data->questions = $this->questions_recode_layout($data->questions);

        // The setting cquiz->attempts can come both in data->attempts and
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
            require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

            if (!defined('CQUIZ_OLD_IMMEDIATELY')) {
                define('CQUIZ_OLD_IMMEDIATELY', 0x3c003f);
                define('CQUIZ_OLD_OPEN',        0x3c00fc0);
                define('CQUIZ_OLD_CLOSED',      0x3c03f000);

                define('CQUIZ_OLD_RESPONSES',        1*0x1041);
                define('CQUIZ_OLD_SCORES',           2*0x1041);
                define('CQUIZ_OLD_FEEDBACK',         4*0x1041);
                define('CQUIZ_OLD_ANSWERS',          8*0x1041);
                define('CQUIZ_OLD_SOLUTIONS',       16*0x1041);
                define('CQUIZ_OLD_GENERALFEEDBACK', 32*0x1041);
                define('CQUIZ_OLD_OVERALLFEEDBACK',  1*0x4440000);
            }

            $oldreview = $data->review;

            $data->reviewattempt =
                    mod_cquiz_display_options::DURING |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_RESPONSES ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_RESPONSES ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_RESPONSES ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewcorrectness =
                    mod_cquiz_display_options::DURING |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewmarks =
                    mod_cquiz_display_options::DURING |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_SCORES ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewspecificfeedback =
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_FEEDBACK ?
                            mod_cquiz_display_options::DURING : 0) |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_FEEDBACK ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_FEEDBACK ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_FEEDBACK ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewgeneralfeedback =
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_GENERALFEEDBACK ?
                            mod_cquiz_display_options::DURING : 0) |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_GENERALFEEDBACK ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_GENERALFEEDBACK ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_GENERALFEEDBACK ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewrightanswer =
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_ANSWERS ?
                            mod_cquiz_display_options::DURING : 0) |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_ANSWERS ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_ANSWERS ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_ANSWERS ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);

            $data->reviewoverallfeedback =
                    0 |
                    ($oldreview & CQUIZ_OLD_IMMEDIATELY & CQUIZ_OLD_OVERALLFEEDBACK ?
                            mod_cquiz_display_options::IMMEDIATELY_AFTER : 0) |
                    ($oldreview & CQUIZ_OLD_OPEN & CQUIZ_OLD_OVERALLFEEDBACK ?
                            mod_cquiz_display_options::LATER_WHILE_OPEN : 0) |
                    ($oldreview & CQUIZ_OLD_CLOSED & CQUIZ_OLD_OVERALLFEEDBACK ?
                            mod_cquiz_display_options::AFTER_CLOSE : 0);
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

        // Insert the cquiz record.
        $newitemid = $DB->insert_record('cquiz', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    protected function process_cquiz_question_instance($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->cquiz = $this->get_new_parentid('cquiz');

        $data->question = $this->get_mappingid('question', $data->question);

        $DB->insert_record('cquiz_question_instances', $data);
    }

    protected function process_cquiz_feedback($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->cquizid = $this->get_new_parentid('cquiz');

        $newitemid = $DB->insert_record('cquiz_feedback', $data);
        $this->set_mapping('cquiz_feedback', $oldid, $newitemid, true); // Has related files.
    }

    protected function process_cquiz_override($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        // Based on userinfo, we'll restore user overides or no.
        $userinfo = $this->get_setting_value('userinfo');

        // Skip user overrides if we are not restoring userinfo.
        if (!$userinfo && !is_null($data->userid)) {
            return;
        }

        $data->cquiz = $this->get_new_parentid('cquiz');

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        $newitemid = $DB->insert_record('cquiz_overrides', $data);

        // Add mapping, restore of logs needs it.
        $this->set_mapping('cquiz_override', $oldid, $newitemid);
    }

    protected function process_cquiz_grade($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->cquiz = $this->get_new_parentid('cquiz');

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->grade = $data->gradeval;

        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('cquiz_grades', $data);
    }

    protected function process_cquiz_attempt($data) {
        $data = (object)$data;

        $data->cquiz = $this->get_new_parentid('cquiz');
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
        $this->currentcquizattempt = clone($data);
    }

    protected function process_cquiz_attempt_legacy($data) {
        global $DB;

        $this->process_cquiz_attempt($data);

        $cquiz = $DB->get_record('cquiz', array('id' => $this->get_new_parentid('cquiz')));
        $cquiz->oldquestions = $this->oldcquizlayout;
        $this->process_legacy_cquiz_attempt_data($data, $cquiz);
    }

    protected function inform_new_usage_id($newusageid) {
        global $DB;

        $data = $this->currentcquizattempt;

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('cquiz_attempts', $data);

        // Save cquiz_attempt->id mapping, because logs use it.
        $this->set_mapping('cquiz_attempt', $oldid, $newitemid, false);
    }

    protected function after_execute() {
        parent::after_execute();
        // Add cquiz related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_cquiz', 'intro', null);
        // Add feedback related files, matching by itemname = 'cquiz_feedback'.
        $this->add_related_files('mod_cquiz', 'feedback', 'cquiz_feedback');
    }
}
