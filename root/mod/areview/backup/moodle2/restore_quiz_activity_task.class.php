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

require_once($CFG->dirroot . '/mod/areview/backup/moodle2/restore_areview_stepslib.php');


/**
 * areview restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_areview_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Quiz only has one structure step.
        $this->add_step(new restore_areview_activity_structure_step('areview_structure', 'areview.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('areview', array('intro'), 'areview');
        $contents[] = new restore_decode_content('areview_feedback',
                array('feedbacktext'), 'areview_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('QUIZVIEWBYID',
                '/mod/areview/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('QUIZVIEWBYQ',
                '/mod/areview/view.php?q=$1', 'areview');
        $rules[] = new restore_decode_rule('QUIZINDEX',
                '/mod/areview/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * areview logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('areview', 'add',
                'view.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'update',
                'view.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'view',
                'view.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'preview',
                'view.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'report',
                'report.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'editquestions',
                'view.php?id={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('areview', 'edit override',
                'overrideedit.php?id={areview_override}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'delete override',
                'overrides.php.php?cmid={course_module}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('areview', 'view summary',
                'summary.php?attempt={areview_attempt_id}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'manualgrade',
                'comment.php?attempt={areview_attempt_id}&question={question}', '{areview}');
        $rules[] = new restore_log_rule('areview', 'manualgrading',
                'report.php?mode=grading&q={areview}', '{areview}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'areview_attempt_id' mapping because that is the
        // one containing the areview_attempt->ids old an new for areview-attempt.
        $rules[] = new restore_log_rule('areview', 'attempt',
                'review.php?id={course_module}&attempt={areview_attempt}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt}');
        // Old an new for areview-submit.
        $rules[] = new restore_log_rule('areview', 'submit',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'submit',
                'review.php?attempt={areview_attempt_id}', '{areview}');
        // Old an new for areview-review.
        $rules[] = new restore_log_rule('areview', 'review',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'review',
                'review.php?attempt={areview_attempt_id}', '{areview}');
        // Old an new for areview-start attemp.
        $rules[] = new restore_log_rule('areview', 'start attempt',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'start attempt',
                'review.php?attempt={areview_attempt_id}', '{areview}');
        // Old an new for areview-close attemp.
        $rules[] = new restore_log_rule('areview', 'close attempt',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'close attempt',
                'review.php?attempt={areview_attempt_id}', '{areview}');
        // Old an new for areview-continue attempt.
        $rules[] = new restore_log_rule('areview', 'continue attempt',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, null, 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'continue attempt',
                'review.php?attempt={areview_attempt_id}', '{areview}');
        // Old an new for areview-continue attemp.
        $rules[] = new restore_log_rule('areview', 'continue attemp',
                'review.php?id={course_module}&attempt={areview_attempt_id}', '{areview}',
                null, 'continue attempt', 'review.php?attempt={areview_attempt_id}');
        $rules[] = new restore_log_rule('areview', 'continue attemp',
                'review.php?attempt={areview_attempt_id}', '{areview}',
                null, 'continue attempt');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('areview', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
