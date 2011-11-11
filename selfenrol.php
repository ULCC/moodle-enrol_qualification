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
 * This file confirms whether a user really wants to add themselves (or remove themselves) from a
 * course. We don't use self enrol/unenrol because we want to be able to renforce rules e.g. you
 * can take any two of these three courses as part of a qualification, so we want to prevent
 * any one from enrolling on further courses once they have their minimum for a particular group.
 */

require_once(dirname(__FILE__).'../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$parentcourseid = required_param('parentcourseid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid));
if (!$course) {
    die('Invalid course id');
}
$parentcourse = $DB->get_record('course', array('id' => $parentcourseid));
if (!$parentcourse) {
    die('Invalid parent course id');
}

require_login($courseid, false);

// If we have confirmation, make the enrolment and redirect to the parent course
if ($confirm) {
    $qualification = enrol_get_plugin('qualification');
    // Assume we only have one instance per parent-child pair
    $instance = $DB->get_record('enrol', array('enrol' => 'qualification',
                                               'courseid' => $courseid,
                                               'customint1' => $parentcourseid));
    $qualification->enrol_user($instance, $USER->id);
    redirect('/course/view.php?id='.$parentcourseid);

} else {
    // Display the form
    echo $OUTPUT->header();
    echo html_writer::start_tag('form');
    echo html_writer::empty_tag('input', array('type' => 'hidden',
                                               'name' => 'courseid',
                                               'value' => $courseid));
    echo html_writer::empty_tag('input', array('type' => 'hidden',
                                               'name' => 'parentcourseid',
                                               'value' => $parentcourseid));
    $html .= html_writer::end_tag('form');
    echo $OUTPUT->footer();
}

