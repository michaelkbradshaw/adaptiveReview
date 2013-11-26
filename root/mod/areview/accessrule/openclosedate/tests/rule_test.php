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
 * Unit tests for the areviewaccess_openclosedate plugin.
 *
 * @package    areviewaccess
 * @subpackage openclosedate
 * @category   phpunit
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/areview/accessrule/openclosedate/rule.php');


/**
 * Unit tests for the areviewaccess_openclosedate plugin.
 *
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class areviewaccess_openclosedate_testcase extends basic_testcase {
    public function test_no_dates() {
        $areview = new stdClass();
        $areview->timeopen = 0;
        $areview->timeclose = 0;
        $areview->overduehandling = 'autosubmit';
        $areview->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $areviewobj = new areview($areview, $cm, null);
        $attempt = new stdClass();
        $attempt->preview = 0;

        $rule = new areviewaccess_openclosedate($areviewobj, 10000);
        $this->assertEmpty($rule->description());
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 10000));
        $this->assertFalse($rule->time_left_display($attempt, 0));

        $rule = new areviewaccess_openclosedate($areviewobj, 0);
        $this->assertEmpty($rule->description());
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }

    public function test_start_date() {
        $areview = new stdClass();
        $areview->timeopen = 10000;
        $areview->timeclose = 0;
        $areview->overduehandling = 'autosubmit';
        $areview->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $areviewobj = new areview($areview, $cm, null);
        $attempt = new stdClass();
        $attempt->preview = 0;

        $rule = new areviewaccess_openclosedate($areviewobj, 9999);
        $this->assertEquals($rule->description(),
            array(get_string('areviewnotavailable', 'areviewaccess_openclosedate', userdate(10000))));
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'areviewaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));

        $rule = new areviewaccess_openclosedate($areviewobj, 10000);
        $this->assertEquals($rule->description(),
            array(get_string('areviewopenedon', 'areview', userdate(10000))));
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }

    public function test_close_date() {
        $areview = new stdClass();
        $areview->timeopen = 0;
        $areview->timeclose = 20000;
        $areview->overduehandling = 'autosubmit';
        $areview->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $areviewobj = new areview($areview, $cm, null);
        $attempt = new stdClass();
        $attempt->preview = 0;

        $rule = new areviewaccess_openclosedate($areviewobj, 20000);
        $this->assertEquals($rule->description(),
            array(get_string('areviewcloseson', 'areview', userdate(20000))));
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - areview_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);

        $rule = new areviewaccess_openclosedate($areviewobj, 20001);
        $this->assertEquals($rule->description(),
            array(get_string('areviewclosed', 'areview', userdate(20000))));
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'areviewaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertTrue($rule->is_finished(0, $attempt));
        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - areview_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);
    }

    public function test_both_dates() {
        $areview = new stdClass();
        $areview->timeopen = 10000;
        $areview->timeclose = 20000;
        $areview->overduehandling = 'autosubmit';
        $areview->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $areviewobj = new areview($areview, $cm, null);
        $attempt = new stdClass();
        $attempt->preview = 0;

        $rule = new areviewaccess_openclosedate($areviewobj, 9999);
        $this->assertEquals($rule->description(),
            array(get_string('areviewnotavailable', 'areviewaccess_openclosedate', userdate(10000)),
                    get_string('areviewcloseson', 'areview', userdate(20000))));
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'areviewaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new areviewaccess_openclosedate($areviewobj, 10000);
        $this->assertEquals($rule->description(),
            array(get_string('areviewopenedon', 'areview', userdate(10000)),
                get_string('areviewcloseson', 'areview', userdate(20000))));
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new areviewaccess_openclosedate($areviewobj, 20000);
        $this->assertEquals($rule->description(),
            array(get_string('areviewopenedon', 'areview', userdate(10000)),
                get_string('areviewcloseson', 'areview', userdate(20000))));
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new areviewaccess_openclosedate($areviewobj, 20001);
        $this->assertEquals($rule->description(),
            array(get_string('areviewclosed', 'areview', userdate(20000))));
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'areviewaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertTrue($rule->is_finished(0, $attempt));

        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - areview_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);
    }

    public function test_close_date_with_overdue() {
        $areview = new stdClass();
        $areview->timeopen = 0;
        $areview->timeclose = 20000;
        $areview->overduehandling = 'graceperiod';
        $areview->graceperiod = 1000;
        $areview->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $areviewobj = new areview($areview, $cm, null);
        $attempt = new stdClass();
        $attempt->preview = 0;

        $rule = new areviewaccess_openclosedate($areviewobj, 20000);
        $this->assertFalse($rule->prevent_access());

        $rule = new areviewaccess_openclosedate($areviewobj, 20001);
        $this->assertFalse($rule->prevent_access());

        $rule = new areviewaccess_openclosedate($areviewobj, 21000);
        $this->assertFalse($rule->prevent_access());

        $rule = new areviewaccess_openclosedate($areviewobj, 21001);
        $this->assertEquals($rule->prevent_access(),
                get_string('notavailable', 'areviewaccess_openclosedate'));
    }
}
