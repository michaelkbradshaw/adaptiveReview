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
 * Implementaton of the areviewaccess_openclosedate plugin.
 *
 * @package    areviewaccess
 * @subpackage openclosedate
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/accessrule/accessrulebase.php');


/**
 * A rule enforcing open and close dates.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class areviewaccess_openclosedate extends areview_access_rule_base {

    public static function make(areview $areviewobj, $timenow, $canignoretimelimits) {
        // This rule is always used, even if the areview has no open or close date.
        return new self($areviewobj, $timenow);
    }

    public function description() {
        $result = array();
        if ($this->timenow < $this->areview->timeopen) {
            $result[] = get_string('areviewnotavailable', 'areviewaccess_openclosedate',
                    userdate($this->areview->timeopen));
            if ($this->areview->timeclose) {
                $result[] = get_string('areviewcloseson', 'areview', userdate($this->areview->timeclose));
            }

        } else if ($this->areview->timeclose && $this->timenow > $this->areview->timeclose) {
            $result[] = get_string('areviewclosed', 'areview', userdate($this->areview->timeclose));

        } else {
            if ($this->areview->timeopen) {
                $result[] = get_string('areviewopenedon', 'areview', userdate($this->areview->timeopen));
            }
            if ($this->areview->timeclose) {
                $result[] = get_string('areviewcloseson', 'areview', userdate($this->areview->timeclose));
            }
        }

        return $result;
    }

    public function prevent_access() {
        $message = get_string('notavailable', 'areviewaccess_openclosedate');

        if ($this->timenow < $this->areview->timeopen) {
            return $message;
        }

        if (!$this->areview->timeclose) {
            return false;
        }

        if ($this->timenow <= $this->areview->timeclose) {
            return false;
        }

        if ($this->areview->overduehandling != 'graceperiod') {
            return $message;
        }

        if ($this->timenow <= $this->areview->timeclose + $this->areview->graceperiod) {
            return false;
        }

        return $message;
    }

    public function is_finished($numprevattempts, $lastattempt) {
        return $this->areview->timeclose && $this->timenow > $this->areview->timeclose;
    }

    public function end_time($attempt) {
        if ($this->areview->timeclose) {
            return $this->areview->timeclose;
        }
        return false;
    }

    public function time_left_display($attempt, $timenow) {
        // If this is a teacher preview after the close date, do not show
        // the time.
        if ($attempt->preview && $timenow > $this->areview->timeclose) {
            return false;
        }
        // Otherwise, return to the time left until the close date, providing that is
        // less than areview_SHOW_TIME_BEFORE_DEADLINE.
        $endtime = $this->end_time($attempt);
        if ($endtime !== false && $timenow > $endtime - areview_SHOW_TIME_BEFORE_DEADLINE) {
            return $endtime - $timenow;
        }
        return false;
    }
}
