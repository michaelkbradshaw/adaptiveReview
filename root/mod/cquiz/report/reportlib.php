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
 * Helper functions for the cquiz reports.
 *
 * @package   mod_cquiz
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Takes an array of objects and constructs a multidimensional array keyed by
 * the keys it finds on the object.
 * @param array $datum an array of objects with properties on the object
 * including the keys passed as the next param.
 * @param array $keys Array of strings with the names of the properties on the
 * objects in datum that you want to index the multidimensional array by.
 * @param bool $keysunique If there is not only one object for each
 * combination of keys you are using you should set $keysunique to true.
 * Otherwise all the object will be added to a zero based array. So the array
 * returned will have count($keys) + 1 indexs.
 * @return array multidimensional array properly indexed.
 */
function cquiz_report_index_by_keys($datum, $keys, $keysunique = true) {
    if (!$datum) {
        return array();
    }
    $key = array_shift($keys);
    $datumkeyed = array();
    foreach ($datum as $data) {
        if ($keys || !$keysunique) {
            $datumkeyed[$data->{$key}][]= $data;
        } else {
            $datumkeyed[$data->{$key}]= $data;
        }
    }
    if ($keys) {
        foreach ($datumkeyed as $datakey => $datakeyed) {
            $datumkeyed[$datakey] = cquiz_report_index_by_keys($datakeyed, $keys, $keysunique);
        }
    }
    return $datumkeyed;
}

function cquiz_report_unindex($datum) {
    if (!$datum) {
        return $datum;
    }
    $datumunkeyed = array();
    foreach ($datum as $value) {
        if (is_array($value)) {
            $datumunkeyed = array_merge($datumunkeyed, cquiz_report_unindex($value));
        } else {
            $datumunkeyed[] = $value;
        }
    }
    return $datumunkeyed;
}

/**
 * Get the slots of real questions (not descriptions) in this cquiz, in order.
 * @param object $cquiz the cquiz.
 * @return array of slot => $question object with fields
 *      ->slot, ->id, ->maxmark, ->number, ->length.
 */
function cquiz_report_get_significant_questions($cquiz) {
    global $DB;

    $questionids = cquiz_questions_in_cquiz($cquiz->questions);
    if (empty($questionids)) {
        return array();
    }

    list($usql, $params) = $DB->get_in_or_equal(explode(',', $questionids));
    $params[] = $cquiz->id;
    $questions = $DB->get_records_sql("
SELECT
    q.id,
    q.length,
    qqi.grade AS maxmark

FROM {question} q
JOIN {cquiz_question_instances} qqi ON qqi.question = q.id

WHERE
    q.id $usql AND
    qqi.cquiz = ? AND
    length > 0", $params);

    $qsbyslot = array();
    $number = 1;
    foreach (explode(',', $questionids) as $key => $id) {
        if (!array_key_exists($id, $questions)) {
            continue;
        }

        $slot = $key + 1;
        $question = $questions[$id];
        $question->slot = $slot;
        $question->number = $number;

        $qsbyslot[$slot] = $question;

        $number += $question->length;
    }

    return $qsbyslot;
}

/**
 * @param object $cquiz the cquiz settings.
 * @return bool whether, for this cquiz, it is possible to filter attempts to show
 *      only those that gave the final grade.
 */
function cquiz_report_can_filter_only_graded($cquiz) {
    return $cquiz->attempts != 1 && $cquiz->grademethod != CQUIZ_GRADEAVERAGE;
}

/**
 * Given the cquiz grading method return sub select sql to find the id of the
 * one attempt that will be graded for each user. Or return
 * empty string if all attempts contribute to final grade.
 */
function cquiz_report_qm_filter_select($cquiz, $cquizattemptsalias = 'cquiza') {
    if ($cquiz->attempts == 1) {
        // This cquiz only allows one attempt.
        return '';
    }

    switch ($cquiz->grademethod) {
        case CQUIZ_GRADEHIGHEST :
            return "$cquizattemptsalias.id = (
                    SELECT MIN(qa2.id)
                    FROM {cquiz_attempts} qa2
                    WHERE qa2.cquiz = $cquizattemptsalias.cquiz AND
                        qa2.userid = $cquizattemptsalias.userid AND
                        COALESCE(qa2.sumgrades, 0) = (
                            SELECT MAX(COALESCE(qa3.sumgrades, 0))
                            FROM {cquiz_attempts} qa3
                            WHERE qa3.cquiz = $cquizattemptsalias.cquiz AND
                                qa3.userid = $cquizattemptsalias.userid
                        )
                    )";

        case CQUIZ_GRADEAVERAGE :
            return '';

        case CQUIZ_ATTEMPTFIRST :
            return "$cquizattemptsalias.id = (
                    SELECT MIN(qa2.id)
                    FROM {cquiz_attempts} qa2
                    WHERE qa2.cquiz = $cquizattemptsalias.cquiz AND
                        qa2.userid = $cquizattemptsalias.userid)";

        case CQUIZ_ATTEMPTLAST :
            return "$cquizattemptsalias.id = (
                    SELECT MAX(qa2.id)
                    FROM {cquiz_attempts} qa2
                    WHERE qa2.cquiz = $cquizattemptsalias.cquiz AND
                        qa2.userid = $cquizattemptsalias.userid)";
    }
}

/**
 * Get the nuber of students whose score was in a particular band for this cquiz.
 * @param number $bandwidth the width of each band.
 * @param int $bands the number of bands
 * @param int $cquizid the cquiz id.
 * @param array $userids list of user ids.
 * @return array band number => number of users with scores in that band.
 */
function cquiz_report_grade_bands($bandwidth, $bands, $cquizid, $userids = array()) {
    global $DB;
    if (!is_int($bands)) {
        debugging('$bands passed to cquiz_report_grade_bands must be an integer. (' .
                gettype($bands) . ' passed.)', DEBUG_DEVELOPER);
        $bands = (int) $bands;
    }

    if ($userids) {
        list($usql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $usql = "qg.userid $usql AND";
    } else {
        $usql = '';
        $params = array();
    }
    $sql = "
SELECT band, COUNT(1)

FROM (
    SELECT FLOOR(qg.grade / :bandwidth) AS band
      FROM {cquiz_grades} qg
     WHERE $usql qg.cquiz = :cquizid
) subquery

GROUP BY
    band

ORDER BY
    band";

    $params['cquizid'] = $cquizid;
    $params['bandwidth'] = $bandwidth;

    $data = $DB->get_records_sql_menu($sql, $params);

    // We need to create array elements with values 0 at indexes where there is no element.
    $data =  $data + array_fill(0, $bands + 1, 0);
    ksort($data);

    // Place the maximum (prefect grade) into the last band i.e. make last
    // band for example 9 <= g <=10 (where 10 is the perfect grade) rather than
    // just 9 <= g <10.
    $data[$bands - 1] += $data[$bands];
    unset($data[$bands]);

    return $data;
}

function cquiz_report_highlighting_grading_method($cquiz, $qmsubselect, $qmfilter) {
    if ($cquiz->attempts == 1) {
        return '<p>' . get_string('onlyoneattemptallowed', 'cquiz_overview') . '</p>';

    } else if (!$qmsubselect) {
        return '<p>' . get_string('allattemptscontributetograde', 'cquiz_overview') . '</p>';

    } else if ($qmfilter) {
        return '<p>' . get_string('showinggraded', 'cquiz_overview') . '</p>';

    } else {
        return '<p>' . get_string('showinggradedandungraded', 'cquiz_overview',
                '<span class="gradedattempt">' . cquiz_get_grading_option_name($cquiz->grademethod) .
                '</span>') . '</p>';
    }
}

/**
 * Get the feedback text for a grade on this cquiz. The feedback is
 * processed ready for display.
 *
 * @param float $grade a grade on this cquiz.
 * @param int $cquizid the id of the cquiz object.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function cquiz_report_feedback_for_grade($grade, $cquizid, $context) {
    global $DB;

    static $feedbackcache = array();

    if (!isset($feedbackcache[$cquizid])) {
        $feedbackcache[$cquizid] = $DB->get_records('cquiz_feedback', array('cquizid' => $cquizid));
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedbacks = $feedbackcache[$cquizid];
    $feedbackid = 0;
    $feedbacktext = '';
    $feedbacktextformat = FORMAT_MOODLE;
    foreach ($feedbacks as $feedback) {
        if ($feedback->mingrade <= $grade && $grade < $feedback->maxgrade) {
            $feedbackid = $feedback->id;
            $feedbacktext = $feedback->feedbacktext;
            $feedbacktextformat = $feedback->feedbacktextformat;
            break;
        }
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedbacktext, 'pluginfile.php',
            $context->id, 'mod_cquiz', 'feedback', $feedbackid);
    $feedbacktext = format_text($feedbacktext, $feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * Format a number as a percentage out of $cquiz->sumgrades
 * @param number $rawgrade the mark to format.
 * @param object $cquiz the cquiz settings
 * @param bool $round whether to round the results ot $cquiz->decimalpoints.
 */
function cquiz_report_scale_summarks_as_percentage($rawmark, $cquiz, $round = true) {
    if ($cquiz->sumgrades == 0) {
        return '';
    }
    if (!is_numeric($rawmark)) {
        return $rawmark;
    }

    $mark = $rawmark * 100 / $cquiz->sumgrades;
    if ($round) {
        $mark = cquiz_format_grade($cquiz, $mark);
    }
    return $mark . '%';
}

/**
 * Returns an array of reports to which the current user has access to.
 * @return array reports are ordered as they should be for display in tabs.
 */
function cquiz_report_list($context) {
    global $DB;
    static $reportlist = null;
    if (!is_null($reportlist)) {
        return $reportlist;
    }

    $reports = $DB->get_records('cquiz_reports', null, 'displayorder DESC', 'name, capability');
    $reportdirs = get_plugin_list('cquiz');

    // Order the reports tab in descending order of displayorder.
    $reportcaps = array();
    foreach ($reports as $key => $report) {
        if (array_key_exists($report->name, $reportdirs)) {
            $reportcaps[$report->name] = $report->capability;
        }
    }

    // Add any other reports, which are on disc but not in the DB, on the end.
    foreach ($reportdirs as $reportname => $notused) {
        if (!isset($reportcaps[$reportname])) {
            $reportcaps[$reportname] = null;
        }
    }
    $reportlist = array();
    foreach ($reportcaps as $name => $capability) {
        if (empty($capability)) {
            $capability = 'mod/cquiz:viewreports';
        }
        if (has_capability($capability, $context)) {
            $reportlist[] = $name;
        }
    }
    return $reportlist;
}

/**
 * Create a filename for use when downloading data from a cquiz report. It is
 * expected that this will be passed to flexible_table::is_downloading, which
 * cleans the filename of bad characters and adds the file extension.
 * @param string $report the type of report.
 * @param string $courseshortname the course shortname.
 * @param string $cquizname the cquiz name.
 * @return string the filename.
 */
function cquiz_report_download_filename($report, $courseshortname, $cquizname) {
    return $courseshortname . '-' . format_string($cquizname, true) . '-' . $report;
}

/**
 * Get the default report for the current user.
 * @param object $context the cquiz context.
 */
function cquiz_report_default_report($context) {
    $reports = cquiz_report_list($context);
    return reset($reports);
}

/**
 * Generate a message saying that this cquiz has no questions, with a button to
 * go to the edit page, if the user has the right capability.
 * @param object $cquiz the cquiz settings.
 * @param object $cm the course_module object.
 * @param object $context the cquiz context.
 * @return string HTML to output.
 */
function cquiz_no_questions_message($cquiz, $cm, $context) {
    global $OUTPUT;

    $output = '';
    $output .= $OUTPUT->notification(get_string('noquestions', 'cquiz'));
    if (has_capability('mod/cquiz:manage', $context)) {
        $output .= $OUTPUT->single_button(new moodle_url('/mod/cquiz/edit.php',
        array('cmid' => $cm->id)), get_string('editcquiz', 'cquiz'), 'get');
    }

    return $output;
}

/**
 * Should the grades be displayed in this report. That depends on the cquiz
 * display options, and whether the cquiz is graded.
 * @param object $cquiz the cquiz settings.
 * @param context $context the cquiz context.
 * @return bool
 */
function cquiz_report_should_show_grades($cquiz, context $context) {
    if ($cquiz->timeclose && time() > $cquiz->timeclose) {
        $when = mod_cquiz_display_options::AFTER_CLOSE;
    } else {
        $when = mod_cquiz_display_options::LATER_WHILE_OPEN;
    }
    $reviewoptions = mod_cquiz_display_options::make_from_cquiz($cquiz, $when);

    return cquiz_has_grades($cquiz) &&
            ($reviewoptions->marks >= question_display_options::MARK_AND_MAX ||
            has_capability('moodle/grade:viewhidden', $context));
}
