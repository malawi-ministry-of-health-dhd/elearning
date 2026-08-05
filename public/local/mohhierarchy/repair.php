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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Hierarchy consistency report and safe repair page.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\form\repair_form;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\hierarchy\repair_service;
use local_mohhierarchy\output\repair_report;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

$pageurl = new moodle_url('/local/mohhierarchy/repair.php');
admin_externalpage_setup('local_mohhierarchy_repair', '', null, $pageurl, ['pagelayout' => 'admin']);
require_capability(permission_service::CAP_REPAIR_CONSISTENCY, context_system::instance());

$form = new repair_form($pageurl);
$service = new repair_service();
$report = $service->scan();
if ($data = $form->get_data()) {
    require_sesskey();
    $apply = !empty($data->apply);
    $result = $service->repair(!$apply);
    if ($apply) {
        $report = $service->scan();
        \core\notification::success(get_string('repairapplied', 'local_mohhierarchy', $result['applied']));
    } else {
        $report = $result;
        \core\notification::info(get_string('repairdryrunresult', 'local_mohhierarchy', $result['planned']));
    }
}

/** @var \local_mohhierarchy\output\renderer $renderer */
$renderer = $PAGE->get_renderer('local_mohhierarchy');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('consistencyrepair', 'local_mohhierarchy'));
echo $OUTPUT->notification(get_string('repaircanonicalnotice', 'local_mohhierarchy'), 'info');
$form->display();
echo $renderer->render_repair_report(new repair_report($report));
echo $OUTPUT->footer();
