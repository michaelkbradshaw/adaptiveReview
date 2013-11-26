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
 * Library of functions for the cquiz module.
 *
 * This contains functions that are called also from outside the cquiz module
 * Functions that are only called by the cquiz module itself are in {@link locallib.php}
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the cquiz settings form.
 */
define('CQUIZ_MAX_ATTEMPT_OPTION', 10);
define('CQUIZ_MAX_QPP_OPTION', 50);
define('CQUIZ_MAX_DECIMAL_OPTION', 5);
define('CQUIZ_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('CQUIZ_GRADEHIGHEST', '1');
define('CQUIZ_GRADEAVERAGE', '2');
define('CQUIZ_ATTEMPTFIRST', '3');
define('CQUIZ_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the cquiz are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('CQUIZ_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within cquizzes.
 */
define('CQUIZ_NAVMETHOD_FREE', 'free');
define('CQUIZ_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $cquiz the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function cquiz_add_instance($cquiz) {
    global $DB;
    $cmid = $cquiz->coursemodule;

    // Process the options from the form.
    $cquiz->created = time();
    $cquiz->questions = '';
    $result = cquiz_process_options($cquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $cquiz->id = $DB->insert_record('cquiz', $cquiz);

    // Do the processing required after an add or an update.
    cquiz_after_add_or_update($cquiz);

    return $cquiz->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $cquiz the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function cquiz_update_instance($cquiz, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

    // Process the options from the form.
    $result = cquiz_process_options($cquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldcquiz = $DB->get_record('cquiz', array('id' => $cquiz->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $cquiz->sumgrades = $oldcquiz->sumgrades;
    $cquiz->grade     = $oldcquiz->grade;

    // Repaginate, if asked to.
    if (!$cquiz->shufflequestions && !empty($cquiz->repaginatenow)) {
        $cquiz->questions = cquiz_repaginate(cquiz_clean_layout($oldcquiz->questions, true),
                $cquiz->questionsperpage);
    }
    unset($cquiz->repaginatenow);

    // Update the database.
    $cquiz->id = $cquiz->instance;
    $DB->update_record('cquiz', $cquiz);

    // Do the processing required after an add or an update.
    cquiz_after_add_or_update($cquiz);

    if ($oldcquiz->grademethod != $cquiz->grademethod) {
        cquiz_update_all_final_grades($cquiz);
        cquiz_update_grades($cquiz);
    }

    $cquizdateschanged = $oldcquiz->timelimit   != $cquiz->timelimit
                     || $oldcquiz->timeclose   != $cquiz->timeclose
                     || $oldcquiz->graceperiod != $cquiz->graceperiod;
    if ($cquizdateschanged) {
        cquiz_update_open_attempts(array('cquizid' => $cquiz->id));
    }

    // Delete any previous preview attempts.
    cquiz_delete_previews($cquiz);

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the cquiz to delete.
 * @return bool success or failure.
 */
function cquiz_delete_instance($id) {
    global $DB;

    $cquiz = $DB->get_record('cquiz', array('id' => $id), '*', MUST_EXIST);

    cquiz_delete_all_attempts($cquiz);
    cquiz_delete_all_overrides($cquiz);

    $DB->delete_records('cquiz_question_instances', array('cquiz' => $cquiz->id));
    $DB->delete_records('cquiz_feedback', array('cquizid' => $cquiz->id));

    $events = $DB->get_records('event', array('modulename' => 'cquiz', 'instance' => $cquiz->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    cquiz_grade_item_delete($cquiz);
    $DB->delete_records('cquiz', array('id' => $cquiz->id));

    return true;
}

/**
 * Deletes a cquiz override from the database and clears any corresponding calendar events
 *
 * @param object $cquiz The cquiz object.
 * @param int $overrideid The id of the override being deleted
 * @return bool true on success
 */
function cquiz_delete_override($cquiz, $overrideid) {
    global $DB;

    $override = $DB->get_record('cquiz_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    $events = $DB->get_records('event', array('modulename' => 'cquiz',
            'instance' => $cquiz->id, 'groupid' => (int)$override->groupid,
            'userid' => (int)$override->userid));
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('cquiz_overrides', array('id' => $overrideid));
    return true;
}

/**
 * Deletes all cquiz overrides from the database and clears any corresponding calendar events
 *
 * @param object $cquiz The cquiz object.
 */
function cquiz_delete_all_overrides($cquiz) {
    global $DB;

    $overrides = $DB->get_records('cquiz_overrides', array('cquiz' => $cquiz->id), 'id');
    foreach ($overrides as $override) {
        cquiz_delete_override($cquiz, $override->id);
    }
}

/**
 * Updates a cquiz object with override information for a user.
 *
 * Algorithm:  For each cquiz setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the cquiz setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   cquiz->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $cquiz The cquiz object.
 * @param int $userid The userid.
 * @return object $cquiz The updated cquiz object.
 */
function cquiz_update_effective_access($cquiz, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('cquiz_overrides', array('cquiz' => $cquiz->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($cquiz->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {cquiz_overrides}
                WHERE groupid $extra AND cquiz = ?";
        $params[] = $cquiz->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with cquiz defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $cquiz->{$key} = $override->{$key};
        }
    }

    return $cquiz;
}

/**
 * Delete all the attempts belonging to a cquiz.
 *
 * @param object $cquiz The cquiz object.
 */
function cquiz_delete_all_attempts($cquiz) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_cquiz($cquiz->id));
    $DB->delete_records('cquiz_attempts', array('cquiz' => $cquiz->id));
    $DB->delete_records('cquiz_grades', array('cquiz' => $cquiz->id));
}

/**
 * Get the best current grade for a particular user in a cquiz.
 *
 * @param object $cquiz the cquiz settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this cquiz, or null if this user does
 * not have a grade on this cquiz.
 */
function cquiz_get_best_grade($cquiz, $userid) {
    global $DB;
    $grade = $DB->get_field('cquiz_grades', 'grade',
            array('cquiz' => $cquiz->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded cquiz? If this method returns true, you can assume that
 * $cquiz->grade and $cquiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $cquiz a row from the cquiz table.
 * @return bool whether this is a graded cquiz.
 */
function cquiz_has_grades($cquiz) {
    return $cquiz->grade >= 0.000005 && $cquiz->sumgrades >= 0.000005;
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $cquiz
 * @return object|null
 */
function cquiz_user_outline($course, $user, $mod, $cquiz) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'cquiz', $cquiz->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $cquiz
 * @return bool
 */
function cquiz_user_complete($course, $user, $mod, $cquiz) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'cquiz', $cquiz->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($attempts = $DB->get_records('cquiz_attempts',
            array('userid' => $user->id, 'cquiz' => $cquiz->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'cquiz', $attempt->attempt) . ': ';
            if ($attempt->state != cquiz_attempt::FINISHED) {
                echo cquiz_attempt_state_name($attempt->state);
            } else {
                echo cquiz_format_grade($cquiz, $attempt->sumgrades) . '/' .
                        cquiz_format_grade($cquiz, $cquiz->sumgrades);
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'cquiz');
    }

    return true;
}

/**
 * Quiz periodic clean-up tasks.
 */
function cquiz_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/cquiz/cronlib.php');
    mtrace('');

    $timenow = time();
    $overduehander = new mod_cquiz_overdue_attempt_updater();

    $processto = $timenow - get_config('cquiz', 'graceperiodmin');

    mtrace('  Looking for cquiz overdue cquiz attempts...');

    list($count, $cquizcount) = $overduehander->update_overdue_attempts($timenow, $processto);

    mtrace('  Considered ' . $count . ' attempts in ' . $cquizcount . ' cquizzes.');

    // Run cron for our sub-plugin types.
    cron_execute_plugin_type('cquiz', 'cquiz reports');
    cron_execute_plugin_type('cquizaccess', 'cquiz access rules');

    return true;
}

/**
 * @param int $cquizid the cquiz id.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this cquiz. Returns an empty
 *      array if there are none.
 */
function cquiz_get_user_attempts($cquizid, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the cquiz_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = cquiz_attempt::FINISHED;
            $params['state2'] = cquiz_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = cquiz_attempt::IN_PROGRESS;
            $params['state2'] = cquiz_attempt::OVERDUE;
            break;
    }

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    $params['cquizid'] = $cquizid;
    $params['userid'] = $userid;
    return $DB->get_records_select('cquiz_attempts',
            'cquiz = :cquizid AND userid = :userid' . $previewclause . $statuscondition,
            $params, 'attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $cquizid id of cquiz
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with cquiz_format_grade for display.
 */
function cquiz_get_user_grades($cquiz, $userid = 0) {
    global $CFG, $DB;

    $params = array($cquiz->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {cquiz_grades} qg ON u.id = qg.userid
            JOIN {cquiz_attempts} qa ON qa.cquiz = qg.cquiz AND qa.userid = u.id

            WHERE qg.cquiz = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $cquiz The cquiz table row, only $cquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function cquiz_format_grade($cquiz, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'cquiz');
    }
    return format_float($grade, $cquiz->decimalpoints);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $cquiz The cquiz table row, only $cquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function cquiz_format_question_grade($cquiz, $grade) {
    if (empty($cquiz->questiondecimalpoints)) {
        $cquiz->questiondecimalpoints = -1;
    }
    if ($cquiz->questiondecimalpoints == -1) {
        return format_float($grade, $cquiz->decimalpoints);
    } else {
        return format_float($grade, $cquiz->questiondecimalpoints);
    }
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $cquiz the cquiz settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function cquiz_update_grades($cquiz, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($cquiz->grade == 0) {
        cquiz_grade_item_update($cquiz);

    } else if ($grades = cquiz_get_user_grades($cquiz, $userid)) {
        cquiz_grade_item_update($cquiz, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        cquiz_grade_item_update($cquiz, $grade);

    } else {
        cquiz_grade_item_update($cquiz);
    }
}

/**
 * Update all grades in gradebook.
 */
function cquiz_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {cquiz} a, {course_modules} cm, {modules} m
             WHERE m.name='cquiz' AND m.id=cm.module AND cm.instance=a.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT a.*, cm.idnumber AS cmidnumber, a.course AS courseid
              FROM {cquiz} a, {course_modules} cm, {modules} m
             WHERE m.name='cquiz' AND m.id=cm.module AND cm.instance=a.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('cquizupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $cquiz) {
            $i++;
            upgrade_set_timeout(60*5); // Set up timeout, may also abort execution.
            cquiz_update_grades($cquiz, 0, false);
            $pbar->update($i, $count, "Updating Quiz grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create or update the grade item for given cquiz
 *
 * @category grade
 * @param object $cquiz object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function cquiz_grade_item_update($cquiz, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $cquiz)) { // May not be always present.
        $params = array('itemname' => $cquiz->name, 'idnumber' => $cquiz->cmidnumber);
    } else {
        $params = array('itemname' => $cquiz->name);
    }

    if ($cquiz->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $cquiz->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the cquiz is set to not show grades while the cquiz is still open,
    //    and is set to show grades after the cquiz is closed, then create the
    //    grade_item with a show-after date that is the cquiz close date.
    // 2. If the cquiz is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the cquiz is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_cquiz_display_options::make_from_cquiz($cquiz,
            mod_cquiz_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_cquiz_display_options::make_from_cquiz($cquiz,
            mod_cquiz_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($cquiz->timeclose) {
            $params['hidden'] = $cquiz->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($cquiz->course, 'mod', 'cquiz', $cquiz->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/cquiz/report.php?q=' . $cquiz->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/cquiz', $cquiz->course, 'mod', 'cquiz', $cquiz->id, 0, $grades, $params);
}

/**
 * Delete grade item for given cquiz
 *
 * @category grade
 * @param object $cquiz object
 * @return object cquiz
 */
function cquiz_grade_item_delete($cquiz) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/cquiz', $cquiz->course, 'mod', 'cquiz', $cquiz->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every cquiz event in the site is checked, else
 * only cquiz events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @return bool
 */
function cquiz_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$cquizzes = $DB->get_records('cquiz')) {
            return true;
        }
    } else {
        if (!$cquizzes = $DB->get_records('cquiz', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($cquizzes as $cquiz) {
        cquiz_update_events($cquiz);
    }

    return true;
}

/**
 * Returns all cquiz graded users since a given time for specified cquiz
 */
function cquiz_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $COURSE, $USER, $DB;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $cquiz = $DB->get_record('cquiz', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['cquizid'] = $cquiz->id;

    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     u.firstname, u.lastname, u.email, u.picture, u.imagealt
                FROM {cquiz_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.cquiz = :cquizid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/cquiz:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    if (is_null($modinfo->groups)) {
        // Load all my groups and cache it in modinfo.
        $modinfo->groups = groups_get_user_groups($course->id);
    }

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                if (is_null($usersgroups)) {
                    $usersgroups = groups_get_all_groups($course->id,
                            $attempt->userid, $cm->groupingid);
                    if (is_array($usersgroups)) {
                        $usersgroups = array_keys($usersgroups);
                    } else {
                        $usersgroups = array();
                    }
                }
                if (!array_intersect($usersgroups, $modinfo->groups[$cm->id])) {
                    continue;
                }
            }
        }

        $options = cquiz_get_review_options($cquiz, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'cquiz';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (cquiz_has_grades($cquiz) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = cquiz_format_grade($cquiz, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = cquiz_format_grade($cquiz, $cquiz->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = new stdClass();
        $tmpactivity->user->id        = $attempt->userid;
        $tmpactivity->user->firstname = $attempt->firstname;
        $tmpactivity->user->lastname  = $attempt->lastname;
        $tmpactivity->user->fullname  = fullname($attempt, $viewfullnames);
        $tmpactivity->user->picture   = $attempt->picture;
        $tmpactivity->user->imagealt  = $attempt->imagealt;
        $tmpactivity->user->email     = $attempt->email;

        $activities[$index++] = $tmpactivity;
    }
}

function cquiz_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', $activity->type) . '" ' .
                'class="icon" alt="' . $modname . '" />';
        echo '<a href="' . $CFG->wwwroot . '/mod/cquiz/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'cquiz', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/cquiz/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the cquiz options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $cquiz The variables set on the form.
 */
function cquiz_process_options($cquiz) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $cquiz->timemodified = time();

    // Quiz name.
    if (!empty($cquiz->name)) {
        $cquiz->name = trim($cquiz->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $cquiz->password = $cquiz->cquizpassword;
    unset($cquiz->cquizpassword);

    // Quiz feedback.
    if (isset($cquiz->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($cquiz->feedbacktext); $i += 1) {
            if (empty($cquiz->feedbacktext[$i]['text'])) {
                $cquiz->feedbacktext[$i]['text'] = '';
            } else {
                $cquiz->feedbacktext[$i]['text'] = trim($cquiz->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($cquiz->feedbackboundaries[$i])) {
            $boundary = trim($cquiz->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $cquiz->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'cquiz', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $cquiz->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'cquiz', $i + 1);
            }
            if ($i > 0 && $boundary >= $cquiz->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'cquiz', $i + 1);
            }
            $cquiz->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($cquiz->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($cquiz->feedbackboundaries); $i += 1) {
                if (!empty($cquiz->feedbackboundaries[$i]) &&
                        trim($cquiz->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'cquiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($cquiz->feedbacktext); $i += 1) {
            if (!empty($cquiz->feedbacktext[$i]['text']) &&
                    trim($cquiz->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'cquiz', $i + 1);
            }
        }
        // Needs to be bigger than $cquiz->grade because of '<' test in cquiz_feedback_for_grade().
        $cquiz->feedbackboundaries[-1] = $cquiz->grade + 1;
        $cquiz->feedbackboundaries[$numboundaries] = 0;
        $cquiz->feedbackboundarycount = $numboundaries;
    } else {
        $cquiz->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $cquiz->reviewattempt = cquiz_review_option_form_to_db($cquiz, 'attempt');
    $cquiz->reviewcorrectness = cquiz_review_option_form_to_db($cquiz, 'correctness');
    $cquiz->reviewmarks = cquiz_review_option_form_to_db($cquiz, 'marks');
    $cquiz->reviewspecificfeedback = cquiz_review_option_form_to_db($cquiz, 'specificfeedback');
    $cquiz->reviewgeneralfeedback = cquiz_review_option_form_to_db($cquiz, 'generalfeedback');
    $cquiz->reviewrightanswer = cquiz_review_option_form_to_db($cquiz, 'rightanswer');
    $cquiz->reviewoverallfeedback = cquiz_review_option_form_to_db($cquiz, 'overallfeedback');
    $cquiz->reviewattempt |= mod_cquiz_display_options::DURING;
    $cquiz->reviewoverallfeedback &= ~mod_cquiz_display_options::DURING;
}

/**
 * Helper function for {@link cquiz_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function cquiz_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_cquiz_display_options::DURING,
        'immediately' => mod_cquiz_display_options::IMMEDIATELY_AFTER,
        'open' => mod_cquiz_display_options::LATER_WHILE_OPEN,
        'closed' => mod_cquiz_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of cquiz_add_instance
 * and cquiz_update_instance, to do the common processing.
 *
 * @param object $cquiz the cquiz object.
 */
function cquiz_after_add_or_update($cquiz) {
    global $DB;
    $cmid = $cquiz->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $cquiz->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('cquiz_feedback', array('cquizid' => $cquiz->id));

    for ($i = 0; $i <= $cquiz->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->cquizid = $cquiz->id;
        $feedback->feedbacktext = $cquiz->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $cquiz->feedbacktext[$i]['format'];
        $feedback->mingrade = $cquiz->feedbackboundaries[$i];
        $feedback->maxgrade = $cquiz->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('cquiz_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$cquiz->feedbacktext[$i]['itemid'],
                $context->id, 'mod_cquiz', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $cquiz->feedbacktext[$i]['text']);
        $DB->set_field('cquiz_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    cquiz_access_manager::save_settings($cquiz);

    // Update the events relating to this cquiz.
    cquiz_update_events($cquiz);

    // Update related grade item.
    cquiz_grade_item_update($cquiz);
}

/**
 * This function updates the events associated to the cquiz.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses CQUIZ_MAX_EVENT_LENGTH
 * @param object $cquiz the cquiz object.
 * @param object optional $override limit to a specific override
 */
function cquiz_update_events($cquiz, $override = null) {
    global $DB;

    // Load the old events relating to this cquiz.
    $conds = array('modulename'=>'cquiz',
                   'instance'=>$cquiz->id);
    if (!empty($override)) {
        // Only load events for this override.
        $conds['groupid'] = isset($override->groupid)?  $override->groupid : 0;
        $conds['userid'] = isset($override->userid)?  $override->userid : 0;
    }
    $oldevents = $DB->get_records('event', $conds);

    // Now make a todo list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the cquiz, so we
        // need to add all the overrides.
        $overrides = $DB->get_records('cquiz_overrides', array('cquiz' => $cquiz->id));
        // As well as the original cquiz (empty override).
        $overrides[] = new stdClass();
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $cquiz->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $cquiz->timeclose;

        // Only add open/close events for an override if they differ from the cquiz default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($cquiz->coursemodule)) {
            $cmid = $cquiz->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('cquiz', $cquiz->id, $cquiz->course)->id;
        }

        $event = new stdClass();
        $event->description = format_module_intro('cquiz', $cquiz, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $cquiz->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'cquiz';
        $event->instance    = $cquiz->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->visible     = instance_is_visible('cquiz', $cquiz);
        $event->eventtype   = 'open';

        // Determine the event name.
        if ($groupid) {
            $params = new stdClass();
            $params->cquiz = $cquiz->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'cquiz', $params);
        } else if ($userid) {
            $params = new stdClass();
            $params->cquiz = $cquiz->name;
            $eventname = get_string('overrideusereventname', 'cquiz', $params);
        } else {
            $eventname = $cquiz->name;
        }
        if ($addopen or $addclose) {
            if ($timeclose and $timeopen and $event->timeduration <= CQUIZ_MAX_EVENT_LENGTH) {
                // Single event for the whole cquiz.
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = $eventname;
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event);
            } else {
                // Separate start and end events.
                $event->timeduration  = 0;
                if ($timeopen && $addopen) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name = $eventname.' ('.get_string('cquizopens', 'cquiz').')';
                    // The method calendar_event::create will reuse a db record if the id field is set.
                    calendar_event::create($event);
                }
                if ($timeclose && $addclose) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name      = $eventname.' ('.get_string('cquizcloses', 'cquiz').')';
                    $event->timestart = $timeclose;
                    $event->eventtype = 'close';
                    calendar_event::create($event);
                }
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * @return array
 */
function cquiz_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * @return array
 */
function cquiz_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function cquiz_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('cquiz_question_instances',
            'question ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{cquiz_attempts} cquiza',
            'cquiza.uniqueid', 'cquiza.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the cquiz.
 *
 * @param $mform the course reset form that is being built.
 */
function cquiz_reset_course_form_definition($mform) {
    $mform->addElement('header', 'cquizheader', get_string('modulenameplural', 'cquiz'));
    $mform->addElement('advcheckbox', 'reset_cquiz_attempts',
            get_string('removeallcquizattempts', 'cquiz'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function cquiz_reset_course_form_defaults($course) {
    return array('reset_cquiz_attempts' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function cquiz_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $cquizzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {cquiz} q ON cm.instance = q.id
            WHERE m.name = 'cquiz' AND cm.course = ?", array($courseid));

    foreach ($cquizzes as $cquiz) {
        cquiz_grade_item_update($cquiz, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * cquiz attempts for course $data->courseid, if $data->reset_cquiz_attempts is
 * set and true.
 *
 * Also, move the cquiz open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function cquiz_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'cquiz');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_cquiz_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{cquiz_attempts} cquiza JOIN {cquiz} cquiz ON cquiza.cquiz = cquiz.id',
                'cquiza.uniqueid', 'cquiz.course = :cquizcourseid',
                array('cquizcourseid' => $data->courseid)));

        $DB->delete_records_select('cquiz_attempts',
                'cquiz IN (SELECT id FROM {cquiz} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'cquiz'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('cquiz_grades',
                'cquiz IN (SELECT id FROM {cquiz} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            cquiz_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'cquiz'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {cquiz_overrides}
                         SET timeopen = timeopen + ?
                       WHERE cquiz IN (SELECT id FROM {cquiz} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {cquiz_overrides}
                         SET timeclose = timeclose + ?
                       WHERE cquiz IN (SELECT id FROM {cquiz} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        shift_course_mod_dates('cquiz', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'cquiz'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints cquiz summaries on MyMoodle Page
 * @param arry $courses
 * @param array $htmlarray
 */
function cquiz_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$cquizzes = get_all_instances_in_courses('cquiz', $courses)) {
        return;
    }

    // Fetch some language strings outside the main loop.
    $strcquiz = get_string('modulename', 'cquiz');
    $strnoattempts = get_string('noattempts', 'cquiz');

    // We want to list cquizzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($cquizzes as $cquiz) {
        if ($cquiz->timeclose >= $now && $cquiz->timeopen < $now) {
            // Give a link to the cquiz, and the deadline.
            $str = '<div class="cquiz overview">' .
                    '<div class="name">' . $strcquiz . ': <a ' .
                    ($cquiz->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/cquiz/view.php?id=' .
                    $cquiz->coursemodule . '">' .
                    $cquiz->name . '</a></div>';
            $str .= '<div class="info">' . get_string('cquizcloseson', 'cquiz',
                    userdate($cquiz->timeclose)) . '</div>';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($cquiz->coursemodule);
            if (has_capability('mod/cquiz:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $cquiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' .
                        cquiz_num_attempt_summary($cquiz, $cquiz, true) . '</div>';
            } else if (has_any_capability(array('mod/cquiz:reviewmyattempts', 'mod/cquiz:attempt'),
                    $context)) { // Student
                // For student-like people, tell them how many attempts they have made.
                if (isset($USER->id) &&
                        ($attempts = cquiz_get_user_attempts($cquiz->id, $USER->id))) {
                    $numattempts = count($attempts);
                    $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'cquiz', $numattempts) . '</div>';
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
                // For ayone else, there is no point listing this cquiz, so stop processing.
                continue;
            }

            // Add the output for this cquiz to the rest.
            $str .= '</div>';
            if (empty($htmlarray[$cquiz->course]['cquiz'])) {
                $htmlarray[$cquiz->course]['cquiz'] = $str;
            } else {
                $htmlarray[$cquiz->course]['cquiz'] .= $str;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular cquiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $cquiz the cquiz object. Only $cquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function cquiz_num_attempt_summary($cquiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('cquiz_attempts', array('cquiz'=> $cquiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{cquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE cquiz = ? AND preview = 0 AND groupid = ?',
                        array($cquiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'cquiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{cquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE cquiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($cquiz->id), $params));
                return get_string('attemptsnumyourgroups', 'cquiz', $a);
            }
        }
        return get_string('attemptsnum', 'cquiz', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link cquiz_num_attempt_summary()} but wrapped in a link
 * to the cquiz reports.
 *
 * @param object $cquiz the cquiz object. Only $cquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the cquiz context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function cquiz_attempt_summary_link_to_reports($cquiz, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = cquiz_num_attempt_summary($cquiz, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/cquiz/report/reportlib.php');
    $url = new moodle_url('/mod/cquiz/report.php', array(
            'id' => $cm->id, 'mode' => cquiz_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if cquiz supports feature
 */
function cquiz_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_GROUPMEMBERSONLY:          return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function cquiz_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    $caps = question_get_all_capabilities();
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $cquiznode
 * @return void
 */
function cquiz_extend_settings_navigation($settings, $cquiznode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $cquiznode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/cquiz:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/cquiz/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'cquiz'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_cquiz_groupoverrides');
        $cquiznode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'cquiz'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_cquiz_useroverrides');
        $cquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/cquiz:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editcquiz', 'cquiz'),
                new moodle_url('/mod/cquiz/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_cquiz_edit',
                new pix_icon('t/edit', ''));
        $cquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/cquiz:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/cquiz/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'cquiz'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_cquiz_preview',
                new pix_icon('i/preview', ''));
        $cquiznode->add_node($node, $beforekey);
    }

    if (has_any_capability(array('mod/cquiz:viewreports', 'mod/cquiz:grade'), $PAGE->cm->context)) {
        require_once($CFG->dirroot . '/mod/cquiz/report/reportlib.php');
        $reportlist = cquiz_report_list($PAGE->cm->context);

        $url = new moodle_url('/mod/cquiz/report.php',
                array('id' => $PAGE->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $cquiznode->add_node(navigation_node::create(get_string('results', 'cquiz'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', '')), $beforekey);

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/cquiz/report.php',
                    array('id' => $PAGE->cm->id, 'mode' => $report));
            $reportnode->add_node(navigation_node::create(get_string($report, 'cquiz_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'cquiz_report_' . $report, new pix_icon('i/item', '')));
        }
    }

    question_extend_settings_navigation($cquiznode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the cquiz files.
 *
 * @package  mod_cquiz
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function cquiz_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$cquiz = $DB->get_record('cquiz', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('cquiz_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_cquiz/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a cquiz attempt.
 *
 * @package  mod_cquiz
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this cquiz attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function cquiz_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/cquiz/locallib.php');

    $attemptobj = cquiz_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/cquiz:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function cquiz_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-cquiz-*'=>get_string('page-mod-cquiz-x', 'cquiz'),
        'mod-cquiz-edit'=>get_string('page-mod-cquiz-edit', 'cquiz'));
    return $module_pagetype;
}

/**
 * @return the options for cquiz navigation.
 */
function cquiz_get_navigation_options() {
    return array(
        CQUIZ_NAVMETHOD_FREE => get_string('navmethod_free', 'cquiz'),
        CQUIZ_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'cquiz')
    );
}
