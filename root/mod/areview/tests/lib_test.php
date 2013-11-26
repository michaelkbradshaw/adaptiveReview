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
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/areview/lib.php');


/**
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_areview_lib_testcase extends basic_testcase {
    public function test_areview_has_grades() {
        $areview = new stdClass();
        $areview->grade = '100.0000';
        $areview->sumgrades = '100.0000';
        $this->assertTrue(areview_has_grades($areview));
        $areview->sumgrades = '0.0000';
        $this->assertFalse(areview_has_grades($areview));
        $areview->grade = '0.0000';
        $this->assertFalse(areview_has_grades($areview));
        $areview->sumgrades = '100.0000';
        $this->assertFalse(areview_has_grades($areview));
    }

    public function test_areview_format_grade() {
        $areview = new stdClass();
        $areview->decimalpoints = 2;
        $this->assertEquals(areview_format_grade($areview, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(areview_format_grade($areview, 0), format_float(0, 2));
        $this->assertEquals(areview_format_grade($areview, 1.000000000000), format_float(1, 2));
        $areview->decimalpoints = 0;
        $this->assertEquals(areview_format_grade($areview, 0.12345678), '0');
    }

    public function test_areview_format_question_grade() {
        $areview = new stdClass();
        $areview->decimalpoints = 2;
        $areview->questiondecimalpoints = 2;
        $this->assertEquals(areview_format_question_grade($areview, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(areview_format_question_grade($areview, 0), format_float(0, 2));
        $this->assertEquals(areview_format_question_grade($areview, 1.000000000000), format_float(1, 2));
        $areview->decimalpoints = 3;
        $areview->questiondecimalpoints = -1;
        $this->assertEquals(areview_format_question_grade($areview, 0.12345678), format_float(0.123, 3));
        $this->assertEquals(areview_format_question_grade($areview, 0), format_float(0, 3));
        $this->assertEquals(areview_format_question_grade($areview, 1.000000000000), format_float(1, 3));
        $areview->questiondecimalpoints = 4;
        $this->assertEquals(areview_format_question_grade($areview, 0.12345678), format_float(0.1235, 4));
        $this->assertEquals(areview_format_question_grade($areview, 0), format_float(0, 4));
        $this->assertEquals(areview_format_question_grade($areview, 1.000000000000), format_float(1, 4));
    }
}
