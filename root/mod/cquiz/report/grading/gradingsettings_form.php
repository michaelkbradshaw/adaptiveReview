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
 * This file defines the setting form for the cquiz grading report.
 *
 * @package   cquiz_grading
 * @copyright 2010 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


/**
 * Quiz grading report settings form.
 *
 * @copyright 2010 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquiz_grading_settings_form extends moodleform {
    protected $includeauto;
    protected $hidden = array();
    protected $counts;
    protected $shownames;
    protected $showidnumbers;

    public function __construct($hidden, $counts, $shownames, $showidnumbers) {
        global $CFG;
        $this->includeauto = !empty($hidden['includeauto']);
        $this->hidden = $hidden;
        $this->counts = $counts;
        $this->shownames = $shownames;
        $this->showidnumbers = $showidnumbers;
        parent::__construct($CFG->wwwroot . '/mod/cquiz/report.php', null, 'get');
    }

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'options', get_string('options', 'cquiz_grading'));

        $gradeoptions = array();
        foreach (array('needsgrading', 'manuallygraded', 'autograded', 'all') as $type) {
            if (empty($this->counts->$type)) {
                continue;
            }
            if ($type == 'autograded' && !$this->includeauto) {
                continue;
            }
            $gradeoptions[$type] = get_string('gradeattempts' . $type, 'cquiz_grading',
                    $this->counts->$type);
        }
        $mform->addElement('select', 'grade', get_string('attemptstograde', 'cquiz_grading'),
                $gradeoptions);

        $mform->addElement('text', 'pagesize', get_string('questionsperpage', 'cquiz_grading'),
                array('size' => 3));
        $mform->setType('pagesize', PARAM_INT);

        $orderoptions = array(
            'random' => get_string('randomly', 'cquiz_grading'),
            'date' => get_string('bydate', 'cquiz_grading'),
        );
        if ($this->shownames) {
            $orderoptions['student'] = get_string('bystudentname', 'cquiz_grading');
        }
        if ($this->showidnumbers) {
            $orderoptions['idnumber'] = get_string('bystudentidnumber', 'cquiz_grading');
        }
        $mform->addElement('select', 'order', get_string('orderattempts', 'cquiz_grading'),
                $orderoptions);

        foreach ($this->hidden as $name => $value) {
            $mform->addElement('hidden', $name, $value);
        }

        $mform->addElement('submit', 'submitbutton', get_string('changeoptions', 'cquiz_grading'));
    }
}
