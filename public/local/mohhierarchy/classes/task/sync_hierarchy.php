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

namespace local_mohhierarchy\task;

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\sync_service;
use local_mohhierarchy\local\sync_trigger;

/**
 * Refreshes the local copy of the hierarchy on a schedule.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_hierarchy extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:synchierarchy', 'local_mohhierarchy');
    }

    #[\Override]
    public function execute() {
        $config = config::create();
        if (!$config->scheduled_sync_enabled()) {
            mtrace(get_string('syncdisabled', 'local_mohhierarchy'));
            return;
        }

        // Do not print a configured URL: deployments sometimes carry credentials in URL query
        // parameters even though the supported token setting uses a hidden header.
        mtrace(get_string('syncstartingscheduled', 'local_mohhierarchy'));

        try {
            $run = sync_service::create(null, $config)->run(sync_trigger::SCHEDULED);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'error:syncalreadyrunning') {
                // Another run holds the lock, most likely a manual or CLI sync. Not a failure.
                mtrace(get_string('error:syncalreadyrunning', 'local_mohhierarchy'));
                return;
            }
            throw new \moodle_exception(
                'error:syncfailedsanitised',
                'local_mohhierarchy',
                '',
                $config->redact($e->getMessage()),
            );
        } catch (\Throwable $e) {
            throw new \moodle_exception(
                'error:syncfailedsanitised',
                'local_mohhierarchy',
                '',
                $config->redact($e->getMessage()),
            );
        }

        mtrace(get_string('synccompleted', 'local_mohhierarchy', $run));
    }
}
