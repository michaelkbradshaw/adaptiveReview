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
 * Quiz attempt overdue handling tests
 *
 * @package    mod_areview
 * @category   phpunit
 * @copyright  2012 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/group/lib.php');

/**
 * Unit tests for areview attempt overdue handling
 *
 * @package    mod_areview
 * @category   phpunit
 * @copyright  2012 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_areview_attempt_overdue_testcase extends advanced_testcase {
    /**
     * Test the functions areview_update_open_attempts() and get_list_of_overdue_attempts()
     */
    public function test_bulk_update_functions() {
        global $DB,$CFG;

        require_once($CFG->dirroot.'/mod/areview/cronlib.php');

        $this->resetAfterTest();

        $this->setAdminUser();

        // Setup course, user and groups

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $this->assertTrue(enrol_try_internal_enrol($course->id, $user1->id, $studentrole->id));
        $group1 = $this->getDataGenerator()->create_group(array('courseid'=>$course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid'=>$course->id));
        $group3 = $this->getDataGenerator()->create_group(array('courseid'=>$course->id));
        $this->assertTrue(groups_add_member($group1, $user1));
        $this->assertTrue(groups_add_member($group2, $user1));

        $uniqueid = 0;
        $usertimes = array();

        $areview_generator = $this->getDataGenerator()->get_plugin_generator('mod_areview');

        // Basic areview settings

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>600, 'message'=>'Test1A');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>1800));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>1800, 'message'=>'Test1B');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>0, 'message'=>'Test1C');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>600));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>600, 'message'=>'Test1D');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>0, 'message'=>'Test1E');

        // Group overrides

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>null));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>0, 'message'=>'Test2A');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>0));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1100, 'timelimit'=>null));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1100, 'timelimit'=>0, 'message'=>'Test2B');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>null, 'timelimit'=>700));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>700, 'message'=>'Test2C');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>null, 'timelimit'=>500));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>500, 'message'=>'Test2D');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>null, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>0, '', 'message'=>'Test2E');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>500));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>500, '', 'message'=>'Test2F');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>500, '', 'message'=>'Test2G');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group3->id, 'timeclose'=>1300, 'timelimit'=>500)); // user not in group
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>600, '', 'message'=>'Test2H');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>600, '', 'message'=>'Test2I');

        // Multiple group overrides

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>501));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1301, 'timelimit'=>500));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3A');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3B');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1301, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1300, 'timelimit'=>501));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3C');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3D');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1301, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1300, 'timelimit'=>501));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group3->id, 'timeclose'=>1500, 'timelimit'=>1000)); // user not in group
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3E');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>501, '', 'message'=>'Test3F');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>null, 'timelimit'=>501));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>501, '', 'message'=>'Test3G');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>501, '', 'message'=>'Test3H');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1301, 'timelimit'=>null));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>500, '', 'message'=>'Test3I');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>500, '', 'message'=>'Test3J');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1301, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>0, '', 'message'=>'Test3K');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1301, 'timelimit'=>0, '', 'message'=>'Test3L');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>500));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>0, 'timelimit'=>501));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>501, '', 'message'=>'Test3M');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>501, '', 'message'=>'Test3N');

        // User overrides

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>1201, 'timelimit'=>601));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>601, '', 'message'=>'Test4A');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>601, '', 'message'=>'Test4B');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>0, 'timelimit'=>601));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>601, '', 'message'=>'Test4C');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>601, '', 'message'=>'Test4D');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>1201, 'timelimit'=>0));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>0, '', 'message'=>'Test4E');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>0, '', 'message'=>'Test4F');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>null, 'timelimit'=>601));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>601, '', 'message'=>'Test4G');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>601, '', 'message'=>'Test4H');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>null, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>null, 'timelimit'=>601));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>601, '', 'message'=>'Test4I');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>601, '', 'message'=>'Test4J');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>1201, 'timelimit'=>null));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>700, '', 'message'=>'Test4K');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>700, '', 'message'=>'Test4L');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>null));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>$user1->id, 'timeclose'=>1201, 'timelimit'=>null));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>600, '', 'message'=>'Test4M');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1201, 'timelimit'=>600, '', 'message'=>'Test4N');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>700));
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'userid'=>0, 'timeclose'=>1201, 'timelimit'=>601)); // not user
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>700, '', 'message'=>'Test4O');
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>1000, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++, 'attempt'=>1));
        $usertimes[$attemptid] = array('timeclose'=>1300, 'timelimit'=>700, '', 'message'=>'Test4P');

        // Attempt state overdue

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>600, 'overduehandling'=>'graceperiod', 'graceperiod'=>250));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'overdue', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>1200, 'timelimit'=>600, '', 'message'=>'Test5A');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>0, 'timelimit'=>600, 'overduehandling'=>'graceperiod', 'graceperiod'=>250));
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'overdue', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));
        $usertimes[$attemptid] = array('timeclose'=>0, 'timelimit'=>600, '', 'message'=>'Test5B');

        //
        // Test areview_update_open_attempts()
        //

        areview_update_open_attempts(array('courseid'=>$course->id));
        foreach ($usertimes as $attemptid=>$times) {
            $attempt = $DB->get_record('areview_attempts', array('id'=>$attemptid));
            $this->assertTrue(false !== $attempt, $times['message']);

            if ($attempt->state == 'overdue') {
                $graceperiod = $DB->get_field('areview', 'graceperiod', array('id'=>$attempt->areview));
            } else {
                $graceperiod = 0;
            }
            if ($times['timeclose'] > 0 and $times['timelimit'] > 0) {
                $this->assertEquals(min($times['timeclose'], $attempt->timestart + $times['timelimit']) + $graceperiod, $attempt->timecheckstate, $times['message']);
            } else if ($times['timeclose'] > 0) {
                $this->assertEquals($times['timeclose'] + $graceperiod, $attempt->timecheckstate <= $times['timeclose'], $times['message']);
            } else if ($times['timelimit'] > 0) {
                $this->assertEquals($attempt->timestart + $times['timelimit'] + $graceperiod, $attempt->timecheckstate, $times['message']);
            } else {
                $this->assertNull($attempt->timecheckstate, $times['message']);
            }
        }

        //
        // Test get_list_of_overdue_attempts()
        //

        $overduehander = new mod_areview_overdue_attempt_updater();

        $attempts = $overduehander->get_list_of_overdue_attempts(100000); // way in the future
        $count = 0;
        foreach ($attempts as $attempt) {
            $this->assertTrue(isset($usertimes[$attempt->id]));
            $times = $usertimes[$attempt->id];
            $this->assertEquals($times['timeclose'], $attempt->usertimeclose, $times['message']);
            $this->assertEquals($times['timelimit'], $attempt->usertimelimit, $times['message']);
            $count++;

        }
        $this->assertEquals($DB->count_records_select('areview_attempts', 'timecheckstate IS NOT NULL'), $count);

        $attempts = $overduehander->get_list_of_overdue_attempts(0); // before all attempts
        $count = 0;
        foreach ($attempts as $attempt) {
            $count++;
        }
        $this->assertEquals(0, $count);

    }

    /**
     * Test the group event handlers
     */
    public function test_group_event_handlers() {
        global $DB,$CFG;

        $this->resetAfterTest();

        $this->setAdminUser();

        // Setup course, user and groups

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        $this->assertNotEmpty($studentrole);
        $this->assertTrue(enrol_try_internal_enrol($course->id, $user1->id, $studentrole->id));
        $group1 = $this->getDataGenerator()->create_group(array('courseid'=>$course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid'=>$course->id));
        $this->assertTrue(groups_add_member($group1, $user1));
        $this->assertTrue(groups_add_member($group2, $user1));

        $uniqueid = 0;

        $areview_generator = $this->getDataGenerator()->get_plugin_generator('mod_areview');

        $areview = $areview_generator->create_instance(array('course'=>$course->id, 'timeclose'=>1200, 'timelimit'=>0));

        // add a group1 override
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group1->id, 'timeclose'=>1300, 'timelimit'=>null));

        // add an attempt
        $attemptid = $DB->insert_record('areview_attempts', array('areview'=>$areview->id, 'userid'=>$user1->id, 'state'=>'inprogress', 'timestart'=>100, 'timecheckstate'=>0, 'layout'=>'', 'uniqueid'=>$uniqueid++));

        // update timecheckstate
        areview_update_open_attempts(array('areviewid'=>$areview->id));
        $this->assertEquals(1300, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // remove from group
        $this->assertTrue(groups_remove_member($group1, $user1));
        $this->assertEquals(1200, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // add back to group
        $this->assertTrue(groups_add_member($group1, $user1));
        $this->assertEquals(1300, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // delete group
        groups_delete_group($group1);
        $this->assertEquals(1200, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));
        $this->assertEquals(0, $DB->count_records('areview_overrides', array('areview'=>$areview->id)));

        // add a group2 override
        $DB->insert_record('areview_overrides', array('areview'=>$areview->id, 'groupid'=>$group2->id, 'timeclose'=>1400, 'timelimit'=>null));
        areview_update_open_attempts(array('areviewid'=>$areview->id));
        $this->assertEquals(1400, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // delete user1 from all groups
        groups_delete_group_members($course->id, $user1->id);
        $this->assertEquals(1200, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // add back to group2
        $this->assertTrue(groups_add_member($group2, $user1));
        $this->assertEquals(1400, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));

        // delete everyone from all groups
        groups_delete_group_members($course->id);
        $this->assertEquals(1200, $DB->get_field('areview_attempts', 'timecheckstate', array('id'=>$attemptid)));
    }
}