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
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/cquiz/lib.php');


/**
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_cquiz_lib_testcase extends basic_testcase {
    public function test_cquiz_has_grades() {
        $cquiz = new stdClass();
        $cquiz->grade = '100.0000';
        $cquiz->sumgrades = '100.0000';
        $this->assertTrue(cquiz_has_grades($cquiz));
        $cquiz->sumgrades = '0.0000';
        $this->assertFalse(cquiz_has_grades($cquiz));
        $cquiz->grade = '0.0000';
        $this->assertFalse(cquiz_has_grades($cquiz));
        $cquiz->sumgrades = '100.0000';
        $this->assertFalse(cquiz_has_grades($cquiz));
    }

    public function test_cquiz_format_grade() {
        $cquiz = new stdClass();
        $cquiz->decimalpoints = 2;
        $this->assertEquals(cquiz_format_grade($cquiz, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(cquiz_format_grade($cquiz, 0), format_float(0, 2));
        $this->assertEquals(cquiz_format_grade($cquiz, 1.000000000000), format_float(1, 2));
        $cquiz->decimalpoints = 0;
        $this->assertEquals(cquiz_format_grade($cquiz, 0.12345678), '0');
    }

    public function test_cquiz_format_question_grade() {
        $cquiz = new stdClass();
        $cquiz->decimalpoints = 2;
        $cquiz->questiondecimalpoints = 2;
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0), format_float(0, 2));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 1.000000000000), format_float(1, 2));
        $cquiz->decimalpoints = 3;
        $cquiz->questiondecimalpoints = -1;
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0.12345678), format_float(0.123, 3));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0), format_float(0, 3));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 1.000000000000), format_float(1, 3));
        $cquiz->questiondecimalpoints = 4;
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0.12345678), format_float(0.1235, 4));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 0), format_float(0, 4));
        $this->assertEquals(cquiz_format_question_grade($cquiz, 1.000000000000), format_float(1, 4));
    }
}
