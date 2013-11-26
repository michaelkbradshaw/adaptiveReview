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
 * Defines the cquiz module ettings form.
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/cquiz/locallib.php');


/**
 * Settings form for the cquiz module.
 *
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_cquiz_mod_form extends moodleform_mod {
    protected $_feedbacks;
    protected static $reviewfields = array(); // Initialised in the constructor.

    public function __construct($current, $section, $cm, $course) {
        self::$reviewfields = array(
            'attempt'          => array('theattempt', 'cquiz'),
            'correctness'      => array('whethercorrect', 'question'),
            'marks'            => array('marks', 'cquiz'),
            'specificfeedback' => array('specificfeedback', 'question'),
            'generalfeedback'  => array('generalfeedback', 'question'),
            'rightanswer'      => array('rightanswer', 'question'),
            'overallfeedback'  => array('reviewoverallfeedback', 'cquiz'),
        );
        parent::__construct($current, $section, $cm, $course);
    }

    protected function definition() {
        global $COURSE, $CFG, $DB, $PAGE;
        $cquizconfig = get_config('cquiz');
        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name.
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction.
        $this->add_intro_editor(false, get_string('introduction', 'cquiz'));

        
        $mform->addElement('duration', 'success_wait_time', get_string('success_wait_time', 'cquiz'),
        		array('optional' => true));
        //$mform->addHelpButton('success_wait_time', 'timelimit', 'cquiz');
        //$mform->setAdvanced('success_wait_time', $cquizconfig->timelimit_adv);
        $mform->setDefault('success_wait_time', $cquizconfig->timelimit);
        
        
        
        
        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'timing', get_string('timing', 'cquiz'));

        // Open and close dates.
        $mform->addElement('date_time_selector', 'timeopen', get_string('cquizopen', 'cquiz'),
                array('optional' => true, 'step' => 1));
        $mform->addHelpButton('timeopen', 'cquizopenclose', 'cquiz');

        $mform->addElement('date_time_selector', 'timeclose', get_string('cquizclose', 'cquiz'),
                array('optional' => true, 'step' => 1));

        // Time limit.
        $mform->addElement('duration', 'timelimit', get_string('timelimit', 'cquiz'),
                array('optional' => true));
        $mform->addHelpButton('timelimit', 'timelimit', 'cquiz');
        $mform->setAdvanced('timelimit', $cquizconfig->timelimit_adv);
        $mform->setDefault('timelimit', $cquizconfig->timelimit);

        // What to do with overdue attempts.
        $mform->addElement('select', 'overduehandling', get_string('overduehandling', 'cquiz'),
                cquiz_get_overdue_handling_options());
        $mform->addHelpButton('overduehandling', 'overduehandling', 'cquiz');
        $mform->setAdvanced('overduehandling', $cquizconfig->overduehandling_adv);
        $mform->setDefault('overduehandling', $cquizconfig->overduehandling);
        // TODO Formslib does OR logic on disableif, and we need AND logic here.
        // $mform->disabledIf('overduehandling', 'timelimit', 'eq', 0);
        // $mform->disabledIf('overduehandling', 'timeclose', 'eq', 0);

        // Grace period time.
        $mform->addElement('duration', 'graceperiod', get_string('graceperiod', 'cquiz'),
                array('optional' => true));
        $mform->addHelpButton('graceperiod', 'graceperiod', 'cquiz');
        $mform->setAdvanced('graceperiod', $cquizconfig->graceperiod_adv);
        $mform->setDefault('graceperiod', $cquizconfig->graceperiod);
        $mform->disabledIf('graceperiod', 'overduehandling', 'neq', 'graceperiod');

        // -------------------------------------------------------------------------------
        // Grade settings.
        $this->standard_grading_coursemodule_elements();

        $mform->removeElement('grade');
        $mform->addElement('hidden', 'grade', $cquizconfig->maximumgrade);
        $mform->setType('grade', PARAM_FLOAT);

        // Number of attempts.
        $attemptoptions = array('0' => get_string('unlimited'));
        for ($i = 1; $i <= CQUIZ_MAX_ATTEMPT_OPTION; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'attempts', get_string('attemptsallowed', 'cquiz'),
                $attemptoptions);
        $mform->setAdvanced('attempts', $cquizconfig->attempts_adv);
        $mform->setDefault('attempts', $cquizconfig->attempts);

        // Grading method.
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'cquiz'),
                cquiz_get_grading_options());
        $mform->addHelpButton('grademethod', 'grademethod', 'cquiz');
        $mform->setAdvanced('grademethod', $cquizconfig->grademethod_adv);
        $mform->setDefault('grademethod', $cquizconfig->grademethod);
        $mform->disabledIf('grademethod', 'attempts', 'eq', 1);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'layouthdr', get_string('layout', 'cquiz'));

        // Shuffle questions.
        $shuffleoptions = array(
            0 => get_string('asshownoneditscreen', 'cquiz'),
            1 => get_string('shuffledrandomly', 'cquiz')
        );
        $mform->addElement('select', 'shufflequestions', get_string('questionorder', 'cquiz'),
                $shuffleoptions, array('id' => 'id_shufflequestions'));
        $mform->setAdvanced('shufflequestions', $cquizconfig->shufflequestions_adv);
        $mform->setDefault('shufflequestions', $cquizconfig->shufflequestions);

        // Questions per page.
        $pageoptions = array();
        $pageoptions[0] = get_string('neverallononepage', 'cquiz');
        $pageoptions[1] = get_string('everyquestion', 'cquiz');
        for ($i = 2; $i <= CQUIZ_MAX_QPP_OPTION; ++$i) {
            $pageoptions[$i] = get_string('everynquestions', 'cquiz', $i);
        }

        $pagegroup = array();
        $pagegroup[] = $mform->createElement('select', 'questionsperpage',
                get_string('newpage', 'cquiz'), $pageoptions, array('id' => 'id_questionsperpage'));
        $mform->setDefault('questionsperpage', $cquizconfig->questionsperpage);

        if (!empty($this->_cm)) {
            $pagegroup[] = $mform->createElement('checkbox', 'repaginatenow', '',
                    get_string('repaginatenow', 'cquiz'), array('id' => 'id_repaginatenow'));
            $mform->disabledIf('repaginatenow', 'shufflequestions', 'eq', 1);

            $PAGE->requires->js('/question/qengine.js');
            $module = array(
                'name'      => 'mod_cquiz_edit',
                'fullpath'  => '/mod/cquiz/edit.js',
                'requires'  => array('yui2-dom', 'yui2-event', 'yui2-container'),
                'strings'   => array(),
                'async'     => false,
            );
            $PAGE->requires->js_init_call('cquiz_settings_init', null, false, $module);
        }

        $mform->addGroup($pagegroup, 'questionsperpagegrp',
                get_string('newpage', 'cquiz'), null, false);
        $mform->addHelpButton('questionsperpagegrp', 'newpage', 'cquiz');
        $mform->setAdvanced('questionsperpagegrp', $cquizconfig->questionsperpage_adv);

        // Navigation method.
        $mform->addElement('select', 'navmethod', get_string('navmethod', 'cquiz'),
                cquiz_get_navigation_options());
        $mform->addHelpButton('navmethod', 'navmethod', 'cquiz');
        $mform->setAdvanced('navmethod', $cquizconfig->navmethod_adv);
        $mform->setDefault('navmethod', $cquizconfig->navmethod);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'interactionhdr', get_string('questionbehaviour', 'cquiz'));

        // Shuffle within questions.
        $mform->addElement('selectyesno', 'shuffleanswers', get_string('shufflewithin', 'cquiz'));
        $mform->addHelpButton('shuffleanswers', 'shufflewithin', 'cquiz');
        $mform->setAdvanced('shuffleanswers', $cquizconfig->shuffleanswers_adv);
        $mform->setDefault('shuffleanswers', $cquizconfig->shuffleanswers);

        // How questions behave (question behaviour).
        if (!empty($this->current->preferredbehaviour)) {
            $currentbehaviour = $this->current->preferredbehaviour;
        } else {
            $currentbehaviour = '';
        }
        $behaviours = question_engine::get_behaviour_options($currentbehaviour);
        $mform->addElement('select', 'preferredbehaviour',
                get_string('howquestionsbehave', 'question'), $behaviours);
        $mform->addHelpButton('preferredbehaviour', 'howquestionsbehave', 'question');
        $mform->setDefault('preferredbehaviour', $cquizconfig->preferredbehaviour);

        // Each attempt builds on last.
        $mform->addElement('selectyesno', 'attemptonlast',
                get_string('eachattemptbuildsonthelast', 'cquiz'));
        $mform->addHelpButton('attemptonlast', 'eachattemptbuildsonthelast', 'cquiz');
        $mform->setAdvanced('attemptonlast', $cquizconfig->attemptonlast_adv);
        $mform->setDefault('attemptonlast', $cquizconfig->attemptonlast);
        $mform->disabledIf('attemptonlast', 'attempts', 'eq', 1);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'reviewoptionshdr',
                get_string('reviewoptionsheading', 'cquiz'));
        $mform->addHelpButton('reviewoptionshdr', 'reviewoptionsheading', 'cquiz');

        // Review options.
        $this->add_review_options_group($mform, $cquizconfig, 'during',
                mod_cquiz_display_options::DURING, true);
        $this->add_review_options_group($mform, $cquizconfig, 'immediately',
                mod_cquiz_display_options::IMMEDIATELY_AFTER);
        $this->add_review_options_group($mform, $cquizconfig, 'open',
                mod_cquiz_display_options::LATER_WHILE_OPEN);
        $this->add_review_options_group($mform, $cquizconfig, 'closed',
                mod_cquiz_display_options::AFTER_CLOSE);

        foreach ($behaviours as $behaviour => $notused) {
            $unusedoptions = question_engine::get_behaviour_unused_display_options($behaviour);
            foreach ($unusedoptions as $unusedoption) {
                $mform->disabledIf($unusedoption . 'during', 'preferredbehaviour',
                        'eq', $behaviour);
            }
        }
        $mform->disabledIf('attemptduring', 'preferredbehaviour',
                'neq', 'wontmatch');
        $mform->disabledIf('overallfeedbackduring', 'preferredbehaviour',
                'neq', 'wontmatch');

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'display', get_string('display', 'form'));

        // Show user picture.
        $mform->addElement('selectyesno', 'showuserpicture',
                get_string('showuserpicture', 'cquiz'));
        $mform->addHelpButton('showuserpicture', 'showuserpicture', 'cquiz');
        $mform->setAdvanced('showuserpicture', $cquizconfig->showuserpicture_adv);
        $mform->setDefault('showuserpicture', $cquizconfig->showuserpicture);

        // Overall decimal points.
        $options = array();
        for ($i = 0; $i <= CQUIZ_MAX_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'decimalpoints', get_string('decimalplaces', 'cquiz'),
                $options);
        $mform->addHelpButton('decimalpoints', 'decimalplaces', 'cquiz');
        $mform->setAdvanced('decimalpoints', $cquizconfig->decimalpoints_adv);
        $mform->setDefault('decimalpoints', $cquizconfig->decimalpoints);

        // Question decimal points.
        $options = array(-1 => get_string('sameasoverall', 'cquiz'));
        for ($i = 0; $i <= CQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'questiondecimalpoints',
                get_string('decimalplacesquestion', 'cquiz'), $options);
        $mform->addHelpButton('questiondecimalpoints', 'decimalplacesquestion', 'cquiz');
        $mform->setAdvanced('questiondecimalpoints', $cquizconfig->questiondecimalpoints_adv);
        $mform->setDefault('questiondecimalpoints', $cquizconfig->questiondecimalpoints);

        // Show blocks during cquiz attempt.
        $mform->addElement('selectyesno', 'showblocks', get_string('showblocks', 'cquiz'));
        $mform->addHelpButton('showblocks', 'showblocks', 'cquiz');
        $mform->setAdvanced('showblocks', $cquizconfig->showblocks_adv);
        $mform->setDefault('showblocks', $cquizconfig->showblocks);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'security', get_string('extraattemptrestrictions', 'cquiz'));

        // Require password to begin cquiz attempt.
        $mform->addElement('passwordunmask', 'cquizpassword', get_string('requirepassword', 'cquiz'));
        $mform->setType('cquizpassword', PARAM_TEXT);
        $mform->addHelpButton('cquizpassword', 'requirepassword', 'cquiz');
        $mform->setAdvanced('cquizpassword', $cquizconfig->password_adv);
        $mform->setDefault('cquizpassword', $cquizconfig->password);

        // IP address.
        $mform->addElement('text', 'subnet', get_string('requiresubnet', 'cquiz'));
        $mform->setType('subnet', PARAM_TEXT);
        $mform->addHelpButton('subnet', 'requiresubnet', 'cquiz');
        $mform->setAdvanced('subnet', $cquizconfig->subnet_adv);
        $mform->setDefault('subnet', $cquizconfig->subnet);

        // Enforced time delay between cquiz attempts.
        $mform->addElement('duration', 'delay1', get_string('delay1st2nd', 'cquiz'),
                array('optional' => true));
        $mform->addHelpButton('delay1', 'delay1st2nd', 'cquiz');
        $mform->setAdvanced('delay1', $cquizconfig->delay1_adv);
        $mform->setDefault('delay1', $cquizconfig->delay1);
        $mform->disabledIf('delay1', 'attempts', 'eq', 1);

        $mform->addElement('duration', 'delay2', get_string('delaylater', 'cquiz'),
                array('optional' => true));
        $mform->addHelpButton('delay2', 'delaylater', 'cquiz');
        $mform->setAdvanced('delay2', $cquizconfig->delay2_adv);
        $mform->setDefault('delay2', $cquizconfig->delay2);
        $mform->disabledIf('delay2', 'attempts', 'eq', 1);
        $mform->disabledIf('delay2', 'attempts', 'eq', 2);

        // Browser security choices.
        $mform->addElement('select', 'browsersecurity', get_string('browsersecurity', 'cquiz'),
                cquiz_access_manager::get_browser_security_choices());
        $mform->addHelpButton('browsersecurity', 'browsersecurity', 'cquiz');
        $mform->setAdvanced('browsersecurity', $cquizconfig->browsersecurity_adv);
        $mform->setDefault('browsersecurity', $cquizconfig->browsersecurity);

        // Any other rule plugins.
        cquiz_access_manager::add_settings_form_fields($this, $mform);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'overallfeedbackhdr', get_string('overallfeedback', 'cquiz'));
        $mform->addHelpButton('overallfeedbackhdr', 'overallfeedback', 'cquiz');

        if (isset($this->current->grade)) {
            $needwarning = $this->current->grade === 0;
        } else {
            $needwarning = $cquizconfig->maximumgrade == 0;
        }
        if ($needwarning) {
            $mform->addElement('static', 'nogradewarning', '',
                    get_string('nogradewarning', 'cquiz'));
        }

        $mform->addElement('static', 'gradeboundarystatic1',
                get_string('gradeboundary', 'cquiz'), '100%');

        $repeatarray = array();
        $repeatedoptions = array();
        $repeatarray[] = $mform->createElement('editor', 'feedbacktext',
                get_string('feedback', 'cquiz'), array('rows' => 3), array('maxfiles' => EDITOR_UNLIMITED_FILES,
                        'noclean' => true, 'context' => $this->context, 'collapsed' => 1));
        $repeatarray[] = $mform->createElement('text', 'feedbackboundaries',
                get_string('gradeboundary', 'cquiz'), array('size' => 10));
        $repeatedoptions['feedbacktext']['type'] = PARAM_RAW;
        $repeatedoptions['feedbackboundaries']['type'] = PARAM_RAW;

        if (!empty($this->_instance)) {
            $this->_feedbacks = $DB->get_records('cquiz_feedback',
                    array('cquizid' => $this->_instance), 'mingrade DESC');
        } else {
            $this->_feedbacks = array();
        }
        $numfeedbacks = max(count($this->_feedbacks) * 1.5, 5);

        $nextel = $this->repeat_elements($repeatarray, $numfeedbacks - 1,
                $repeatedoptions, 'boundary_repeats', 'boundary_add_fields', 3,
                get_string('addmoreoverallfeedbacks', 'cquiz'), true);

        // Put some extra elements in before the button.
        $mform->insertElementBefore($mform->createElement('editor',
                "feedbacktext[$nextel]", get_string('feedback', 'cquiz'), array('rows' => 3),
                array('maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true,
                      'context' => $this->context, 'collapsed' => 1)),
                'boundary_add_fields');
        $mform->insertElementBefore($mform->createElement('static',
                'gradeboundarystatic2', get_string('gradeboundary', 'cquiz'), '0%'),
                'boundary_add_fields');

        // Add the disabledif rules. We cannot do this using the $repeatoptions parameter to
        // repeat_elements because we don't want to dissable the first feedbacktext.
        for ($i = 0; $i < $nextel; $i++) {
            $mform->disabledIf('feedbackboundaries[' . $i . ']', 'grade', 'eq', 0);
            $mform->disabledIf('feedbacktext[' . ($i + 1) . ']', 'grade', 'eq', 0);
        }

        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();

        // Check and act on whether setting outcomes is considered an advanced setting.
        $mform->setAdvanced('modoutcomes', !empty($cquizconfig->outcomes_adv));

        // The standard_coursemodule_elements method sets this to 100, but the
        // cquiz has its own setting, so use that.
        $mform->setDefault('grade', $cquizconfig->maximumgrade);

        // -------------------------------------------------------------------------------
        $this->add_action_buttons();
    }

    protected function add_review_options_group($mform, $cquizconfig, $whenname,
            $when, $withhelp = false) {
        global $OUTPUT;

        $group = array();
        foreach (self::$reviewfields as $field => $string) {
            list($identifier, $component) = $string;

            $label = get_string($identifier, $component);
            if ($withhelp) {
                $label .= ' ' . $OUTPUT->help_icon($identifier, $component);
            }

            $group[] = $mform->createElement('checkbox', $field . $whenname, '', $label);
        }
        $mform->addGroup($group, $whenname . 'optionsgrp',
                get_string('review' . $whenname, 'cquiz'), null, false);

        foreach (self::$reviewfields as $field => $notused) {
            $cfgfield = 'review' . $field;
            if ($cquizconfig->$cfgfield & $when) {
                $mform->setDefault($field . $whenname, 1);
            } else {
                $mform->setDefault($field . $whenname, 0);
            }
        }

        $mform->disabledIf('correctness' . $whenname, 'attempt' . $whenname);
        $mform->disabledIf('specificfeedback' . $whenname, 'attempt' . $whenname);
        $mform->disabledIf('generalfeedback' . $whenname, 'attempt' . $whenname);
        $mform->disabledIf('rightanswer' . $whenname, 'attempt' . $whenname);
    }

    protected function preprocessing_review_settings(&$toform, $whenname, $when) {
        foreach (self::$reviewfields as $field => $notused) {
            $fieldname = 'review' . $field;
            if (array_key_exists($fieldname, $toform)) {
                $toform[$field . $whenname] = $toform[$fieldname] & $when;
            }
        }
    }

    public function data_preprocessing(&$toform) {
        if (isset($toform['grade'])) {
            // Convert to a real number, so we don't get 0.0000.
            $toform['grade'] = $toform['grade'] + 0;
        }

        if (count($this->_feedbacks)) {
            $key = 0;
            foreach ($this->_feedbacks as $feedback) {
                $draftid = file_get_submitted_draft_itemid('feedbacktext['.$key.']');
                $toform['feedbacktext['.$key.']']['text'] = file_prepare_draft_area(
                    $draftid,               // Draftid.
                    $this->context->id,     // Context.
                    'mod_cquiz',             // Component.
                    'feedback',             // Filarea.
                    !empty($feedback->id) ? (int) $feedback->id : null, // Itemid.
                    null,
                    $feedback->feedbacktext // Text.
                );
                $toform['feedbacktext['.$key.']']['format'] = $feedback->feedbacktextformat;
                $toform['feedbacktext['.$key.']']['itemid'] = $draftid;

                if ($toform['grade'] == 0) {
                    // When a cquiz is un-graded, there can only be one lot of
                    // feedback. If the cquiz previously had a maximum grade and
                    // several lots of feedback, we must now avoid putting text
                    // into input boxes that are disabled, but which the
                    // validation will insist are blank.
                    break;
                }

                if ($feedback->mingrade > 0) {
                    $toform['feedbackboundaries['.$key.']'] =
                            (100.0 * $feedback->mingrade / $toform['grade']) . '%';
                }
                $key++;
            }
        }

        if (isset($toform['timelimit'])) {
            $toform['timelimitenable'] = $toform['timelimit'] > 0;
        }

        $this->preprocessing_review_settings($toform, 'during',
                mod_cquiz_display_options::DURING);
        $this->preprocessing_review_settings($toform, 'immediately',
                mod_cquiz_display_options::IMMEDIATELY_AFTER);
        $this->preprocessing_review_settings($toform, 'open',
                mod_cquiz_display_options::LATER_WHILE_OPEN);
        $this->preprocessing_review_settings($toform, 'closed',
                mod_cquiz_display_options::AFTER_CLOSE);
        $toform['attemptduring'] = true;
        $toform['overallfeedbackduring'] = false;

        // Password field - different in form to stop browsers that remember
        // passwords from getting confused.
        if (isset($toform['password'])) {
            $toform['cquizpassword'] = $toform['password'];
            unset($toform['password']);
        }

        // Load any settings belonging to the access rules.
        if (!empty($toform['instance'])) {
            $accesssettings = cquiz_access_manager::load_settings($toform['instance']);
            foreach ($accesssettings as $name => $value) {
                $toform[$name] = $value;
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Check open and close times are consistent.
        if ($data['timeopen'] != 0 && $data['timeclose'] != 0 &&
                $data['timeclose'] < $data['timeopen']) {
            $errors['timeclose'] = get_string('closebeforeopen', 'cquiz');
        }

        // Check that the grace period is not too short.
        if ($data['overduehandling'] == 'graceperiod') {
            $graceperiodmin = get_config('cquiz', 'graceperiodmin');
            if ($data['graceperiod'] <= $graceperiodmin) {
                $errors['graceperiod'] = get_string('graceperiodtoosmall', 'cquiz', format_time($graceperiodmin));
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($data['feedbackboundaries'][$i] )) {
            $boundary = trim($data['feedbackboundaries'][$i]);
            if (strlen($boundary) > 0) {
                if ($boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $data['grade'] / 100.0;
                    } else {
                        $errors["feedbackboundaries[$i]"] =
                                get_string('feedbackerrorboundaryformat', 'cquiz', $i + 1);
                    }
                } else if (!is_numeric($boundary)) {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorboundaryformat', 'cquiz', $i + 1);
                }
            }
            if (is_numeric($boundary) && $boundary <= 0 || $boundary >= $data['grade'] ) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrorboundaryoutofrange', 'cquiz', $i + 1);
            }
            if (is_numeric($boundary) && $i > 0 &&
                    $boundary >= $data['feedbackboundaries'][$i - 1]) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrororder', 'cquiz', $i + 1);
            }
            $data['feedbackboundaries'][$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($data['feedbackboundaries'])) {
            for ($i = $numboundaries; $i < count($data['feedbackboundaries']); $i += 1) {
                if (!empty($data['feedbackboundaries'][$i] ) &&
                        trim($data['feedbackboundaries'][$i] ) != '') {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorjunkinboundary', 'cquiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($data['feedbacktext']); $i += 1) {
            if (!empty($data['feedbacktext'][$i]['text']) &&
                    trim($data['feedbacktext'][$i]['text'] ) != '') {
                $errors["feedbacktext[$i]"] =
                        get_string('feedbackerrorjunkinfeedback', 'cquiz', $i + 1);
            }
        }

        // Any other rule plugins.
        $errors = cquiz_access_manager::validate_settings_form_fields($errors, $data, $files, $this);

        return $errors;
    }
}
