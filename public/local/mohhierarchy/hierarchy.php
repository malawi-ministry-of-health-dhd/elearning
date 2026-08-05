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
 * Read-only hierarchy administration browser.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\form\search_form;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\output\hierarchy_browser;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

$pageurl = new moodle_url('/local/mohhierarchy/hierarchy.php');
admin_externalpage_setup('local_mohhierarchy_hierarchy', '', null, $pageurl, ['pagelayout' => 'admin']);
require_capability(permission_service::CAP_VIEW_HIERARCHY, context_system::instance());

$search = optional_param('search', '', PARAM_TEXT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$searchform = new search_form(
    $pageurl,
    ['label' => get_string('searchhierarchy', 'local_mohhierarchy')],
    'get',
);
$searchform->set_data(['search' => $search]);

/** @var \local_mohhierarchy\output\renderer $renderer */
$renderer = $PAGE->get_renderer('local_mohhierarchy');
$browser = new hierarchy_browser((int) $USER->id, $search, $pageurl, $page);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('hierarchybrowser', 'local_mohhierarchy'));
$searchform->display();
echo $renderer->render_hierarchy_browser($browser);
echo $OUTPUT->footer();
