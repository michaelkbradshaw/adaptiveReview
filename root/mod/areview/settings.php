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
 * Administration settings definitions for the areview module.
 *
 * @package    mod
 * @subpackage areview
 * @copyright  2010 Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/areview/lib.php');
require_once($CFG->dirroot . '/mod/areview/settingslib.php');

// First get a list of areview reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = get_plugin_list_with_file('areview', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'areview_'.$report);
    $reportsbyname[$strreportname] = $report;
}
collatorlib::ksort($reportsbyname);

// First get a list of areview reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = get_plugin_list_with_file('areviewaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'areviewaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
collatorlib::ksort($rulesbyname);

// Create the areview settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'areview');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$areviewsettings = new admin_settingpage('modsettingareview', $pagetitle, 'moodle/site:config');

// Introductory explanation that all the settings are defaults for the add areview form.
$areviewsettings->add(new admin_setting_heading('areviewintro', '', get_string('configintro', 'areview')));

// Time limit.
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/timelimit',
        get_string('timelimitsec', 'areview'), get_string('configtimelimitsec', 'areview'),
        array('value' => '0', 'adv' => false), PARAM_INT));

// What to do with overdue attempts.
$areviewsettings->add(new mod_areview_admin_setting_overduehandling('areview/overduehandling',
        get_string('overduehandling', 'areview'), get_string('overduehandling_desc', 'areview'),
        array('value' => 'autoabandon', 'adv' => false), null));

// Grace period time.
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/graceperiod',
        get_string('graceperiod', 'areview'), get_string('graceperiod_desc', 'areview'),
        array('value' => '86400', 'adv' => false), PARAM_INT));

// Minimum grace period used behind the scenes.
$areviewsettings->add(new admin_setting_configtext('areview/graceperiodmin',
        get_string('graceperiodmin', 'areview'), get_string('graceperiodmin_desc', 'areview'),
        60, PARAM_INT));

// Number of attempts.
$options = array(get_string('unlimited'));
for ($i = 1; $i <= areview_MAX_ATTEMPT_OPTION; $i++) {
    $options[$i] = $i;
}
$areviewsettings->add(new admin_setting_configselect_with_advanced('areview/attempts',
        get_string('attemptsallowed', 'areview'), get_string('configattemptsallowed', 'areview'),
        array('value' => 0, 'adv' => false), $options));

// Grading method.
$areviewsettings->add(new mod_areview_admin_setting_grademethod('areview/grademethod',
        get_string('grademethod', 'areview'), get_string('configgrademethod', 'areview'),
        array('value' => areview_GRADEHIGHEST, 'adv' => false), null));

// Maximum grade.
$areviewsettings->add(new admin_setting_configtext('areview/maximumgrade',
        get_string('maximumgrade'), get_string('configmaximumgrade', 'areview'), 10, PARAM_INT));

// Shuffle questions.
$areviewsettings->add(new admin_setting_configcheckbox_with_advanced('areview/shufflequestions',
        get_string('shufflequestions', 'areview'), get_string('configshufflequestions', 'areview'),
        array('value' => 0, 'adv' => false)));

// Questions per page.
$perpage = array();
$perpage[0] = get_string('never');
$perpage[1] = get_string('aftereachquestion', 'areview');
for ($i = 2; $i <= areview_MAX_QPP_OPTION; ++$i) {
    $perpage[$i] = get_string('afternquestions', 'areview', $i);
}
$areviewsettings->add(new admin_setting_configselect_with_advanced('areview/questionsperpage',
        get_string('newpageevery', 'areview'), get_string('confignewpageevery', 'areview'),
        array('value' => 1, 'adv' => false), $perpage));

// Navigation method.
$areviewsettings->add(new admin_setting_configselect_with_advanced('areview/navmethod',
        get_string('navmethod', 'areview'), get_string('confignavmethod', 'areview'),
        array('value' => areview_NAVMETHOD_FREE, 'adv' => true), areview_get_navigation_options()));

// Shuffle within questions.
$areviewsettings->add(new admin_setting_configcheckbox_with_advanced('areview/shuffleanswers',
        get_string('shufflewithin', 'areview'), get_string('configshufflewithin', 'areview'),
        array('value' => 1, 'adv' => false)));

// Preferred behaviour.
$areviewsettings->add(new admin_setting_question_behaviour('areview/preferredbehaviour',
        get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'areview'),
        'deferredfeedback'));

// Each attempt builds on last.
$areviewsettings->add(new admin_setting_configcheckbox_with_advanced('areview/attemptonlast',
        get_string('eachattemptbuildsonthelast', 'areview'),
        get_string('configeachattemptbuildsonthelast', 'areview'),
        array('value' => 0, 'adv' => true)));

// Review options.
$areviewsettings->add(new admin_setting_heading('reviewheading',
        get_string('reviewoptionsheading', 'areview'), ''));
foreach (mod_areview_admin_review_setting::fields() as $field => $name) {
    $default = mod_areview_admin_review_setting::all_on();
    $forceduring = null;
    if ($field == 'attempt') {
        $forceduring = true;
    } else if ($field == 'overallfeedback') {
        $default = $default ^ mod_areview_admin_review_setting::DURING;
        $forceduring = false;
    }
    $areviewsettings->add(new mod_areview_admin_review_setting('areview/review' . $field,
            $name, '', $default, $forceduring));
}

// Show the user's picture.
$areviewsettings->add(new admin_setting_configcheckbox_with_advanced('areview/showuserpicture',
        get_string('showuserpicture', 'areview'), get_string('configshowuserpicture', 'areview'),
        array('value' => 0, 'adv' => false)));

// Decimal places for overall grades.
$options = array();
for ($i = 0; $i <= areview_MAX_DECIMAL_OPTION; $i++) {
    $options[$i] = $i;
}
$areviewsettings->add(new admin_setting_configselect_with_advanced('areview/decimalpoints',
        get_string('decimalplaces', 'areview'), get_string('configdecimalplaces', 'areview'),
        array('value' => 2, 'adv' => false), $options));

// Decimal places for question grades.
$options = array(-1 => get_string('sameasoverall', 'areview'));
for ($i = 0; $i <= areview_MAX_Q_DECIMAL_OPTION; $i++) {
    $options[$i] = $i;
}
$areviewsettings->add(new admin_setting_configselect_with_advanced('areview/questiondecimalpoints',
        get_string('decimalplacesquestion', 'areview'),
        get_string('configdecimalplacesquestion', 'areview'),
        array('value' => -1, 'adv' => true), $options));

// Show blocks during areview attempts.
$areviewsettings->add(new admin_setting_configcheckbox_with_advanced('areview/showblocks',
        get_string('showblocks', 'areview'), get_string('configshowblocks', 'areview'),
        array('value' => 0, 'adv' => true)));

// Password.
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/password',
        get_string('requirepassword', 'areview'), get_string('configrequirepassword', 'areview'),
        array('value' => '', 'adv' => true), PARAM_TEXT));

// IP restrictions.
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/subnet',
        get_string('requiresubnet', 'areview'), get_string('configrequiresubnet', 'areview'),
        array('value' => '', 'adv' => true), PARAM_TEXT));

// Enforced delay between attempts.
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/delay1',
        get_string('delay1st2nd', 'areview'), get_string('configdelay1st2nd', 'areview'),
        array('value' => 0, 'adv' => true), PARAM_INT));
$areviewsettings->add(new admin_setting_configtext_with_advanced('areview/delay2',
        get_string('delaylater', 'areview'), get_string('configdelaylater', 'areview'),
        array('value' => 0, 'adv' => true), PARAM_INT));

// Browser security.
$areviewsettings->add(new mod_areview_admin_setting_browsersecurity('areview/browsersecurity',
        get_string('showinsecurepopup', 'areview'), get_string('configpopup', 'areview'),
        array('value' => '-', 'adv' => true), null));

// Allow user to specify if setting outcomes is an advanced setting
if (!empty($CFG->enableoutcomes)) {
    $areviewsettings->add(new admin_setting_configcheckbox('areview/outcomes_adv',
        get_string('outcomesadvanced', 'areview'), get_string('configoutcomesadvanced', 'areview'),
        '0'));
}

// Autosave frequency.
$options = array(
      0 => get_string('donotuseautosave', 'areview'),
     60 => get_string('oneminute', 'areview'),
    120 => get_string('numminutes', 'moodle', 2),
    300 => get_string('numminutes', 'moodle', 5),
);
$areviewsettings->add(new admin_setting_configselect('areview/autosaveperiod',
        get_string('autosaveperiod', 'areview'), get_string('autosaveperiod_desc', 'areview'), 0, $options));

// Now, depending on whether any reports have their own settings page, add
// the areview setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $areviewsettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsareviewcat',
            get_string('modulename', 'areview'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsareviewcat', $areviewsettings);

    // Add settings pages for the areview report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsareviewcat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/areview/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsareviewcat', $settings);
        }
    }

    // Add settings pages for the areview access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsareviewcat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/areview/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsareviewcat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
