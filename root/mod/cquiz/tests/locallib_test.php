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
 * Unit tests for (some of) mod/cquiz/locallib.php.
 *
 * @package    mod_cquiz
 * @category   phpunit
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/cquiz/locallib.php');


/**
 * Unit tests for (some of) mod/cquiz/locallib.php.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_cquiz_locallib_testcase extends basic_testcase {
    public function test_cquiz_questions_in_cquiz() {
        $this->assertEquals(cquiz_questions_in_cquiz(''), '');
        $this->assertEquals(cquiz_questions_in_cquiz('0'), '');
        $this->assertEquals(cquiz_questions_in_cquiz('0,0'), '');
        $this->assertEquals(cquiz_questions_in_cquiz('0,0,0'), '');
        $this->assertEquals(cquiz_questions_in_cquiz('1'), '1');
        $this->assertEquals(cquiz_questions_in_cquiz('1,2'), '1,2');
        $this->assertEquals(cquiz_questions_in_cquiz('1,0,2'), '1,2');
        $this->assertEquals(cquiz_questions_in_cquiz('0,1,0,0,2,0'), '1,2');
    }

    public function test_cquiz_number_of_pages() {
        $this->assertEquals(cquiz_number_of_pages('0'), 1);
        $this->assertEquals(cquiz_number_of_pages('0,0'), 2);
        $this->assertEquals(cquiz_number_of_pages('0,0,0'), 3);
        $this->assertEquals(cquiz_number_of_pages('1,0'), 1);
        $this->assertEquals(cquiz_number_of_pages('1,2,0'), 1);
        $this->assertEquals(cquiz_number_of_pages('1,0,2,0'), 2);
        $this->assertEquals(cquiz_number_of_pages('1,2,3,0'), 1);
        $this->assertEquals(cquiz_number_of_pages('1,2,3,0'), 1);
        $this->assertEquals(cquiz_number_of_pages('0,1,0,0,2,0'), 4);
    }

    public function test_cquiz_number_of_questions_in_cquiz() {
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('0'), 0);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('0,0'), 0);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('0,0,0'), 0);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('1,0'), 1);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('1,2,0'), 2);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('1,0,2,0'), 2);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('1,2,3,0'), 3);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('1,2,3,0'), 3);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('0,1,0,0,2,0'), 2);
        $this->assertEquals(cquiz_number_of_questions_in_cquiz('10,,0,0'), 1);
    }

    public function test_cquiz_clean_layout() {
        // Without stripping empty pages.
        $this->assertEquals(cquiz_clean_layout(',,1,,,2,,'), '1,2,0');
        $this->assertEquals(cquiz_clean_layout(''), '0');
        $this->assertEquals(cquiz_clean_layout('0'), '0');
        $this->assertEquals(cquiz_clean_layout('0,0'), '0,0');
        $this->assertEquals(cquiz_clean_layout('0,0,0'), '0,0,0');
        $this->assertEquals(cquiz_clean_layout('1'), '1,0');
        $this->assertEquals(cquiz_clean_layout('1,2'), '1,2,0');
        $this->assertEquals(cquiz_clean_layout('1,0,2'), '1,0,2,0');
        $this->assertEquals(cquiz_clean_layout('0,1,0,0,2,0'), '0,1,0,0,2,0');

        // With stripping empty pages.
        $this->assertEquals(cquiz_clean_layout('', true), '0');
        $this->assertEquals(cquiz_clean_layout('0', true), '0');
        $this->assertEquals(cquiz_clean_layout('0,0', true), '0');
        $this->assertEquals(cquiz_clean_layout('0,0,0', true), '0');
        $this->assertEquals(cquiz_clean_layout('1', true), '1,0');
        $this->assertEquals(cquiz_clean_layout('1,2', true), '1,2,0');
        $this->assertEquals(cquiz_clean_layout('1,0,2', true), '1,0,2,0');
        $this->assertEquals(cquiz_clean_layout('0,1,0,0,2,0', true), '1,0,2,0');
    }

    public function test_cquiz_repaginate() {
        // Test starting with 1 question per page.
        $this->assertEquals(cquiz_repaginate('1,0,2,0,3,0', 0), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('1,0,2,0,3,0', 3), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('1,0,2,0,3,0', 2), '1,2,0,3,0');
        $this->assertEquals(cquiz_repaginate('1,0,2,0,3,0', 1), '1,0,2,0,3,0');

        // Test starting with all on one page page.
        $this->assertEquals(cquiz_repaginate('1,2,3,0', 0), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('1,2,3,0', 3), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('1,2,3,0', 2), '1,2,0,3,0');
        $this->assertEquals(cquiz_repaginate('1,2,3,0', 1), '1,0,2,0,3,0');

        // Test single question case.
        $this->assertEquals(cquiz_repaginate('100,0', 0), '100,0');
        $this->assertEquals(cquiz_repaginate('100,0', 1), '100,0');

        // No questions case.
        $this->assertEquals(cquiz_repaginate('0', 0), '0');

        // Test empty pages are removed.
        $this->assertEquals(cquiz_repaginate('1,2,3,0,0,0', 0), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('1,0,0,0,2,3,0', 0), '1,2,3,0');
        $this->assertEquals(cquiz_repaginate('0,0,0,1,2,3,0', 0), '1,2,3,0');

        // Test shuffle option.
        $this->assertTrue(in_array(cquiz_repaginate('1,2,0', 0, true),
            array('1,2,0', '2,1,0')));
        $this->assertTrue(in_array(cquiz_repaginate('1,2,0', 1, true),
            array('1,0,2,0', '2,0,1,0')));
    }

    public function test_cquiz_rescale_grade() {
        $cquiz = new stdClass();
        $cquiz->decimalpoints = 2;
        $cquiz->questiondecimalpoints = 3;
        $cquiz->grade = 10;
        $cquiz->sumgrades = 10;
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, false), 0.12345678);
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, true), format_float(0.12, 2));
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, 'question'),
            format_float(0.123, 3));
        $cquiz->sumgrades = 5;
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, false), 0.24691356);
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, true), format_float(0.25, 2));
        $this->assertEquals(cquiz_rescale_grade(0.12345678, $cquiz, 'question'),
            format_float(0.247, 3));
    }

    public function test_cquiz_get_slot_for_question() {
        $cquiz = new stdClass();
        $cquiz->questions = '1,2,0,7,0';
        $this->assertEquals(1, cquiz_get_slot_for_question($cquiz, 1));
        $this->assertEquals(3, cquiz_get_slot_for_question($cquiz, 7));
    }

    public function test_cquiz_attempt_state_in_progress() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::IN_PROGRESS;
        $attempt->timefinish = 0;

        $cquiz = new stdClass();
        $cquiz->timeclose = 0;

        $this->assertEquals(mod_cquiz_display_options::DURING, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_recently_submitted() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 10;

        $cquiz = new stdClass();
        $cquiz->timeclose = 0;

        $this->assertEquals(mod_cquiz_display_options::IMMEDIATELY_AFTER, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_sumitted_cquiz_never_closes() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $cquiz = new stdClass();
        $cquiz->timeclose = 0;

        $this->assertEquals(mod_cquiz_display_options::LATER_WHILE_OPEN, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_sumitted_cquiz_closes_later() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $cquiz = new stdClass();
        $cquiz->timeclose = time() + 3600;

        $this->assertEquals(mod_cquiz_display_options::LATER_WHILE_OPEN, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_sumitted_cquiz_closed() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $cquiz = new stdClass();
        $cquiz->timeclose = time() - 3600;

        $this->assertEquals(mod_cquiz_display_options::AFTER_CLOSE, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_never_sumitted_cquiz_never_closes() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::ABANDONED;
        $attempt->timefinish = 1000; // A very long time ago!

        $cquiz = new stdClass();
        $cquiz->timeclose = 0;

        $this->assertEquals(mod_cquiz_display_options::LATER_WHILE_OPEN, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_never_sumitted_cquiz_closes_later() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $cquiz = new stdClass();
        $cquiz->timeclose = time() + 3600;

        $this->assertEquals(mod_cquiz_display_options::LATER_WHILE_OPEN, cquiz_attempt_state($cquiz, $attempt));
    }

    public function test_cquiz_attempt_state_never_sumitted_cquiz_closed() {
        $attempt = new stdClass();
        $attempt->state = cquiz_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $cquiz = new stdClass();
        $cquiz->timeclose = time() - 3600;

        $this->assertEquals(mod_cquiz_display_options::AFTER_CLOSE, cquiz_attempt_state($cquiz, $attempt));
    }
}
