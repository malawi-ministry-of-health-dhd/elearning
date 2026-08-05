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

namespace local_mohhierarchy\output;

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\assignment_repository;
use local_mohhierarchy\local\hierarchy\district_repository;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\sync_log_repository;
use local_mohhierarchy\local\hierarchy\zone_repository;
use local_mohhierarchy\local\sync_status;
use local_mohhierarchy\local\sync_trigger;
use local_mohhierarchy\task\sync_hierarchy_adhoc;

/**
 * The synchronisation dashboard: endpoints, counts, outcomes and history.
 *
 * This class only assembles data. All markup lives in the template, so no HTML is built inside the
 * plugin's classes. The bearer token is never exported, only whether one is configured.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_dashboard implements \renderable, \templatable {
    /** @var config The current plugin settings. */
    protected config $config;

    /** @var \moodle_url This page, used for action and paging links. */
    protected \moodle_url $pageurl;

    /** @var int Zero-based history page number. */
    protected int $page;

    /** @var int History records per page. */
    protected int $perpage;

    /**
     * Constructor.
     *
     * @param config $config The current settings.
     * @param \moodle_url $pageurl This page, used for the action and paging links.
     * @param int $page Zero based page number of the history table.
     * @param int $perpage History rows per page.
     */
    public function __construct(
        config $config,
        \moodle_url $pageurl,
        int $page = 0,
        int $perpage = 20,
    ) {
        $this->config = $config;
        $this->pageurl = $pageurl;
        $this->page = $page;
        $this->perpage = $perpage;
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $synclog = new sync_log_repository();
        $total = $synclog->count_all();
        $pendingtask = sync_hierarchy_adhoc::get_pending_task();
        $taskindicator = '';
        if ($pendingtask !== null) {
            $taskindicator = $output->render(new \core\output\task_indicator(
                $pendingtask,
                get_string('syncprogressheading', 'local_mohhierarchy'),
                get_string('syncprogressqueued', 'local_mohhierarchy'),
                $this->pageurl,
            ));
        }
        $pendingwithouttask = $pendingtask === null && $synclog->has_running();

        return [
            'endpoints' => [
                [
                    'label' => get_string('zonesendpoint', 'local_mohhierarchy'),
                    'url' => $this->config->redact($this->config->zones_url()),
                ],
                [
                    'label' => get_string('districtsendpoint', 'local_mohhierarchy'),
                    'url' => $this->config->redact($this->config->districts_url()),
                ],
                [
                    'label' => get_string('facilitiesendpoint', 'local_mohhierarchy'),
                    'url' => $this->config->redact($this->config->facilities_url()),
                ],
            ],
            // Whether a token exists, never the token itself.
            'tokenconfigured' => $this->config->has_token(),
            'tokenstate' => $this->config->has_token()
                ? get_string('tokenset', 'local_mohhierarchy')
                : get_string('tokennotset', 'local_mohhierarchy'),
            'counts' => [
                ['label' => get_string('activezones', 'local_mohhierarchy'), 'value' => (new zone_repository())->count()],
                [
                    'label' => get_string('activedistricts', 'local_mohhierarchy'),
                    'value' => (new district_repository())->count(),
                ],
                [
                    'label' => get_string('activefacilities', 'local_mohhierarchy'),
                    'value' => (new facility_repository())->count(),
                ],
                [
                    'label' => get_string('userassignments', 'local_mohhierarchy'),
                    'value' => (new assignment_repository())->count(),
                ],
            ],
            'lastsuccess' => $this->summarise($synclog->get_last_successful()),
            'lastfailure' => $this->summarise($synclog->get_last_failed()),
            'taskindicator' => $taskindicator,
            'hastaskindicator' => $taskindicator !== '',
            'pending' => $pendingwithouttask,
            'pendingmessage' => get_string('syncpending', 'local_mohhierarchy'),
            'syncurl' => $this->pageurl->out(false),
            'sesskey' => sesskey(),
            'runs' => array_values(array_map(
                fn(\stdClass $run): array => $this->summarise($run),
                $synclog->get_page($this->page * $this->perpage, $this->perpage),
            )),
            'hasruns' => $total > 0,
            'paging' => $output->render(new \paging_bar($total, $this->page, $this->perpage, $this->pageurl)),
        ];
    }

    /**
     * Reduce one run log row to template data.
     *
     * @param \stdClass|null $run A local_mohh_synclog row.
     * @return array Empty when there is no such run.
     */
    protected function summarise(?\stdClass $run): array {
        if ($run === null) {
            return ['exists' => false, 'never' => get_string('syncnever', 'local_mohhierarchy')];
        }

        return [
            'exists' => true,
            'status' => get_string('syncstatus:' . sync_status::from_value($run->status)->value, 'local_mohhierarchy'),
            'failed' => $run->status === sync_status::FAILED->value,
            'trigger' => get_string(
                'synctrigger:' . sync_trigger::from_value($run->triggerkind)->value,
                'local_mohhierarchy',
            ),
            'started' => userdate($run->startedat),
            'finished' => $run->finishedat ? userdate($run->finishedat) : '',
            'zones' => $run->zonescreated . ' / ' . $run->zonesupdated,
            'districts' => $run->districtscreated . ' / ' . $run->districtsupdated,
            'facilities' => $run->facilitiescreated . ' / ' . $run->facilitiesupdated,
            'deactivated' => (int) $run->itemsdeactivated,
            'error' => $this->config->redact((string) ($run->errormessage ?? '')),
        ];
    }
}
