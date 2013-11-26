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
 * Implementaton of the cquizaccess_securewindow plugin.
 *
 * @package    cquizaccess
 * @subpackage securewindow
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/accessrule/accessrulebase.php');


/**
 * A rule for ensuring that the cquiz is opened in a popup, with some JavaScript
 * to prevent copying and pasting, etc.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cquizaccess_securewindow extends cquiz_access_rule_base {
    /** @var array options that should be used for opening the secure popup. */
    protected static $popupoptions = array(
        'left' => 0,
        'top' => 0,
        'fullscreen' => true,
        'scrollbars' => true,
        'resizeable' => false,
        'directories' => false,
        'toolbar' => false,
        'titlebar' => false,
        'location' => false,
        'status' => false,
        'menubar' => false,
    );

    public static function make(cquiz $cquizobj, $timenow, $canignoretimelimits) {

        if ($cquizobj->get_cquiz()->browsersecurity !== 'securewindow') {
            return null;
        }

        return new self($cquizobj, $timenow);
    }

    public function attempt_must_be_in_popup() {
        return !$this->cquizobj->is_preview_user();
    }

    public function get_popup_options() {
        return self::$popupoptions;
    }

    public function setup_attempt_page($page) {
        $page->set_popup_notification_allowed(false); // Prevent message notifications.
        $page->set_title($this->cquizobj->get_course()->shortname . ': ' . $page->title);
        $page->set_cacheable(false);
        $page->set_pagelayout('secure');

        if ($this->cquizobj->is_preview_user()) {
            return;
        }

        $page->add_body_class('cquiz-secure-window');
        $page->requires->js_init_call('M.mod_cquiz.secure_window.init',
                null, false, cquiz_get_js_module());
    }

    /**
     * @return array key => lang string any choices to add to the cquiz Browser
     *      security settings menu.
     */
    public static function get_browser_security_choices() {
        return array('securewindow' =>
                get_string('popupwithjavascriptsupport', 'quizaccess_securewindow'));
    }
}
