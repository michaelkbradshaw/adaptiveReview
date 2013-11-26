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
 * Library code used by areview cron.
 *
 * @package   mod_areview
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/locallib.php');


/**
 * This class holds all the code for automatically updating all attempts that have
 * gone over their time limit.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_areview_overdue_attempt_updater {

    /**
     * Do the processing required.
     * @param int $timenow the time to consider as 'now' during the processing.
     * @param int $processto only process attempt with timecheckstate longer ago than this.
     * @return array with two elements, the number of attempt considered, and how many different areviewzes that was.
     */
    public function update_overdue_attempts($timenow, $processto) {
        global $DB;

        $attemptstoprocess = $this->get_list_of_overdue_attempts($processto);

        $course = null;
        $areview = null;
        $cm = null;

        $count = 0;
        $areviewcount = 0;
        foreach ($attemptstoprocess as $attempt) {
            try {

                // If we have moved on to a different areview, fetch the new data.
                if (!$areview || $attempt->areview != $areview->id) {
                    $areview = $DB->get_record('areview', array('id' => $attempt->areview), '*', MUST_EXIST);
                    $cm = get_coursemodule_from_instance('areview', $attempt->areview);
                    $areviewcount += 1;
                }

                // If we have moved on to a different course, fetch the new data.
                if (!$course || $course->id != $areview->course) {
                    $course = $DB->get_record('course', array('id' => $areview->course), '*', MUST_EXIST);
                }

                // Make a specialised version of the areview settings, with the relevant overrides.
                $areviewforuser = clone($areview);
                $areviewforuser->timeclose = $attempt->usertimeclose;
                $areviewforuser->timelimit = $attempt->usertimelimit;

                // Trigger any transitions that are required.
                $attemptobj = new areview_attempt($attempt, $areviewforuser, $cm, $course);
                $attemptobj->handle_if_time_expired($timenow, false);
                $count += 1;

            } catch (moodle_exception $e) {
                // If an error occurs while processing one attempt, don't let that kill cron.
                mtrace("Error while processing attempt {$attempt->id} at {$attempt->areview} areview:");
                mtrace($e->getMessage());
                mtrace($e->getTraceAsString());
            }
        }

        $attemptstoprocess->close();
        return array($count, $areviewcount);
    }

    /**
     * @return moodle_recordset of areview_attempts that need to be processed because time has
     *     passed. The array is sorted by courseid then areviewid.
     */
    public function get_list_of_overdue_attempts($processto) {
        global $DB;


        // SQL to compute timeclose and timelimit for each attempt:
        $areviewausersql = areview_get_attempt_usertime_sql(
                "iareviewa.state IN ('inprogress', 'overdue') AND iareviewa.timecheckstate <= :iprocessto");

        // This query should have all the areview_attempts columns.
        return $DB->get_recordset_sql("
         SELECT areviewa.*,
                areviewauser.usertimeclose,
                areviewauser.usertimelimit

           FROM {areview_attempts} areviewa
           JOIN {areview} areview ON areview.id = areviewa.areview
           JOIN ( $areviewausersql ) areviewauser ON areviewauser.id = areviewa.id

          WHERE areviewa.state IN ('inprogress', 'overdue')
            AND areviewa.timecheckstate <= :processto
       ORDER BY areview.course, areviewa.areview",

                array('processto' => $processto, 'iprocessto' => $processto));
    }
}
