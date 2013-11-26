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
 * Library code used by cquiz cron.
 *
 * @package   mod_cquiz
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/locallib.php');


/**
 * This class holds all the code for automatically updating all attempts that have
 * gone over their time limit.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_cquiz_overdue_attempt_updater {

    /**
     * Do the processing required.
     * @param int $timenow the time to consider as 'now' during the processing.
     * @param int $processto only process attempt with timecheckstate longer ago than this.
     * @return array with two elements, the number of attempt considered, and how many different cquizzes that was.
     */
    public function update_overdue_attempts($timenow, $processto) {
        global $DB;

        $attemptstoprocess = $this->get_list_of_overdue_attempts($processto);

        $course = null;
        $cquiz = null;
        $cm = null;

        $count = 0;
        $cquizcount = 0;
        foreach ($attemptstoprocess as $attempt) {
            try {

                // If we have moved on to a different cquiz, fetch the new data.
                if (!$cquiz || $attempt->cquiz != $cquiz->id) {
                    $cquiz = $DB->get_record('cquiz', array('id' => $attempt->cquiz), '*', MUST_EXIST);
                    $cm = get_coursemodule_from_instance('cquiz', $attempt->cquiz);
                    $cquizcount += 1;
                }

                // If we have moved on to a different course, fetch the new data.
                if (!$course || $course->id != $cquiz->course) {
                    $course = $DB->get_record('course', array('id' => $cquiz->course), '*', MUST_EXIST);
                }

                // Make a specialised version of the cquiz settings, with the relevant overrides.
                $cquizforuser = clone($cquiz);
                $cquizforuser->timeclose = $attempt->usertimeclose;
                $cquizforuser->timelimit = $attempt->usertimelimit;

                // Trigger any transitions that are required.
                $attemptobj = new cquiz_attempt($attempt, $cquizforuser, $cm, $course);
                $attemptobj->handle_if_time_expired($timenow, false);
                $count += 1;

            } catch (moodle_exception $e) {
                // If an error occurs while processing one attempt, don't let that kill cron.
                mtrace("Error while processing attempt {$attempt->id} at {$attempt->cquiz} cquiz:");
                mtrace($e->getMessage());
                mtrace($e->getTraceAsString());
            }
        }

        $attemptstoprocess->close();
        return array($count, $cquizcount);
    }

    /**
     * @return moodle_recordset of cquiz_attempts that need to be processed because time has
     *     passed. The array is sorted by courseid then cquizid.
     */
    public function get_list_of_overdue_attempts($processto) {
        global $DB;


        // SQL to compute timeclose and timelimit for each attempt:
        $cquizausersql = cquiz_get_attempt_usertime_sql(
                "icquiza.state IN ('inprogress', 'overdue') AND icquiza.timecheckstate <= :iprocessto");

        // This query should have all the cquiz_attempts columns.
        return $DB->get_recordset_sql("
         SELECT cquiza.*,
                cquizauser.usertimeclose,
                cquizauser.usertimelimit

           FROM {cquiz_attempts} cquiza
           JOIN {cquiz} cquiz ON cquiz.id = cquiza.cquiz
           JOIN ( $cquizausersql ) cquizauser ON cquizauser.id = cquiza.id

          WHERE cquiza.state IN ('inprogress', 'overdue')
            AND cquiza.timecheckstate <= :processto
       ORDER BY cquiz.course, cquiza.cquiz",

                array('processto' => $processto, 'iprocessto' => $processto));
    }
}
