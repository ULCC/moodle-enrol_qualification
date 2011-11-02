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
 * Adds instance form
 *
 * @package    enrol
 * @subpackage qualification
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

class enrol_qualification_addinstance_form extends moodleform {
    protected $course;

    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form;
        $course = $this->_customdata;
        $this->course = $course;

        $existing = $DB->get_records('enrol', array('enrol' => 'qualification',
                                                   'courseid' => $course->id),
                                     '', 'customint1, id');

        // TODO: this has to be done via ajax or else it will fail very badly on large sites!
        $courses = array('' => get_string('choosedots'));
        $rs = $DB->get_recordset('course', array(), 'sortorder ASC',
                                 'id, fullname, shortname, visible');
        foreach ($rs as $c) {
            if ($c->id == SITEID or $c->id == $course->id or isset($existing[$c->id])) {
                continue;
            }
            $coursecontext = get_context_instance(CONTEXT_COURSE, $c->id);
            $canviewhidden = has_capability('moodle/course:viewhiddencourses', $coursecontext);
            if (!$c->visible and !$canviewhidden) {
                continue;
            }
            if (!has_capability('enrol/qualification:selectaslinked', $coursecontext)) {
                continue;
            }
            $courses[$c->id] = format_string($c->fullname).' ['.
                               format_string($c->shortname, true,
                                             array('context' => $coursecontext)).']';
        }
        $rs->close();

        $mform->addElement('header', 'general', get_string('pluginname', 'enrol_qualification'));

        $mform->addElement('select', 'link',
                           get_string('linkedcourse', 'enrol_qualification'), $courses);
        $mform->addRule('link', get_string('required'), 'required', null, 'client');

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('addinstance', 'enrol'));

        $this->set_data(array('id' => $course->id));
    }

    public function validation($data, $files) {
        global $DB, $CFG;

        // TODO: this is duplicated here because it may be necessary one we implement ajax course
        // selection element

        $errors = parent::validation($data, $files);
        if (!$c = $DB->get_record('course', array('id' => $data['link']))) {
            $errors['link'] = get_string('required');
        } else {
            $coursecontext = get_context_instance(CONTEXT_COURSE, $c->id);
            $existing = $DB->get_records('enrol', array('enrol' => 'qualification',
                                                       'courseid' => $this->course->id),
                                         '', 'customint1, id');
            $canviewhidden = has_capability('moodle/course:viewhiddencourses', $coursecontext);
            if (!$c->visible and !$canviewhidden) {
                $errors['link'] = get_string('error');
            } else {
                if (!has_capability('enrol/qualification:selectaslinked', $coursecontext)) {
                    $errors['link'] = get_string('error');
                } else {
                    if ($c->id == SITEID or
                        $c->id == $this->course->id or
                        isset($existing[$c->id])
                    ) {

                        $errors['link'] = get_string('error');
                    }
                }
            }
        }

        return $errors;
    }
}
