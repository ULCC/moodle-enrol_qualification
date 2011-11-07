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
 * Meta course enrolment plugin.
 *
 * @package    enrol
 * @subpackage qualification
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Meta course enrolment plugin.
 * @author Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_qualification_plugin extends enrol_plugin {

    /**
     * Returns localised name of enrol instance
     *
     * @param object $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_'.$enrol);
        } else {
            if (empty($instance->name)) {
                $enrol = $this->get_name();
                $coursefullname = $DB->get_field('course',
                                                 'fullname',
                                                 array('id' => $instance->customint1));
                return get_string('pluginname', 'enrol_'.$enrol).' ('.
                       format_string($coursefullname).')';
            } else {
                return format_string($instance->name);
            }
        }
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = get_context_instance(CONTEXT_COURSE, $courseid, MUST_EXIST);
        if (!has_capability('moodle/course:enrolconfig', $context) or
            !has_capability('enrol/qualification:config', $context)) {

            return null;
        }
        // multiple instances supported - multiple parent courses linked
        return new moodle_url('/enrol/qualification/addinstance.php', array('id' => $courseid));
    }


    /**
     * Called after updating/inserting course.
     *
     * @param bool $inserted true if course just inserted
     * @param object $course
     * @param object $data form data
     * @return void
     */
    public function course_updated($inserted, $course, $data) {
        global $CFG;

        if (!$inserted) {
            // sync cohort enrols
            require_once("$CFG->dirroot/enrol/qualification/locallib.php");
            enrol_qualification_sync($course->id);
        }
        // cohorts are never inserted automatically

    }

    /**
     * Overriding so we can add an outcome sync when a new instance is added to a course.
     *
     * @param $course
     * @param array|null $fields
     * @return void
     */
    public function add_instance($course, array $fields = NULL) {
        parent::add_instance($course, $fields);

         // Make sure all the outcomes are updated
        $this->sync_outcome_grade_items($course->id);
    }

    /**
     * Called for all enabled enrol plugins that returned true from is_cron_required().
     * @return void
     */
    public function cron() {
        global $CFG;

        // purge all roles if qualification sync disabled, those can be recreated later here in cron
        if (!enrol_is_enabled('qualification')) {
            role_unassign_all(array('component' => 'qualification_enrol'));
            return;
        }

        require_once("$CFG->dirroot/enrol/qualification/locallib.php");
        enrol_qualification_sync();

        // We also want to make sure that every outcome is made into a grade item
        $this->sync_outcome_grade_items();
    }

    /**
     * This will get all of the course outcomes that a teacher has added and make grade items for
     * them automatically.
     *
     * @param null $courseid
     * @return void
     */
    public function sync_outcome_grade_items($courseid = null) {
        global $DB;

        // Get all outcomes in courses that have a qualification enrolment plugin
        $params = array();
        $sql = "SELECT outcomes.*
                  FROM {grade_outcomes} outcomes
            INNER JOIN {grade_outcomes_courses} outcomes_courses
                    ON (outcomes.id = outcomes_courses.outcomeid
                        AND outcomes.courseid = outcomes_courses.courseid)
            INNER JOIN {enrol} enrol
                    ON enrol.courseid = outcomes_courses.courseid
                 WHERE enrol.enrol = 'qualification'
                  AND NOT EXISTS (SELECT 1
                                    FROM {grade_items} items
                                   WHERE items.itemtype = 'outcome'
                                     AND items.iteminstance = outcomes.id
                                     AND items.courseid = outcomes.courseid
                                     ) ";
        if ($courseid) {
            $sql .= " AND outcomes.courseid = :courseid ";
            $params ['courseid'] = $courseid;
        }
        $outcomes = $DB->get_records_sql($sql, $params);
        // course outcomes for courses with joined qualification enrolment

        // Get all existing grade items that are for outcomes. We can then update any changed names

        // cross reference to add new ones (can SQL do this?)
        // grade/edit/tree/item.php
        // - Name
        // - Type
        // - Scale
        // - Max
        // - Min
        // - Hidden
        // - Locked
        foreach ($outcomes as $outcome) {

            $item = new stdClass();

            // TODO include grade lib
            // Update?
//            $grade_item = grade_item::fetch(array('instanceid'=>$outcome->id,
//                                                  'courseid'=>$outcome->courseid,
//                                                  'type' => 'outcome'));

            $data = new stdClass();
            $data->scaleid = $outcome->scaleid;
            $data->decimals = null;
            $data->aggregationcoef = 0;
            $data->courseid = $outcome->courseid;
            $data->grademax = 100;
            $data->grademin = 0;
            $data->gradetype = GRADE_TYPE_SCALE;
            $data->itemname = $outcome->shortname;
            $data->itemtype = 'outcome';
            $data->iteminstance = $data->outcomeid = $outcome->id;
            $data->idnumber = '';
            $data->iteminfo = $outcome->description;

            // We want the item to have the same scale as the outcome
            $grade_item = new grade_item(array('id' => 0, 'courseid' => $courseid));
            grade_item::set_properties($grade_item, $data);
            $grade_item->outcomeid = null;
            $grade_item->insert();


        }
        // cross reference to remove old ones?


    }


}

