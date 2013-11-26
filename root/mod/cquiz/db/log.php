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
 * Definition of log events for the cquiz module.
 *
 * @package    mod_cquiz
 * @category   log
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    array('module'=>'cquiz', 'action'=>'add', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'update', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'view', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'report', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'attempt', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'submit', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'review', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'editquestions', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'preview', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'start attempt', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'close attempt', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'continue attempt', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'edit override', 'mtable'=>'cquiz', 'field'=>'name'),
    array('module'=>'cquiz', 'action'=>'delete override', 'mtable'=>'cquiz', 'field'=>'name'),
);