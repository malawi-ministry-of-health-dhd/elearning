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
 * User hierarchy assignment administration.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\form\assign_form;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\reportbuilder\local\systemreports\jurisdiction_users;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

$pageurl = new moodle_url('/local/mohhierarchy/assignments.php');
admin_externalpage_setup('local_mohhierarchy_assignments', '', null, $pageurl, ['pagelayout' => 'admin']);
$systemcontext = context_system::instance();
require_capability(permission_service::CAP_MANAGE_ASSIGNMENTS, $systemcontext);

$search = optional_param('search', '', PARAM_TEXT);
$targetuserid = optional_param('userid', 0, PARAM_INT);
$permissions = new permission_service();
$assignments = new assignment_service();

if ($targetuserid > 0) {
    $target = core_user::get_user(
        $targetuserid,
        'id, username, firstname, lastname, email, deleted',
        MUST_EXIST,
    );
    if ((int) $target->deleted === 1) {
        throw new moodle_exception('error:unknownuser', 'local_mohhierarchy', '', $targetuserid);
    }
    if (!$permissions->can_manage_assignment((int) $USER->id, $targetuserid)) {
        throw new required_capability_exception(
            $systemcontext,
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            'nopermissions',
            '',
        );
    }

    $target->userid = (int) $target->id;
    $current = $assignments->get_assignment_with_names($targetuserid);
    $facilityoptions = [];
    $zoneoptions = [];
    $districtoptions = [];
    $facilitypaths = [];
    foreach ($permissions->get_assignable_facilities((int) $USER->id) as $facility) {
        $facilityid = (int) $facility->facilityid;
        $districtid = (int) $facility->districtid;
        $zoneid = (int) $facility->zoneid;
        $facilityoptions[$facilityid] = format_string($facility->facilityname);
        $districtoptions[$districtid] = format_string($facility->districtname);
        $zoneoptions[$zoneid] = format_string($facility->zonename);
        $facilitypaths[$facilityid] = ['zoneid' => $zoneid, 'districtid' => $districtid];
    }

    // Keep an inactive current placement visible for context. It is not available when moving
    // somebody else, and the service repeats that rule when the form is saved.
    if ($current !== null && !isset($facilityoptions[(int) $current->facilityid])) {
        $facilityid = (int) $current->facilityid;
        $districtid = (int) $current->districtid;
        $zoneid = (int) $current->zoneid;
        $facilityoptions[$facilityid] = get_string(
            'facilityinactivecurrent',
            'local_mohhierarchy',
            format_string($current->facilityname),
        );
        $districtoptions[$districtid] = format_string($current->districtname);
        $zoneoptions[$zoneid] = format_string($current->zonename);
        $facilitypaths[$facilityid] = ['zoneid' => $zoneid, 'districtid' => $districtid];
    }

    $formurl = new moodle_url($pageurl, ['userid' => $targetuserid, 'search' => $search]);
    $form = new assign_form($formurl, [
        'target' => $target,
        'targetname' => fullname($target) . ' (' . s($target->username) . ')',
        'zones' => $zoneoptions,
        'districts' => $districtoptions,
        'facilities' => $facilityoptions,
        'facilitypaths' => $facilitypaths,
        'scopes' => $permissions->grantable_scopes((int) $USER->id),
        'permissions' => $permissions,
        'currentfacilityid' => $current === null ? 0 : (int) $current->facilityid,
        'search' => $search,
    ]);
    if ($current !== null) {
        $form->set_data([
            'userid' => $targetuserid,
            'zoneid' => (int) $current->zoneid,
            'districtid' => (int) $current->districtid,
            'facilityid' => (int) $current->facilityid,
            'scopelevel' => $current->scopelevel,
            'search' => $search,
        ]);
    }

    if ($form->is_cancelled()) {
        redirect(new moodle_url($pageurl, ['search' => $search]));
    }
    if ($data = $form->get_data()) {
        require_sesskey();
        $scope = scope_level::from_value($data->scopelevel);
        // These checks duplicate form validation intentionally: controllers and services do not
        // trust a browser submission or rely on JavaScript/form state as the security boundary.
        if (
            (int) $data->userid !== $targetuserid
            ||
            !$permissions->can_manage_assignment((int) $USER->id, (int) $data->userid)
            || !$permissions->can_grant_scope_to_user((int) $USER->id, (int) $data->userid, $scope)
            || (
                !$permissions->can_assign_user_to_facility((int) $USER->id, (int) $data->facilityid)
                && (int) $data->facilityid !== (int) ($current->facilityid ?? 0)
            )
        ) {
            throw new required_capability_exception(
                $systemcontext,
                permission_service::CAP_MANAGE_ASSIGNMENTS,
                'nopermissions',
                '',
            );
        }

        $assignments->assign_user(
            (int) $data->userid,
            (int) $data->facilityid,
            $scope,
            (int) $USER->id,
            assign_source::ADMIN,
            trim((string) $data->reason) ?: null,
        );
        redirect(
            new moodle_url($pageurl, ['search' => $search]),
            get_string('assignmentsaved', 'local_mohhierarchy'),
            null,
            \core\output\notification::NOTIFY_SUCCESS,
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('transferuser', 'local_mohhierarchy'));
    if (!is_siteadmin($USER)) {
        echo $OUTPUT->notification(
            get_string('jurisdictionnotice', 'local_mohhierarchy'),
            \core\output\notification::NOTIFY_INFO,
        );
    }
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$report = \core_reportbuilder\system_report_factory::create(
    jurisdiction_users::class,
    $systemcontext,
    parameters: ['withcheckboxes' => false],
);
if (is_siteadmin($USER) || has_capability(permission_service::CAP_CREATE_USER, $systemcontext)) {
    $createurl = is_siteadmin($USER)
        ? new moodle_url('/user/editadvanced.php', ['id' => -1])
        : new moodle_url('/local/mohhierarchy/createuser.php');
    $report->set_report_action(new \core_reportbuilder\output\report_action(
        get_string('addnewuser', 'moodle'),
        ['class' => 'btn btn-primary ms-auto', 'data-action' => 'add-user', 'href' => (string) $createurl],
        'a',
    ));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(is_siteadmin($USER)
    ? get_string('assignmentadministration', 'local_mohhierarchy')
    : get_string('jurisdictionusers', 'local_mohhierarchy'));
if (!is_siteadmin($USER)) {
    echo $OUTPUT->notification(
        get_string('jurisdictionnotice', 'local_mohhierarchy'),
        \core\output\notification::NOTIFY_INFO,
    );
}
echo $report->output();
echo $OUTPUT->footer();
