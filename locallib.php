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
 * Local stuff for qualification course enrolment plugin.
 *
 * @package    enrol
 * @subpackage qualification
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Event handler for qualification enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_qualification_handler {
    public function role_assigned($ra) {
        global $DB;

        if (!enrol_is_enabled('qualification')) {
            return true;
        }

        // prevent circular dependencies - we can not sync qualification roles recursively
        if ($ra->component === 'enrol_qualification') {
            return true;
        }

        // only course level roles are interesting
        $parentcontext = get_context_instance_by_id($ra->contextid);
        if ($parentcontext->contextlevel != CONTEXT_COURSE) {
            return true;
        }

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$parentcontext->instanceid, 'enrol'=>'qualification'), 'id ASC')) {
            return true;
        }

        // make sure the role sync is not prevented
        $plugin = enrol_get_plugin('qualification');
        if ($disabled = $plugin->get_config('nosyncroleids')) {
            if (in_array($ra->roleid, explode(',', $disabled))) {
                return true;
            }
        }

        foreach ($enrols as $enrol) {
            // Is the user enrolled? We want to sync only really enrolled users
            if (!$DB->record_exists('user_enrolments', array('userid'=>$ra->userid, 'enrolid'=>$enrol->id))) {
                continue;
            }
            $context = get_context_instance(CONTEXT_COURSE, $enrol->courseid);

            // just try to assign role, no problem if role assignment already exists
            role_assign($ra->roleid, $ra->userid, $context->id, 'enrol_qualification', $enrol->id);
        }

        return true;
    }

    public function role_unassigned($ra) {
        global $DB;

        // note: do not test if plugin enabled, we want to keep removing previous roles

        // prevent circular dependencies - we can not sync qualification roles recursively
        if ($ra->component === 'enrol_qualification') {
            return true;
        }

        // only course level roles are interesting
        $parentcontext = get_context_instance_by_id($ra->contextid);
        if ($parentcontext->contextlevel != CONTEXT_COURSE) {
            return true;
        }

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$parentcontext->instanceid, 'enrol'=>'qualification'), 'id ASC')) {
            return true;
        }

        // note: do not check 'nosyncroleids', somebody might have just enabled it, we want to get rid of nosync roles gradually

        foreach ($enrols as $enrol) {
            // Is the user enrolled? We want to sync only really enrolled users
            if (!$DB->record_exists('user_enrolments', array('userid'=>$ra->userid, 'enrolid'=>$enrol->id))) {
                continue;
            }
            $context = get_context_instance(CONTEXT_COURSE, $enrol->courseid);

            // now make sure the user does not have the role through some other enrol plugin
            $params = array('contextid'=>$ra->contextid, 'roleid'=>$ra->roleid, 'userid'=>$ra->userid);
            if ($DB->record_exists_select('role_assignments', "contextid = :contextid AND roleid = :roleid AND userid = :userid AND component <> 'enrol_qualification'", $params)) {
                continue;
            }

            // unassign role, there is no other role assignment in parent course
            role_unassign($ra->roleid, $ra->userid, $context->id, 'enrol_qualification', $enrol->id);
        }

        return true;
    }

    public function user_enrolled($ue) {
        global $DB;

        if (!enrol_is_enabled('qualification')) {
            return true;
        }

        if ($ue->enrol === 'qualification') {
            // prevent circular dependencies - we can not sync qualification enrolments recursively
            return true;
        }

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$ue->courseid, 'enrol'=>'qualification'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('qualification');
        foreach ($enrols as $enrol) {
            // no problem if already enrolled
            $plugin->enrol_user($enrol, $ue->userid);
        }

        return true;
    }

    public function user_unenrolled($ue) {
        global $DB;

        //note: do not test if plugin enabled, we want to keep removing previously linked courses

        // look for unenrolment candidates - it may be possible that user has multiple enrolments...
        $sql = "SELECT e.*
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = :userid)
                  JOIN {enrol} pe ON (pe.courseid = e.customint1 AND pe.enrol <> 'qualification' AND pe.courseid = :courseid)
             LEFT JOIN {user_enrolments} pue ON (pue.enrolid = pe.id AND pue.userid = ue.userid)
                 WHERE pue.id IS NULL AND e.enrol = 'qualification'";
        $params = array('courseid'=>$ue->courseid, 'userid'=>$ue->userid);

        $rs = $DB->get_recordset_sql($sql, $params);

        $plugin = enrol_get_plugin('qualification');
        foreach ($rs as $enrol) {
            $plugin->unenrol_user($enrol, $ue->userid);
        }
        $rs->close();

        return true;
    }

    public function course_deleted($course) {
        global $DB;

        // note: do not test if plugin enabled, we want to keep removing previously linked courses

        // does anything want to sync with this parent?
        if (!$enrols = $DB->get_records('enrol', array('customint1'=>$course->id, 'enrol'=>'qualification'), 'id ASC')) {
            return true;
        }

        $plugin = enrol_get_plugin('qualification');
        foreach ($enrols as $enrol) {
            // unenrol all users
            $ues = $DB->get_recordset('user_enrolments', array('enrolid'=>$enrol->id));
            foreach ($ues as $ue) {
                $plugin->unenrol_user($enrol, $ue->userid);
            }
            $ues->close();
        }

        return true;
    }
}


/**
 * Sync all qualification course links.
 * @param int $courseid one course, empty mean all
 * @return void
 */
function enrol_qualification_sync($courseid = NULL) {
    global $CFG, $DB;

    // unfortunately this may take a loooong time
    @set_time_limit(0); //if this fails during upgrade we can continue from cron, no big deal

    $qualification = enrol_get_plugin('qualification');

    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";

    // iterate through all not enrolled yet users
    if (enrol_is_enabled('qualification')) {
        list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
        $onecourse = "";
        if ($courseid) {
            $params['courseid'] = $courseid;
            $onecourse = "AND e.courseid = :courseid";
        }
        $sql = "SELECT pue.userid, e.id AS enrolid
                  FROM {user_enrolments} pue
                  JOIN {enrol} pe ON (pe.id = pue.enrolid AND pe.enrol <> 'qualification' AND pe.enrol $enabled )
                  JOIN {enrol} e ON (e.customint1 = pe.courseid AND e.enrol = 'qualification' AND e.status = :statusenabled $onecourse)
             LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = pue.userid)
                 WHERE ue.id IS NULL";
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['courseid'] = $courseid;

        $rs = $DB->get_recordset_sql($sql, $params);
        $instances = array(); //cache
        foreach($rs as $ue) {
            if (!isset($instances[$ue->enrolid])) {
                $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
            }
            $qualification->enrol_user($instances[$ue->enrolid], $ue->userid);
        }
        $rs->close();
        unset($instances);
    }

    // unenrol as necessary - ignore enabled flag, we want to get rid of all
    $sql = "SELECT ue.userid, e.id AS enrolid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'qualification' $onecourse)
         LEFT JOIN (SELECT xue.userid, xe.courseid
                      FROM {enrol} xe
                      JOIN {user_enrolments} xue ON (xue.enrolid = xe.id)
                   ) pue ON (pue.courseid = e.customint1 AND pue.userid = ue.userid)
             WHERE pue.courseid IS NULL";
    //TODO: this may use a bit of SQL optimisation
    $rs = $DB->get_recordset_sql($sql, array('courseid'=>$courseid));
    $instances = array(); //cache
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $DB->get_record('enrol', array('id'=>$ue->enrolid));
        }
        $qualification->unenrol_user($instances[$ue->enrolid], $ue->userid);
    }
    $rs->close();
    unset($instances);

    // now assign all necessary roles
    if (enrol_is_enabled('qualification')) {
        $enabled = explode(',', $CFG->enrol_plugins_enabled);
        foreach($enabled as $k=>$v) {
            if ($v === 'qualification') {
                continue; // no qualification sync of qualification roles
            }
            $enabled[$k] = 'enrol_'.$v;
        }
        $enabled[] = $DB->sql_empty(); // manual assignments are replicated too

        list($enabled, $params) = $DB->get_in_or_equal($enabled, SQL_PARAMS_NAMED, 'e');
        $sql = "SELECT DISTINCT pra.roleid, pra.userid, c.id AS contextid, e.id AS enrolid
                  FROM {role_assignments} pra
                  JOIN {user} u ON (u.id = pra.userid AND u.deleted = 0)
                  JOIN {context} pc ON (pc.id = pra.contextid AND pc.contextlevel = :coursecontext AND pra.component $enabled)
                  JOIN {enrol} e ON (e.customint1 = pc.instanceid AND e.enrol = 'qualification' AND e.status = :statusenabled $onecourse)
                  JOIN {context} c ON (c.contextlevel = pc.contextlevel AND c.instanceid = e.courseid)
             LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = pra.userid AND ra.roleid = pra.itemid AND ra.itemid = e.id AND ra.component = 'enrol_qualification')
                 WHERE ra.id IS NULL";
        $params['statusenabled'] = ENROL_INSTANCE_ENABLED;
        $params['coursecontext'] = CONTEXT_COURSE;
        $params['courseid'] = $courseid;

        if ($ignored = $qualification->get_config('nosyncroleids')) {
            list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
            $params = array_merge($params, $xparams);
            $sql = "$sql AND pra.roleid $notignored";
        }

        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_qualification', $ra->enrolid);
        }
        $rs->close();
    }

    // remove unwanted roles - include ignored roles and disabled plugins too
    $params = array('coursecontext' => CONTEXT_COURSE, 'courseid' => $courseid);
    if ($ignored = $qualification->get_config('nosyncroleids')) {
        list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
        $params = array_merge($params, $xparams);
        $notignored = "AND pra.roleid $notignored";
    } else {
        $notignored = "";
    }
    $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid
              FROM {role_assignments} ra
              JOIN {enrol} e ON (e.id = ra.itemid AND ra.component = 'enrol_qualification' AND e.enrol = 'qualification' $onecourse)
              JOIN {context} pc ON (pc.instanceid = e.customint1 AND pc.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} pra ON (pra.contextid = pc.id AND pra.userid = ra.userid AND pra.roleid = ra.roleid AND pra.component <> 'enrol_qualification' $notignored)
             WHERE pra.id IS NULL";

    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_qualification', $ra->itemid);
    }
    $rs->close();

}
