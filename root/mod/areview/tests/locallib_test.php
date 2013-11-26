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
 * Unit tests for (some of) mod/areview/locallib.php.
 *
 * @package    mod_areview
 * @category   phpunit
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/areview/locallib.php');


/**
 * Unit tests for (some of) mod/areview/locallib.php.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_areview_locallib_testcase extends basic_testcase {
    public function test_areview_questions_in_areview() {
        $this->assertEquals(areview_questions_in_areview(''), '');
        $this->assertEquals(areview_questions_in_areview('0'), '');
        $this->assertEquals(areview_questions_in_areview('0,0'), '');
        $this->assertEquals(areview_questions_in_areview('0,0,0'), '');
        $this->assertEquals(areview_questions_in_areview('1'), '1');
        $this->assertEquals(areview_questions_in_areview('1,2'), '1,2');
        $this->assertEquals(areview_questions_in_areview('1,0,2'), '1,2');
        $this->assertEquals(areview_questions_in_areview('0,1,0,0,2,0'), '1,2');
    }

    public function test_areview_number_of_pages() {
        $this->assertEquals(areview_number_of_pages('0'), 1);
        $this->assertEquals(areview_number_of_pages('0,0'), 2);
        $this->assertEquals(areview_number_of_pages('0,0,0'), 3);
        $this->assertEquals(areview_number_of_pages('1,0'), 1);
        $this->assertEquals(areview_number_of_pages('1,2,0'), 1);
        $this->assertEquals(areview_number_of_pages('1,0,2,0'), 2);
        $this->assertEquals(areview_number_of_pages('1,2,3,0'), 1);
        $this->assertEquals(areview_number_of_pages('1,2,3,0'), 1);
        $this->assertEquals(areview_number_of_pages('0,1,0,0,2,0'), 4);
    }

    public function test_areview_number_of_questions_in_areview() {
        $this->assertEquals(areview_number_of_questions_in_areview('0'), 0);
        $this->assertEquals(areview_number_of_questions_in_areview('0,0'), 0);
        $this->assertEquals(areview_number_of_questions_in_areview('0,0,0'), 0);
        $this->assertEquals(areview_number_of_questions_in_areview('1,0'), 1);
        $this->assertEquals(areview_number_of_questions_in_areview('1,2,0'), 2);
        $this->assertEquals(areview_number_of_questions_in_areview('1,0,2,0'), 2);
        $this->assertEquals(areview_number_of_questions_in_areview('1,2,3,0'), 3);
        $this->assertEquals(areview_number_of_questions_in_areview('1,2,3,0'), 3);
        $this->assertEquals(areview_number_of_questions_in_areview('0,1,0,0,2,0'), 2);
        $this->assertEquals(areview_number_of_questions_in_areview('10,,0,0'), 1);
    }

    public function test_areview_clean_layout() {
        // Without stripping empty pages.
        $this->assertEquals(areview_clean_layout(',,1,,,2,,'), '1,2,0');
        $this->assertEquals(areview_clean_layout(''), '0');
        $this->assertEquals(areview_clean_layout('0'), '0');
        $this->assertEquals(areview_clean_layout('0,0'), '0,0');
        $this->assertEquals(areview_clean_layout('0,0,0'), '0,0,0');
        $this->assertEquals(areview_clean_layout('1'), '1,0');
        $this->assertEquals(areview_clean_layout('1,2'), '1,2,0');
        $this->assertEquals(areview_clean_layout('1,0,2'), '1,0,2,0');
        $this->assertEquals(areview_clean_layout('0,1,0,0,2,0'), '0,1,0,0,2,0');

        // With stripping empty pages.
        $this->assertEquals(areview_clean_layout('', true), '0');
        $this->assertEquals(areview_clean_layout('0', true), '0');
        $this->assertEquals(areview_clean_layout('0,0', true), '0');
        $this->assertEquals(areview_clean_layout('0,0,0', true), '0');
        $this->assertEquals(areview_clean_layout('1', true), '1,0');
        $this->assertEquals(areview_clean_layout('1,2', true), '1,2,0');
        $this->assertEquals(areview_clean_layout('1,0,2', true), '1,0,2,0');
        $this->assertEquals(areview_clean_layout('0,1,0,0,2,0', true), '1,0,2,0');
    }

    public function test_areview_repaginate() {
        // Test starting with 1 question per page.
        $this->assertEquals(areview_repaginate('1,0,2,0,3,0', 0), '1,2,3,0');
        $this->assertEquals(areview_repaginate('1,0,2,0,3,0', 3), '1,2,3,0');
        $this->assertEquals(areview_repaginate('1,0,2,0,3,0', 2), '1,2,0,3,0');
        $this->assertEquals(areview_repaginate('1,0,2,0,3,0', 1), '1,0,2,0,3,0');

        // Test starting with all on one page page.
        $this->assertEquals(areview_repaginate('1,2,3,0', 0), '1,2,3,0');
        $this->assertEquals(areview_repaginate('1,2,3,0', 3), '1,2,3,0');
        $this->assertEquals(areview_repaginate('1,2,3,0', 2), '1,2,0,3,0');
        $this->assertEquals(areview_repaginate('1,2,3,0', 1), '1,0,2,0,3,0');

        // Test single question case.
        $this->assertEquals(areview_repaginate('100,0', 0), '100,0');
        $this->assertEquals(areview_repaginate('100,0', 1), '100,0');

        // No questions case.
        $this->assertEquals(areview_repaginate('0', 0), '0');

        // Test empty pages are removed.
        $this->assertEquals(areview_repaginate('1,2,3,0,0,0', 0), '1,2,3,0');
        $this->assertEquals(areview_repaginate('1,0,0,0,2,3,0', 0), '1,2,3,0');
        $this->assertEquals(areview_repaginate('0,0,0,1,2,3,0', 0), '1,2,3,0');

        // Test shuffle option.
        $this->assertTrue(in_array(areview_repaginate('1,2,0', 0, true),
            array('1,2,0', '2,1,0')));
        $this->assertTrue(in_array(areview_repaginate('1,2,0', 1, true),
            array('1,0,2,0', '2,0,1,0')));
    }

    public function test_areview_rescale_grade() {
        $areview = new stdClass();
        $areview->decimalpoints = 2;
        $areview->questiondecimalpoints = 3;
        $areview->grade = 10;
        $areview->sumgrades = 10;
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, false), 0.12345678);
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, true), format_float(0.12, 2));
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, 'question'),
            format_float(0.123, 3));
        $areview->sumgrades = 5;
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, false), 0.24691356);
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, true), format_float(0.25, 2));
        $this->assertEquals(areview_rescale_grade(0.12345678, $areview, 'question'),
            format_float(0.247, 3));
    }

    public function test_areview_get_slot_for_question() {
        $areview = new stdClass();
        $areview->questions = '1,2,0,7,0';
        $this->assertEquals(1, areview_get_slot_for_question($areview, 1));
        $this->assertEquals(3, areview_get_slot_for_question($areview, 7));
    }

    public function test_areview_attempt_state_in_progress() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::IN_PROGRESS;
        $attempt->timefinish = 0;

        $areview = new stdClass();
        $areview->timeclose = 0;

        $this->assertEquals(mod_areview_display_options::DURING, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_recently_submitted() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::FINISHED;
        $attempt->timefinish = time() - 10;

        $areview = new stdClass();
        $areview->timeclose = 0;

        $this->assertEquals(mod_areview_display_options::IMMEDIATELY_AFTER, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_sumitted_areview_never_closes() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $areview = new stdClass();
        $areview->timeclose = 0;

        $this->assertEquals(mod_areview_display_options::LATER_WHILE_OPEN, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_sumitted_areview_closes_later() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $areview = new stdClass();
        $areview->timeclose = time() + 3600;

        $this->assertEquals(mod_areview_display_options::LATER_WHILE_OPEN, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_sumitted_areview_closed() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $areview = new stdClass();
        $areview->timeclose = time() - 3600;

        $this->assertEquals(mod_areview_display_options::AFTER_CLOSE, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_never_sumitted_areview_never_closes() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::ABANDONED;
        $attempt->timefinish = 1000; // A very long time ago!

        $areview = new stdClass();
        $areview->timeclose = 0;

        $this->assertEquals(mod_areview_display_options::LATER_WHILE_OPEN, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_never_sumitted_areview_closes_later() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $areview = new stdClass();
        $areview->timeclose = time() + 3600;

        $this->assertEquals(mod_areview_display_options::LATER_WHILE_OPEN, areview_attempt_state($areview, $attempt));
    }

    public function test_areview_attempt_state_never_sumitted_areview_closed() {
        $attempt = new stdClass();
        $attempt->state = areview_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $areview = new stdClass();
        $areview->timeclose = time() - 3600;

        $this->assertEquals(mod_areview_display_options::AFTER_CLOSE, areview_attempt_state($areview, $attempt));
    }
}
