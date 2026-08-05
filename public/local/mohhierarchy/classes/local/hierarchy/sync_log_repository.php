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

namespace local_mohhierarchy\local\hierarchy;

use local_mohhierarchy\local\sync_status;
use local_mohhierarchy\local\sync_trigger;

/**
 * Database access for the synchronisation run log.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_log_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_synclog';

    /** @var string[] The counter columns a run may report. */
    public const COUNTERS = [
        'zonescreated',
        'zonesupdated',
        'districtscreated',
        'districtsupdated',
        'facilitiescreated',
        'facilitiesupdated',
        'itemsdeactivated',
    ];

    /**
     * Open a run log row before any remote call is made.
     *
     * @param sync_trigger $trigger What started the run.
     * @param int|null $triggeredby The acting user for a manual run, null otherwise.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return int The run id, to be passed back to finish().
     */
    public function start(sync_trigger $trigger, ?int $triggeredby = null, ?int $now = null): int {
        global $DB;

        $now = $now ?? time();
        $record = (object) [
            'status' => sync_status::RUNNING->value,
            'triggerkind' => $trigger->value,
            'triggeredby' => $triggeredby,
            'startedat' => $now,
            'finishedat' => null,
            'errormessage' => null,
            'timecreated' => $now,
        ];
        foreach (self::COUNTERS as $counter) {
            $record->{$counter} = 0;
        }

        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Close a run log row with its outcome.
     *
     * @param int $id The run id returned by start().
     * @param sync_status $status The outcome.
     * @param array $counters Counter column name => value, restricted to self::COUNTERS.
     * @param string|null $errormessage The failure detail, for a failed run.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return void
     */
    public function finish(
        int $id,
        sync_status $status,
        array $counters = [],
        ?string $errormessage = null,
        ?int $now = null,
    ): void {
        global $DB;

        $unknown = array_diff(array_keys($counters), self::COUNTERS);
        if ($unknown) {
            throw new \coding_exception('Unknown sync counter: ' . implode(', ', $unknown));
        }

        $record = (object) [
            'id' => $id,
            'status' => $status->value,
            'finishedat' => $now ?? time(),
            'errormessage' => $errormessage,
        ];
        foreach ($counters as $counter => $value) {
            $record->{$counter} = (int) $value;
        }

        $DB->update_record(self::TABLE, $record);
    }

    /**
     * One run by id.
     *
     * @param int $id The run id.
     * @return \stdClass|null
     */
    public function get(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * The most recent runs, newest first.
     *
     * @param int $limit Page size.
     * @return \stdClass[] Keyed by run id.
     */
    public function get_recent(int $limit = 20): array {
        global $DB;

        return $DB->get_records(self::TABLE, null, 'timecreated DESC, id DESC', '*', 0, $limit);
    }

    /**
     * One page of runs, newest first.
     *
     * @param int $limitfrom Offset.
     * @param int $limitnum Page size.
     * @return \stdClass[] Keyed by run id.
     */
    public function get_page(int $limitfrom, int $limitnum): array {
        global $DB;

        return $DB->get_records(self::TABLE, null, 'timecreated DESC, id DESC', '*', $limitfrom, $limitnum);
    }

    /**
     * How many runs have been recorded.
     *
     * @return int
     */
    public function count_all(): int {
        global $DB;

        return $DB->count_records(self::TABLE);
    }

    /**
     * The most recent run that failed.
     *
     * @return \stdClass|null
     */
    public function get_last_failed(): ?\stdClass {
        return $this->get_latest_with_status(sync_status::FAILED);
    }

    /**
     * The last run that completed successfully.
     *
     * @return \stdClass|null
     */
    public function get_last_successful(): ?\stdClass {
        return $this->get_latest_with_status(sync_status::SUCCESS);
    }

    /**
     * The most recent run with a given outcome.
     *
     * @param sync_status $status The outcome to look for.
     * @return \stdClass|null
     */
    protected function get_latest_with_status(sync_status $status): ?\stdClass {
        global $DB;

        $records = $DB->get_records(
            self::TABLE,
            ['status' => $status->value],
            'finishedat DESC, id DESC',
            '*',
            0,
            1,
        );

        return $records ? reset($records) : null;
    }

    /**
     * Whether a run is currently marked as in progress.
     *
     * @return bool
     */
    public function has_running(): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['status' => sync_status::RUNNING->value]);
    }
}
