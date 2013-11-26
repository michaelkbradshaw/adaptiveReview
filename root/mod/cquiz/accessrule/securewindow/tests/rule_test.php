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
 * Unit tests for the cquizaccess_securewindow plugin.
 *
 * @package    cquizaccess
 * @subpackage securewindow
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/cquiz/accessrule/securewindow/rule.php');


/**
 * Unit tests for the cquizaccess_securewindow plugin.
 *
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquizaccess_securewindow_testcase extends basic_testcase {
    public static $includecoverage = array('mod/cquiz/accessrule/securewindow/rule.php');

    // Nothing very testable in this class, just test that it obeys the general access rule contact.
    public function test_securewindow_access_rule() {
        $cquiz = new stdClass();
        $cquiz->browsersecurity = 'securewindow';
        $cquiz->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $cquizobj = new cquiz($cquiz, $cm, null);
        $rule = new cquizaccess_securewindow($cquizobj, 0);
        $attempt = new stdClass();

        $this->assertFalse($rule->prevent_access());
        $this->assertEmpty($rule->description());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }
}
