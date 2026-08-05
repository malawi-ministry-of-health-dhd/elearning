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
use local_mohhierarchy\form\search_form;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\output\assignment_manager;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

$pageurl = new moodle_url('/local/mohhierarchy/assignments.php');
admin_externalpage_setup('local_mohhierarchy_assignments', '', null, $pageurl, ['pagelayout' => 'admin']);
$systemcontext = context_system::instance();
require_capability(permission_service::CAP_MANAGE_ASSIGNMENTS, $systemcontext);

$search = optional_param('search', '', PARAM_TEXT);
$page = max(0, optional_param('page', 0, PARAM_INT));
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
    foreach ($permissions->get_assignable_facilities((int) $USER->id) as $facility) {
        $facilityoptions[(int) $facility->facilityid] = get_string(
            'hierarchypath',
            'local_mohhierarchy',
            (object) [
                'zone' => $facility->zonename,
                'district' => $facility->districtname,
                'facility' => $facility->facilityname,
            ],
        );
    }

    // Keep an inactive current placement visible for context. It is not available when moving
    // somebody else, and the service repeats that rule when the form is saved.
    if ($current !== null && !isset($facilityoptions[(int) $current->facilityid])) {
        $facilityoptions[(int) $current->facilityid] = get_string(
            'hierarchypathinactive',
            'local_mohhierarchy',
            (object) [
                'zone' => $current->zonename,
                'district' => $current->districtname,
                'facility' => $current->facilityname,
            ],
        );
    }

    $formurl = new moodle_url($pageurl, ['userid' => $targetuserid, 'search' => $search]);
    $form = new assign_form($formurl, [
        'target' => $target,
        'targetname' => fullname($target) . ' (' . s($target->username) . ')',
        'facilities' => $facilityoptions,
        'scopes' => $permissions->grantable_scopes((int) $USER->id),
        'permissions' => $permissions,
        'currentfacilityid' => $current === null ? 0 : (int) $current->facilityid,
        'search' => $search,
    ]);
    if ($current !== null) {
        $form->set_data([
            'userid' => $targetuserid,
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
            || !$permissions->can_grant_scope((int) $USER->id, $scope)
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
    echo $OUTPUT->heading(get_string('editassignment', 'local_mohhierarchy'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$searchform = new search_form(
    $pageurl,
    ['label' => get_string('searchusers', 'local_mohhierarchy')],
    'get',
);
$searchform->set_data(['search' => $search]);

/** @var \local_mohhierarchy\output\renderer $renderer */
$renderer = $PAGE->get_renderer('local_mohhierarchy');
$manager = new assignment_manager((int) $USER->id, $search, $pageurl, $page);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assignmentadministration', 'local_mohhierarchy'));
$searchform->display();
echo $renderer->render_assignment_manager($manager);
echo $OUTPUT->footer();
