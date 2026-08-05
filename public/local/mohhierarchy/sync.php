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
 * Synchronisation administration dashboard.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\output\sync_dashboard;
use local_mohhierarchy\task\sync_hierarchy_adhoc;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$pageurl = new moodle_url('/local/mohhierarchy/sync.php');
admin_externalpage_setup('local_mohhierarchy_sync', '', null, $pageurl, ['pagelayout' => 'admin']);
require_capability(permission_service::CAP_MANAGE_SYNC, context_system::instance());

$page = optional_param('page', 0, PARAM_INT);
$run = optional_param('run', 0, PARAM_BOOL);

if ($run) {
    require_sesskey();
    if (sync_hierarchy_adhoc::queue((int) $USER->id)) {
        redirect(
            $pageurl,
            get_string('syncqueued', 'local_mohhierarchy'),
            null,
            \core\output\notification::NOTIFY_SUCCESS,
        );
    }
    redirect(
        $pageurl,
        get_string('syncpending', 'local_mohhierarchy'),
        null,
        \core\output\notification::NOTIFY_INFO,
    );
}

/** @var \local_mohhierarchy\output\renderer $renderer */
$renderer = $PAGE->get_renderer('local_mohhierarchy');
$dashboard = new sync_dashboard(config::create(), $pageurl, max(0, $page));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('syncadministration', 'local_mohhierarchy'));
echo $renderer->render_sync_dashboard($dashboard);
echo $OUTPUT->footer();
