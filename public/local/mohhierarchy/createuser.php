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
 * Strict delegated hierarchy user creation.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\form\create_user_form;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\hierarchy\selector_options;
use local_mohhierarchy\local\user_creation_service;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

require_login();
$systemcontext = context_system::instance();
require_capability(permission_service::CAP_CREATE_USER, $systemcontext);

$pageurl = new moodle_url('/local/mohhierarchy/createuser.php');
admin_externalpage_setup('local_mohhierarchy_createuser', '', null, $pageurl, ['pagelayout' => 'admin']);

if (isset($SESSION->local_mohhierarchy_created_user)) {
    $created = $SESSION->local_mohhierarchy_created_user;
    unset($SESSION->local_mohhierarchy_created_user);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('hierarchyusercreated', 'local_mohhierarchy'));
    echo $OUTPUT->notification(
        get_string('usercreatedsuccess', 'local_mohhierarchy', (object) [
            'fullname' => s((string) $created->fullname),
            'username' => s((string) $created->username),
        ]),
        \core\output\notification::NOTIFY_SUCCESS,
    );
    if ($created->passwordemailed === false) {
        echo $OUTPUT->notification(
            get_string('generatedpasswordmailfailed', 'local_mohhierarchy'),
            \core\output\notification::NOTIFY_WARNING,
        );
    }
    echo $OUTPUT->single_button($pageurl, get_string('createanotheruser', 'local_mohhierarchy'));
    echo $OUTPUT->footer();
    exit;
}

$service = new user_creation_service();
$form = new create_user_form($pageurl, [
    'actorid' => (int) $USER->id,
    'service' => $service,
    'selectors' => new selector_options(),
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/mohhierarchy/hierarchy.php'));
}
if ($data = $form->get_data()) {
    require_sesskey();
    // The service repeats capability, hierarchy ancestry, active-state and scope checks after
    // acquiring its creation lock. Form state and JavaScript are never treated as authorisation.
    $SESSION->local_mohhierarchy_created_user = $service->create_user($data, (int) $USER->id);
    redirect($pageurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('createhierarchyuser', 'local_mohhierarchy'));
echo $OUTPUT->notification(
    get_string('delegatedcreationnotice', 'local_mohhierarchy'),
    \core\output\notification::NOTIFY_INFO,
);
$form->display();
echo $OUTPUT->footer();
