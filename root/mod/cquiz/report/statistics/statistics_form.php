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
 * Quiz statistics settings form definition.
 *
 * @package   cquiz_statistics
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


/**
 * This is the settings form for the cquiz statistics report.
 *
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquiz_statistics_settings_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'preferencespage',
                get_string('preferencespage', 'cquiz_overview'));

        $options = array();
        $options[0] = get_string('attemptsfirst', 'cquiz_statistics');
        $options[1] = get_string('attemptsall', 'cquiz_statistics');
        $mform->addElement('select', 'useallattempts',
                get_string('calculatefrom', 'cquiz_statistics'), $options);
        $mform->setDefault('useallattempts', 0);

        $mform->addElement('submit', 'submitbutton',
                get_string('preferencessave', 'cquiz_overview'));
    }
}
