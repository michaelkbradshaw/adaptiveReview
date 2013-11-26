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
 * Administration settings definitions for the cquiz module.
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  2010 Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/cquiz/lib.php');
require_once($CFG->dirroot . '/mod/cquiz/settingslib.php');

// First get a list of cquiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = get_plugin_list_with_file('cquiz', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'cquiz_'.$report);
    $reportsbyname[$strreportname] = $report;
}
collatorlib::ksort($reportsbyname);

// First get a list of cquiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = get_plugin_list_with_file('cquizaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'cquizaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
collatorlib::ksort($rulesbyname);

// Create the cquiz settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'cquiz');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$cquizsettings = new admin_settingpage('modsettingcquiz', $pagetitle, 'moodle/site:config');

// Introductory explanation that all the settings are defaults for the add cquiz form.
$cquizsettings->add(new admin_setting_heading('cquizintro', '', get_string('configintro', 'cquiz')));

// Time limit.
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/timelimit',
        get_string('timelimitsec', 'cquiz'), get_string('configtimelimitsec', 'cquiz'),
        array('value' => '0', 'adv' => false), PARAM_INT));

// What to do with overdue attempts.
$cquizsettings->add(new mod_cquiz_admin_setting_overduehandling('cquiz/overduehandling',
        get_string('overduehandling', 'cquiz'), get_string('overduehandling_desc', 'cquiz'),
        array('value' => 'autoabandon', 'adv' => false), null));

// Grace period time.
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/graceperiod',
        get_string('graceperiod', 'cquiz'), get_string('graceperiod_desc', 'cquiz'),
        array('value' => '86400', 'adv' => false), PARAM_INT));

// Minimum grace period used behind the scenes.
$cquizsettings->add(new admin_setting_configtext('cquiz/graceperiodmin',
        get_string('graceperiodmin', 'cquiz'), get_string('graceperiodmin_desc', 'cquiz'),
        60, PARAM_INT));

// Number of attempts.
$options = array(get_string('unlimited'));
for ($i = 1; $i <= CQUIZ_MAX_ATTEMPT_OPTION; $i++) {
    $options[$i] = $i;
}
$cquizsettings->add(new admin_setting_configselect_with_advanced('cquiz/attempts',
        get_string('attemptsallowed', 'cquiz'), get_string('configattemptsallowed', 'cquiz'),
        array('value' => 0, 'adv' => false), $options));

// Grading method.
$cquizsettings->add(new mod_cquiz_admin_setting_grademethod('cquiz/grademethod',
        get_string('grademethod', 'cquiz'), get_string('configgrademethod', 'cquiz'),
        array('value' => CQUIZ_GRADEHIGHEST, 'adv' => false), null));

// Maximum grade.
$cquizsettings->add(new admin_setting_configtext('cquiz/maximumgrade',
        get_string('maximumgrade'), get_string('configmaximumgrade', 'cquiz'), 10, PARAM_INT));

// Shuffle questions.
$cquizsettings->add(new admin_setting_configcheckbox_with_advanced('cquiz/shufflequestions',
        get_string('shufflequestions', 'cquiz'), get_string('configshufflequestions', 'cquiz'),
        array('value' => 0, 'adv' => false)));

// Questions per page.
$perpage = array();
$perpage[0] = get_string('never');
$perpage[1] = get_string('aftereachquestion', 'cquiz');
for ($i = 2; $i <= CQUIZ_MAX_QPP_OPTION; ++$i) {
    $perpage[$i] = get_string('afternquestions', 'cquiz', $i);
}
$cquizsettings->add(new admin_setting_configselect_with_advanced('cquiz/questionsperpage',
        get_string('newpageevery', 'cquiz'), get_string('confignewpageevery', 'cquiz'),
        array('value' => 1, 'adv' => false), $perpage));

// Navigation method.
$cquizsettings->add(new admin_setting_configselect_with_advanced('cquiz/navmethod',
        get_string('navmethod', 'cquiz'), get_string('confignavmethod', 'cquiz'),
        array('value' => CQUIZ_NAVMETHOD_FREE, 'adv' => true), cquiz_get_navigation_options()));

// Shuffle within questions.
$cquizsettings->add(new admin_setting_configcheckbox_with_advanced('cquiz/shuffleanswers',
        get_string('shufflewithin', 'cquiz'), get_string('configshufflewithin', 'cquiz'),
        array('value' => 1, 'adv' => false)));

// Preferred behaviour.
$cquizsettings->add(new admin_setting_question_behaviour('cquiz/preferredbehaviour',
        get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'cquiz'),
        'deferredfeedback'));

// Each attempt builds on last.
$cquizsettings->add(new admin_setting_configcheckbox_with_advanced('cquiz/attemptonlast',
        get_string('eachattemptbuildsonthelast', 'cquiz'),
        get_string('configeachattemptbuildsonthelast', 'cquiz'),
        array('value' => 0, 'adv' => true)));

// Review options.
$cquizsettings->add(new admin_setting_heading('reviewheading',
        get_string('reviewoptionsheading', 'cquiz'), ''));
foreach (mod_cquiz_admin_review_setting::fields() as $field => $name) {
    $default = mod_cquiz_admin_review_setting::all_on();
    $forceduring = null;
    if ($field == 'attempt') {
        $forceduring = true;
    } else if ($field == 'overallfeedback') {
        $default = $default ^ mod_cquiz_admin_review_setting::DURING;
        $forceduring = false;
    }
    $cquizsettings->add(new mod_cquiz_admin_review_setting('cquiz/review' . $field,
            $name, '', $default, $forceduring));
}

// Show the user's picture.
$cquizsettings->add(new admin_setting_configcheckbox_with_advanced('cquiz/showuserpicture',
        get_string('showuserpicture', 'cquiz'), get_string('configshowuserpicture', 'cquiz'),
        array('value' => 0, 'adv' => false)));

// Decimal places for overall grades.
$options = array();
for ($i = 0; $i <= CQUIZ_MAX_DECIMAL_OPTION; $i++) {
    $options[$i] = $i;
}
$cquizsettings->add(new admin_setting_configselect_with_advanced('cquiz/decimalpoints',
        get_string('decimalplaces', 'cquiz'), get_string('configdecimalplaces', 'cquiz'),
        array('value' => 2, 'adv' => false), $options));

// Decimal places for question grades.
$options = array(-1 => get_string('sameasoverall', 'cquiz'));
for ($i = 0; $i <= CQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
    $options[$i] = $i;
}
$cquizsettings->add(new admin_setting_configselect_with_advanced('cquiz/questiondecimalpoints',
        get_string('decimalplacesquestion', 'cquiz'),
        get_string('configdecimalplacesquestion', 'cquiz'),
        array('value' => -1, 'adv' => true), $options));

// Show blocks during cquiz attempts.
$cquizsettings->add(new admin_setting_configcheckbox_with_advanced('cquiz/showblocks',
        get_string('showblocks', 'cquiz'), get_string('configshowblocks', 'cquiz'),
        array('value' => 0, 'adv' => true)));

// Password.
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/password',
        get_string('requirepassword', 'cquiz'), get_string('configrequirepassword', 'cquiz'),
        array('value' => '', 'adv' => true), PARAM_TEXT));

// IP restrictions.
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/subnet',
        get_string('requiresubnet', 'cquiz'), get_string('configrequiresubnet', 'cquiz'),
        array('value' => '', 'adv' => true), PARAM_TEXT));

// Enforced delay between attempts.
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/delay1',
        get_string('delay1st2nd', 'cquiz'), get_string('configdelay1st2nd', 'cquiz'),
        array('value' => 0, 'adv' => true), PARAM_INT));
$cquizsettings->add(new admin_setting_configtext_with_advanced('cquiz/delay2',
        get_string('delaylater', 'cquiz'), get_string('configdelaylater', 'cquiz'),
        array('value' => 0, 'adv' => true), PARAM_INT));

// Browser security.
$cquizsettings->add(new mod_cquiz_admin_setting_browsersecurity('cquiz/browsersecurity',
        get_string('showinsecurepopup', 'cquiz'), get_string('configpopup', 'cquiz'),
        array('value' => '-', 'adv' => true), null));

// Allow user to specify if setting outcomes is an advanced setting
if (!empty($CFG->enableoutcomes)) {
    $cquizsettings->add(new admin_setting_configcheckbox('cquiz/outcomes_adv',
        get_string('outcomesadvanced', 'cquiz'), get_string('configoutcomesadvanced', 'cquiz'),
        '0'));
}

// Autosave frequency.
$options = array(
      0 => get_string('donotuseautosave', 'cquiz'),
     60 => get_string('oneminute', 'cquiz'),
    120 => get_string('numminutes', 'moodle', 2),
    300 => get_string('numminutes', 'moodle', 5),
);
$cquizsettings->add(new admin_setting_configselect('cquiz/autosaveperiod',
        get_string('autosaveperiod', 'cquiz'), get_string('autosaveperiod_desc', 'cquiz'), 0, $options));

// Now, depending on whether any reports have their own settings page, add
// the cquiz setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $cquizsettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingscquizcat',
            get_string('modulename', 'cquiz'), $module->is_enabled() === false));
    $ADMIN->add('modsettingscquizcat', $cquizsettings);

    // Add settings pages for the cquiz report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingscquizcat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/cquiz/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingscquizcat', $settings);
        }
    }

    // Add settings pages for the cquiz access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingscquizcat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/cquiz/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingscquizcat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
