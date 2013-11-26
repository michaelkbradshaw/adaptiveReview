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
 * Helper functions for the areview reports.
 *
 * @package   mod_areview
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/lib.php');
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
function areview_report_index_by_keys($datum, $keys, $keysunique = true) {
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
            $datumkeyed[$datakey] = areview_report_index_by_keys($datakeyed, $keys, $keysunique);
        }
    }
    return $datumkeyed;
}

function areview_report_unindex($datum) {
    if (!$datum) {
        return $datum;
    }
    $datumunkeyed = array();
    foreach ($datum as $value) {
        if (is_array($value)) {
            $datumunkeyed = array_merge($datumunkeyed, areview_report_unindex($value));
        } else {
            $datumunkeyed[] = $value;
        }
    }
    return $datumunkeyed;
}

/**
 * Get the slots of real questions (not descriptions) in this areview, in order.
 * @param object $areview the areview.
 * @return array of slot => $question object with fields
 *      ->slot, ->id, ->maxmark, ->number, ->length.
 */
function areview_report_get_significant_questions($areview) {
    global $DB;

    $questionids = areview_questions_in_areview($areview->questions);
    if (empty($questionids)) {
        return array();
    }

    list($usql, $params) = $DB->get_in_or_equal(explode(',', $questionids));
    $params[] = $areview->id;
    $questions = $DB->get_records_sql("
SELECT
    q.id,
    q.length,
    qqi.grade AS maxmark

FROM {question} q
JOIN {areview_question_instances} qqi ON qqi.question = q.id

WHERE
    q.id $usql AND
    qqi.areview = ? AND
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
 * @param object $areview the areview settings.
 * @return bool whether, for this areview, it is possible to filter attempts to show
 *      only those that gave the final grade.
 */
function areview_report_can_filter_only_graded($areview) {
    return $areview->attempts != 1 && $areview->grademethod != areview_GRADEAVERAGE;
}

/**
 * Given the areview grading method return sub select sql to find the id of the
 * one attempt that will be graded for each user. Or return
 * empty string if all attempts contribute to final grade.
 */
function areview_report_qm_filter_select($areview, $areviewattemptsalias = 'areviewa') {
    if ($areview->attempts == 1) {
        // This areview only allows one attempt.
        return '';
    }

    switch ($areview->grademethod) {
        case areview_GRADEHIGHEST :
            return "$areviewattemptsalias.id = (
                    SELECT MIN(qa2.id)
                    FROM {areview_attempts} qa2
                    WHERE qa2.areview = $areviewattemptsalias.areview AND
                        qa2.userid = $areviewattemptsalias.userid AND
                        COALESCE(qa2.sumgrades, 0) = (
                            SELECT MAX(COALESCE(qa3.sumgrades, 0))
                            FROM {areview_attempts} qa3
                            WHERE qa3.areview = $areviewattemptsalias.areview AND
                                qa3.userid = $areviewattemptsalias.userid
                        )
                    )";

        case areview_GRADEAVERAGE :
            return '';

        case areview_ATTEMPTFIRST :
            return "$areviewattemptsalias.id = (
                    SELECT MIN(qa2.id)
                    FROM {areview_attempts} qa2
                    WHERE qa2.areview = $areviewattemptsalias.areview AND
                        qa2.userid = $areviewattemptsalias.userid)";

        case areview_ATTEMPTLAST :
            return "$areviewattemptsalias.id = (
                    SELECT MAX(qa2.id)
                    FROM {areview_attempts} qa2
                    WHERE qa2.areview = $areviewattemptsalias.areview AND
                        qa2.userid = $areviewattemptsalias.userid)";
    }
}

/**
 * Get the nuber of students whose score was in a particular band for this areview.
 * @param number $bandwidth the width of each band.
 * @param int $bands the number of bands
 * @param int $areviewid the areview id.
 * @param array $userids list of user ids.
 * @return array band number => number of users with scores in that band.
 */
function areview_report_grade_bands($bandwidth, $bands, $areviewid, $userids = array()) {
    global $DB;
    if (!is_int($bands)) {
        debugging('$bands passed to areview_report_grade_bands must be an integer. (' .
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
      FROM {areview_grades} qg
     WHERE $usql qg.areview = :areviewid
) subquery

GROUP BY
    band

ORDER BY
    band";

    $params['areviewid'] = $areviewid;
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

function areview_report_highlighting_grading_method($areview, $qmsubselect, $qmfilter) {
    if ($areview->attempts == 1) {
        return '<p>' . get_string('onlyoneattemptallowed', 'areview_overview') . '</p>';

    } else if (!$qmsubselect) {
        return '<p>' . get_string('allattemptscontributetograde', 'areview_overview') . '</p>';

    } else if ($qmfilter) {
        return '<p>' . get_string('showinggraded', 'areview_overview') . '</p>';

    } else {
        return '<p>' . get_string('showinggradedandungraded', 'areview_overview',
                '<span class="gradedattempt">' . areview_get_grading_option_name($areview->grademethod) .
                '</span>') . '</p>';
    }
}

/**
 * Get the feedback text for a grade on this areview. The feedback is
 * processed ready for display.
 *
 * @param float $grade a grade on this areview.
 * @param int $areviewid the id of the areview object.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function areview_report_feedback_for_grade($grade, $areviewid, $context) {
    global $DB;

    static $feedbackcache = array();

    if (!isset($feedbackcache[$areviewid])) {
        $feedbackcache[$areviewid] = $DB->get_records('areview_feedback', array('areviewid' => $areviewid));
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedbacks = $feedbackcache[$areviewid];
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
            $context->id, 'mod_areview', 'feedback', $feedbackid);
    $feedbacktext = format_text($feedbacktext, $feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * Format a number as a percentage out of $areview->sumgrades
 * @param number $rawgrade the mark to format.
 * @param object $areview the areview settings
 * @param bool $round whether to round the results ot $areview->decimalpoints.
 */
function areview_report_scale_summarks_as_percentage($rawmark, $areview, $round = true) {
    if ($areview->sumgrades == 0) {
        return '';
    }
    if (!is_numeric($rawmark)) {
        return $rawmark;
    }

    $mark = $rawmark * 100 / $areview->sumgrades;
    if ($round) {
        $mark = areview_format_grade($areview, $mark);
    }
    return $mark . '%';
}

/**
 * Returns an array of reports to which the current user has access to.
 * @return array reports are ordered as they should be for display in tabs.
 */
function areview_report_list($context) {
    global $DB;
    static $reportlist = null;
    if (!is_null($reportlist)) {
        return $reportlist;
    }

    $reports = $DB->get_records('areview_reports', null, 'displayorder DESC', 'name, capability');
    $reportdirs = get_plugin_list('areview');

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
            $capability = 'mod/areview:viewreports';
        }
        if (has_capability($capability, $context)) {
            $reportlist[] = $name;
        }
    }
    return $reportlist;
}

/**
 * Create a filename for use when downloading data from a areview report. It is
 * expected that this will be passed to flexible_table::is_downloading, which
 * cleans the filename of bad characters and adds the file extension.
 * @param string $report the type of report.
 * @param string $courseshortname the course shortname.
 * @param string $areviewname the areview name.
 * @return string the filename.
 */
function areview_report_download_filename($report, $courseshortname, $areviewname) {
    return $courseshortname . '-' . format_string($areviewname, true) . '-' . $report;
}

/**
 * Get the default report for the current user.
 * @param object $context the areview context.
 */
function areview_report_default_report($context) {
    $reports = areview_report_list($context);
    return reset($reports);
}

/**
 * Generate a message saying that this areview has no questions, with a button to
 * go to the edit page, if the user has the right capability.
 * @param object $areview the areview settings.
 * @param object $cm the course_module object.
 * @param object $context the areview context.
 * @return string HTML to output.
 */
function areview_no_questions_message($areview, $cm, $context) {
    global $OUTPUT;

    $output = '';
    $output .= $OUTPUT->notification(get_string('noquestions', 'areview'));
    if (has_capability('mod/areview:manage', $context)) {
        $output .= $OUTPUT->single_button(new moodle_url('/mod/areview/edit.php',
        array('cmid' => $cm->id)), get_string('editareview', 'areview'), 'get');
    }

    return $output;
}

/**
 * Should the grades be displayed in this report. That depends on the areview
 * display options, and whether the areview is graded.
 * @param object $areview the areview settings.
 * @param context $context the areview context.
 * @return bool
 */
function areview_report_should_show_grades($areview, context $context) {
    if ($areview->timeclose && time() > $areview->timeclose) {
        $when = mod_areview_display_options::AFTER_CLOSE;
    } else {
        $when = mod_areview_display_options::LATER_WHILE_OPEN;
    }
    $reviewoptions = mod_areview_display_options::make_from_areview($areview, $when);

    return areview_has_grades($areview) &&
            ($reviewoptions->marks >= question_display_options::MARK_AND_MAX ||
            has_capability('moodle/grade:viewhidden', $context));
}
