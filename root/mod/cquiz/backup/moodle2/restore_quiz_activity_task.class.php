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

require_once($CFG->dirroot . '/mod/cquiz/backup/moodle2/restore_cquiz_stepslib.php');


/**
 * cquiz restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_cquiz_activity_task extends restore_activity_task {

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
        $this->add_step(new restore_cquiz_activity_structure_step('cquiz_structure', 'cquiz.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('cquiz', array('intro'), 'cquiz');
        $contents[] = new restore_decode_content('cquiz_feedback',
                array('feedbacktext'), 'cquiz_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('QUIZVIEWBYID',
                '/mod/cquiz/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('QUIZVIEWBYQ',
                '/mod/cquiz/view.php?q=$1', 'cquiz');
        $rules[] = new restore_decode_rule('QUIZINDEX',
                '/mod/cquiz/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * cquiz logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('cquiz', 'add',
                'view.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'update',
                'view.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'view',
                'view.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'preview',
                'view.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'report',
                'report.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'editquestions',
                'view.php?id={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('cquiz', 'edit override',
                'overrideedit.php?id={cquiz_override}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'delete override',
                'overrides.php.php?cmid={course_module}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('cquiz', 'view summary',
                'summary.php?attempt={cquiz_attempt_id}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'manualgrade',
                'comment.php?attempt={cquiz_attempt_id}&question={question}', '{cquiz}');
        $rules[] = new restore_log_rule('cquiz', 'manualgrading',
                'report.php?mode=grading&q={cquiz}', '{cquiz}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'cquiz_attempt_id' mapping because that is the
        // one containing the cquiz_attempt->ids old an new for cquiz-attempt.
        $rules[] = new restore_log_rule('cquiz', 'attempt',
                'review.php?id={course_module}&attempt={cquiz_attempt}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt}');
        // Old an new for cquiz-submit.
        $rules[] = new restore_log_rule('cquiz', 'submit',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'submit',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}');
        // Old an new for cquiz-review.
        $rules[] = new restore_log_rule('cquiz', 'review',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'review',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}');
        // Old an new for cquiz-start attemp.
        $rules[] = new restore_log_rule('cquiz', 'start attempt',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'start attempt',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}');
        // Old an new for cquiz-close attemp.
        $rules[] = new restore_log_rule('cquiz', 'close attempt',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'close attempt',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}');
        // Old an new for cquiz-continue attempt.
        $rules[] = new restore_log_rule('cquiz', 'continue attempt',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, null, 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'continue attempt',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}');
        // Old an new for cquiz-continue attemp.
        $rules[] = new restore_log_rule('cquiz', 'continue attemp',
                'review.php?id={course_module}&attempt={cquiz_attempt_id}', '{cquiz}',
                null, 'continue attempt', 'review.php?attempt={cquiz_attempt_id}');
        $rules[] = new restore_log_rule('cquiz', 'continue attemp',
                'review.php?attempt={cquiz_attempt_id}', '{cquiz}',
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

        $rules[] = new restore_log_rule('cquiz', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
