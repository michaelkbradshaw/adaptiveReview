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
 * Page to edit cquizzes
 *
 * This page generally has two columns:
 * The right column lists all available questions in a chosen category and
 * allows them to be edited or more to be added. This column is only there if
 * the cquiz does not already have student attempts
 * The left column lists all questions that have been added to the current cquiz.
 * The lecturer can add questions from the right hand list to the cquiz or remove them
 *
 * The script also processes a number of actions:
 * Actions affecting a cquiz:
 * up and down  Changes the order of questions and page breaks
 * addquestion  Adds a single question to the cquiz
 * add          Adds several selected questions to the cquiz
 * addrandom    Adds a certain number of random questions to the cquiz
 * repaginate   Re-paginates the cquiz
 * delete       Removes a question from the cquiz
 * savechanges  Saves the order and grades for questions in the cquiz
 *
 * @package    mod
 * @subpackage cquiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once('../../config.php');
require_once($CFG->dirroot . '/mod/cquiz/editlib.php');
require_once($CFG->dirroot . '/mod/cquiz/addrandomform.php');
require_once($CFG->dirroot . '/question/category_class.php');


/**
 * Callback function called from question_list() function
 * (which is called from showbank())
 * Displays button in form with checkboxes for each question.
 */
function module_specific_buttons($cmid, $cmoptions) {
    global $OUTPUT;
    $params = array(
        'type' => 'submit',
        'name' => 'add',
        'value' => $OUTPUT->larrow() . ' ' . get_string('addtocquiz', 'cquiz'),
    );
    if ($cmoptions->hasattempts) {
        $params['disabled'] = 'disabled';
    }
    return html_writer::empty_tag('input', $params);
}

/**
 * Callback function called from question_list() function
 * (which is called from showbank())
 */
function module_specific_controls($totalnumber, $recurse, $category, $cmid, $cmoptions) {
    global $OUTPUT;
    $out = '';
    $catcontext = context::instance_by_id($category->contextid);
    if (has_capability('moodle/question:useall', $catcontext)) {
        if ($cmoptions->hasattempts) {
            $disabled = ' disabled="disabled"';
        } else {
            $disabled = '';
        }
        $randomusablequestions =
                question_bank::get_qtype('random')->get_available_questions_from_category(
                        $category->id, $recurse);
        $maxrand = count($randomusablequestions);
        if ($maxrand > 0) {
            for ($i = 1; $i <= min(10, $maxrand); $i++) {
                $randomcount[$i] = $i;
            }
            for ($i = 20; $i <= min(100, $maxrand); $i += 10) {
                $randomcount[$i] = $i;
            }
        } else {
            $randomcount[0] = 0;
            $disabled = ' disabled="disabled"';
        }

        $out = '<strong><label for="menurandomcount">'.get_string('addrandomfromcategory', 'cquiz').
                '</label></strong><br />';
        $attributes = array();
        $attributes['disabled'] = $disabled ? 'disabled' : null;
        $select = html_writer::select($randomcount, 'randomcount', '1', null, $attributes);
        $out .= get_string('addrandom', 'cquiz', $select);
        $out .= '<input type="hidden" name="recurse" value="'.$recurse.'" />';
        $out .= '<input type="hidden" name="categoryid" value="' . $category->id . '" />';
        $out .= ' <input type="submit" name="addrandom" value="'.
                get_string('addtocquiz', 'cquiz').'"' . $disabled . ' />';
        $out .= $OUTPUT->help_icon('addarandomquestion', 'cquiz');
    }
    return $out;
}

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$cquiz_reordertool = optional_param('reordertool', -1, PARAM_BOOL);
$cquiz_qbanktool = optional_param('qbanktool', -1, PARAM_BOOL);
$scrollpos = optional_param('scrollpos', '', PARAM_INT);

list($thispageurl, $contexts, $cmid, $cm, $cquiz, $pagevars) =
        question_edit_setup('editq', '/mod/cquiz/edit.php', true);
$cquiz->questions = cquiz_clean_layout($cquiz->questions);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

if ($cquiz_qbanktool > -1) {
    $thispageurl->param('qbanktool', $cquiz_qbanktool);
    set_user_preference('cquiz_qbanktool_open', $cquiz_qbanktool);
} else {
    $cquiz_qbanktool = get_user_preferences('cquiz_qbanktool_open', 0);
}

if ($cquiz_reordertool > -1) {
    $thispageurl->param('reordertool', $cquiz_reordertool);
    set_user_preference('cquiz_reordertab', $cquiz_reordertool);
} else {
    $cquiz_reordertool = get_user_preferences('cquiz_reordertab', 0);
}

$canaddrandom = $contexts->have_cap('moodle/question:useall');
$canaddquestion = (bool) $contexts->having_add_and_use();

$cquizhasattempts = cquiz_has_attempts($cquiz->id);

$PAGE->set_url($thispageurl);

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $cquiz->course));
if (!$course) {
    print_error('invalidcourseid', 'error');
}

$questionbank = new cquiz_question_bank_view($contexts, $thispageurl, $course, $cm, $cquiz);
$questionbank->set_cquiz_has_attempts($cquizhasattempts);

// Log this visit.
add_to_log($cm->course, 'cquiz', 'editquestions',
            "view.php?id=$cm->id", "$cquiz->id", $cm->id);

// You need mod/cquiz:manage in addition to question capabilities to access this page.
require_capability('mod/cquiz:manage', $contexts->lowest());

if (empty($cquiz->grades)) {
    $cquiz->grades = cquiz_get_all_question_grades($cquiz);
}

// Process commands ============================================================
if ($cquiz->shufflequestions) {
    // Strip page breaks before processing actions, so that re-ordering works
    // as expected when shuffle questions is on.
    $cquiz->questions = cquiz_repaginate($cquiz->questions, 0);
}

// Get the list of question ids had their check-boxes ticked.
$selectedquestionids = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedquestionids[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}
if (($up = optional_param('up', false, PARAM_INT)) && confirm_sesskey()) {
    $cquiz->questions = cquiz_move_question_up($cquiz->questions, $up);
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

if (($down = optional_param('down', false, PARAM_INT)) && confirm_sesskey()) {
    $cquiz->questions = cquiz_move_question_down($cquiz->questions, $down);
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the cquiz.
    $questionsperpage = optional_param('questionsperpage', $cquiz->questionsperpage, PARAM_INT);
    $cquiz->questions = cquiz_repaginate($cquiz->questions, $questionsperpage );
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current cquiz.
    cquiz_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    cquiz_add_cquiz_question($addquestion, $cquiz, $addonpage);
    cquiz_delete_previews($cquiz);
    cquiz_update_sumgrades($cquiz);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    // Add selected questions to the current cquiz.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            cquiz_require_question_use($key);
            cquiz_add_cquiz_question($key, $cquiz);
        }
    }
    cquiz_delete_previews($cquiz);
    cquiz_update_sumgrades($cquiz);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the cquiz.
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    cquiz_add_random_questions($cquiz, $addonpage, $categoryid, $randomcount, $recurse);

    cquiz_delete_previews($cquiz);
    cquiz_update_sumgrades($cquiz);
    redirect($afteractionurl);
}

if (optional_param('addnewpagesafterselected', null, PARAM_CLEAN) &&
        !empty($selectedquestionids) && confirm_sesskey()) {
    foreach ($selectedquestionids as $questionid) {
        $cquiz->questions = cquiz_add_page_break_after($cquiz->questions, $questionid);
    }
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

$addpage = optional_param('addpage', false, PARAM_INT);
if ($addpage !== false && confirm_sesskey()) {
    $cquiz->questions = cquiz_add_page_break_at($cquiz->questions, $addpage);
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

$deleteemptypage = optional_param('deleteemptypage', false, PARAM_INT);
if (($deleteemptypage !== false) && confirm_sesskey()) {
    $cquiz->questions = cquiz_delete_empty_page($cquiz->questions, $deleteemptypage);
    $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
    cquiz_delete_previews($cquiz);
    redirect($afteractionurl);
}

$remove = optional_param('remove', false, PARAM_INT);
if ($remove && confirm_sesskey()) {
    // Remove a question from the cquiz.
    // We require the user to have the 'use' capability on the question,
    // so that then can add it back if they remove the wrong one by mistake,
    // but, if the question is missing, it can always be removed.
    if ($DB->record_exists('question', array('id' => $remove))) {
        cquiz_require_question_use($remove);
    }
    cquiz_remove_question($cquiz, $remove);
    cquiz_delete_previews($cquiz);
    cquiz_update_sumgrades($cquiz);
    redirect($afteractionurl);
}

if (optional_param('cquizdeleteselected', false, PARAM_BOOL) &&
        !empty($selectedquestionids) && confirm_sesskey()) {
    foreach ($selectedquestionids as $questionid) {
        if (cquiz_has_question_use($questionid)) {
            cquiz_remove_question($cquiz, $questionid);
        }
    }
    cquiz_delete_previews($cquiz);
    cquiz_update_sumgrades($cquiz);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {
    $deletepreviews = false;
    $recomputesummarks = false;

    $oldquestions = explode(',', $cquiz->questions); // The questions in the old order.
    $questions = array(); // For questions in the new order.
    $rawdata = (array) data_submitted();
    $moveonpagequestions = array();
    $moveselectedonpage = optional_param('moveselectedonpagetop', 0, PARAM_INT);
    if (!$moveselectedonpage) {
        $moveselectedonpage = optional_param('moveselectedonpagebottom', 0, PARAM_INT);
    }

    foreach ($rawdata as $key => $value) {
        if (preg_match('!^g([0-9]+)$!', $key, $matches)) {
            // Parse input for question -> grades.
            $questionid = $matches[1];
            $cquiz->grades[$questionid] = unformat_float($value);
            cquiz_update_question_instance($cquiz->grades[$questionid], $questionid, $cquiz);
            $deletepreviews = true;
            $recomputesummarks = true;

        } else if (preg_match('!^o(pg)?([0-9]+)$!', $key, $matches)) {
            // Parse input for ordering info.
            $questionid = $matches[2];
            // Make sure two questions don't overwrite each other. If we get a second
            // question with the same position, shift the second one along to the next gap.
            $value = clean_param($value, PARAM_INT);
            while (array_key_exists($value, $questions)) {
                $value++;
            }
            if ($matches[1]) {
                // This is a page-break entry.
                $questions[$value] = 0;
            } else {
                $questions[$value] = $questionid;
            }
            $deletepreviews = true;
        }
    }

    // If ordering info was given, reorder the questions.
    if ($questions) {
        ksort($questions);
        $questions[] = 0;
        $cquiz->questions = implode(',', $questions);
        $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
        $deletepreviews = true;
    }

    // Get a list of questions to move, later to be added in the appropriate
    // place in the string.
    if ($moveselectedonpage) {
        $questions = explode(',', $cquiz->questions);
        $newquestions = array();
        // Remove the questions from their original positions first.
        foreach ($questions as $questionid) {
            if (!in_array($questionid, $selectedquestionids)) {
                $newquestions[] = $questionid;
            }
        }
        $questions = $newquestions;

        // Move to the end of the selected page.
        $pagebreakpositions = array_keys($questions, 0);
        $numpages = count($pagebreakpositions);

        // Ensure the target page number is in range.
        for ($i = $moveselectedonpage; $i > $numpages; $i--) {
            $questions[] = 0;
            $pagebreakpositions[] = count($questions) - 1;
        }
        $moveselectedpos = $pagebreakpositions[$moveselectedonpage - 1];

        // Do the move.
        array_splice($questions, $moveselectedpos, 0, $selectedquestionids);
        $cquiz->questions = implode(',', $questions);

        // Update the database.
        $DB->set_field('cquiz', 'questions', $cquiz->questions, array('id' => $cquiz->id));
        $deletepreviews = true;
    }

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', -1, PARAM_RAW));
    if ($maxgrade >= 0) {
        cquiz_set_grade($maxgrade, $cquiz);
    }

    if ($deletepreviews) {
        cquiz_delete_previews($cquiz);
    }
    if ($recomputesummarks) {
        cquiz_update_sumgrades($cquiz);
        cquiz_update_all_attempt_sumgrades($cquiz);
        cquiz_update_all_final_grades($cquiz);
        cquiz_update_grades($cquiz, 0, true);
    }
    redirect($afteractionurl);
}

$questionbank->process_actions($thispageurl, $cm);

// End of process commands =====================================================

$PAGE->requires->skip_link_to('questionbank',
        get_string('skipto', 'access', get_string('questionbank', 'question')));
$PAGE->requires->skip_link_to('cquizcontentsblock',
        get_string('skipto', 'access', get_string('questionsinthiscquiz', 'cquiz')));
$PAGE->set_title(get_string('editingcquizx', 'cquiz', format_string($cquiz->name)));
$PAGE->set_heading($course->fullname);
$node = $PAGE->settingsnav->find('mod_cquiz_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$cquizeditconfig = new stdClass();
$cquizeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$cquizeditconfig->dialoglisteners = array();
$numberoflisteners = max(cquiz_number_of_pages($cquiz->questions), 1);
for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $cquizeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}
$PAGE->requires->data_for_js('cquiz_edit_config', $cquizeditconfig);
$PAGE->requires->js('/question/qengine.js');
$module = array(
    'name'      => 'mod_cquiz_edit',
    'fullpath'  => '/mod/cquiz/edit.js',
    'requires'  => array('yui2-dom', 'yui2-event', 'yui2-container'),
    'strings'   => array(),
    'async'     => false,
);
$PAGE->requires->js_init_call('cquiz_edit_init', null, false, $module);

// Print the tabs to switch mode.
if ($cquiz_reordertool) {
    $currenttab = 'reorder';
} else {
    $currenttab = 'edit';
}
$tabs = array(array(
    new tabobject('edit', new moodle_url($thispageurl,
            array('reordertool' => 0)), get_string('editingcquiz', 'cquiz')),
    new tabobject('reorder', new moodle_url($thispageurl,
            array('reordertool' => 1)), get_string('orderingcquiz', 'cquiz')),
));
print_tabs($tabs, $currenttab);

if ($cquiz_qbanktool) {
    $bankclass = '';
    $cquizcontentsclass = '';
} else {
    $bankclass = 'collapsed ';
    $cquizcontentsclass = 'cquizwhenbankcollapsed';
}

echo '<div class="questionbankwindow ' . $bankclass . 'block">';
echo '<div class="header"><div class="title"><h2>';
echo get_string('questionbankcontents', 'cquiz') .
       '&nbsp;[<a href="' . $thispageurl->out(true, array('qbanktool' => '1')) .
       '" id="showbankcmd">' . get_string('show').
       '</a><a href="' . $thispageurl->out(true, array('qbanktool' => '0')) .
       '" id="hidebankcmd">' . get_string('hide').
       '</a>]';
echo '</h2></div></div><div class="content">';

echo '<span id="questionbank"></span>';
echo '<div class="container">';
echo '<div id="module" class="module">';
echo '<div class="bd">';
$questionbank->display('editq',
        $pagevars['qpage'],
        $pagevars['qperpage'],
        $pagevars['cat'], $pagevars['recurse'], $pagevars['showhidden'],
        $pagevars['qbshowtext']);
echo '</div>';
echo '</div>';
echo '</div>';

echo '</div></div>';

echo '<div class="cquizcontents ' . $cquizcontentsclass . '" id="cquizcontentsblock">';
if ($cquiz->shufflequestions) {
    $repaginatingdisabledhtml = 'disabled="disabled"';
    $repaginatingdisabled = true;
    $cquiz->questions = cquiz_repaginate($cquiz->questions, $cquiz->questionsperpage);
} else {
    $repaginatingdisabledhtml = '';
    $repaginatingdisabled = false;
}
if ($cquiz_reordertool) {
    echo '<div class="repaginatecommand"><button id="repaginatecommand" ' .
            $repaginatingdisabledhtml.'>'.
            get_string('repaginatecommand', 'cquiz').'...</button>';
    echo '</div>';
}

if ($cquiz_reordertool) {
    echo $OUTPUT->heading_with_help(get_string('orderingcquizx', 'cquiz', format_string($cquiz->name)),
            'orderandpaging', 'cquiz');
} else {
    echo $OUTPUT->heading(get_string('editingcquizx', 'cquiz', format_string($cquiz->name)), 2);
    echo $OUTPUT->help_icon('editingcquiz', 'cquiz', get_string('basicideasofcquiz', 'cquiz'));
}
cquiz_print_status_bar($cquiz);

$tabindex = 0;
cquiz_print_grading_form($cquiz, $thispageurl, $tabindex);

$notifystrings = array();
if ($cquizhasattempts) {
    $reviewlink = cquiz_attempt_summary_link_to_reports($cquiz, $cm, $contexts->lowest());
    $notifystrings[] = get_string('cannoteditafterattempts', 'cquiz', $reviewlink);
}
if ($cquiz->shufflequestions) {
    $updateurl = new moodle_url("$CFG->wwwroot/course/mod.php",
            array('return' => 'true', 'update' => $cquiz->cmid, 'sesskey' => sesskey()));
    $updatelink = '<a href="'.$updateurl->out().'">' . get_string('updatethis', '',
            get_string('modulename', 'cquiz')) . '</a>';
    $notifystrings[] = get_string('shufflequestionsselected', 'cquiz', $updatelink);
}
if (!empty($notifystrings)) {
    echo $OUTPUT->box('<p>' . implode('</p><p>', $notifystrings) . '</p>', 'statusdisplay');
}

if ($cquiz_reordertool) {
    $perpage = array();
    $perpage[0] = get_string('allinone', 'cquiz');
    for ($i = 1; $i <= 50; ++$i) {
        $perpage[$i] = $i;
    }
    $gostring = get_string('go');
    echo '<div id="repaginatedialog"><div class="hd">';
    echo get_string('repaginatecommand', 'cquiz');
    echo '</div><div class="bd">';
    echo '<form action="edit.php" method="post">';
    echo '<fieldset class="invisiblefieldset">';
    echo html_writer::input_hidden_params($thispageurl);
    echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
    // YUI does not submit the value of the submit button so we need to add the value.
    echo '<input type="hidden" name="repaginate" value="'.$gostring.'" />';
    $attributes = array();
    $attributes['disabled'] = $repaginatingdisabledhtml ? 'disabled' : null;
    $select = html_writer::select(
            $perpage, 'questionsperpage', $cquiz->questionsperpage, null, $attributes);
    print_string('repaginate', 'cquiz', $select);
    echo '<div class="cquizquestionlistcontrols">';
    echo ' <input type="submit" name="repaginate" value="'. $gostring . '" ' .
            $repaginatingdisabledhtml.' />';
    echo '</div></fieldset></form></div></div>';
}

if ($cquiz_reordertool) {
    echo '<div class="reorder">';
} else {
    echo '<div class="editq">';
}

cquiz_print_question_list($cquiz, $thispageurl, true, $cquiz_reordertool, $cquiz_qbanktool,
        $cquizhasattempts, $defaultcategoryobj, $canaddquestion, $canaddrandom);
echo '</div>';

// Close <div class="cquizcontents">.
echo '</div>';

if (!$cquiz_reordertool && $canaddrandom) {
    $randomform = new cquiz_add_random_form(new moodle_url('/mod/cquiz/addrandom.php'), $contexts);
    $randomform->set_data(array(
        'category' => $pagevars['cat'],
        'returnurl' => $thispageurl->out_as_local_url(false),
        'cmid' => $cm->id,
    ));
    ?>
    <div id="randomquestiondialog">
    <div class="hd"><?php print_string('addrandomquestiontocquiz', 'cquiz', $cquiz->name); ?>
    <span id="pagenumber"><!-- JavaScript will insert the page number here. -->
    </span>
    </div>
    <div class="bd"><?php
    $randomform->display();
    ?></div>
    </div>
    <?php
}
echo $OUTPUT->footer();
