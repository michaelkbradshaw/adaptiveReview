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
 * Defines the base class for areview access plugins backup code.
 *
 * @package    mod
 * @subpackage areview
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Base class for backing up all the areview settings and attempt data for an
 * access rule areview sub-plugin.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_mod_areview_access_subplugin extends backup_subplugin {

    /**
     * Use this method to describe the XML structure required to store your
     * sub-plugin's settings for a particular areview, and how that data is stored
     * in the database.
     */
    protected function define_areview_subplugin_structure() {
        // Do nothing by default.
    }

    /**
     * Use this method to describe the XML structure required to store your
     * sub-plugin's settings for a particular areview attempt, and how that data
     * is stored in the database.
     */
    protected function define_attempt_subplugin_structure() {
        // Do nothing by default.
    }
}
