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

use local_mohhierarchy\local\assign_action;

/**
 * Append-only database access for the assignment history.
 *
 * This class deliberately exposes no update or delete method. History is never overwritten and is
 * never pruned automatically; removing it requires uninstalling the plugin.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_log_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_assignlog';

    /**
     * Record one assignment change.
     *
     * @param int $userid The user whose assignment changed.
     * @param int|null $assignmentid The assignment row, or null when it no longer exists.
     * @param assign_action $action What happened.
     * @param \stdClass|null $olddata Snapshot before the change, null for a creation.
     * @param \stdClass|null $newdata Snapshot after the change, null for a removal.
     * @param int|null $changedby The acting user, or null for automated changes.
     * @param string|null $reason Free-text note, truncated to the column width.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return int The new log row id.
     */
    public function add(
        int $userid,
        ?int $assignmentid,
        assign_action $action,
        ?\stdClass $olddata = null,
        ?\stdClass $newdata = null,
        ?int $changedby = null,
        ?string $reason = null,
        ?int $now = null,
    ): int {
        global $DB;

        $record = (object) [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
            'action' => $action->value,
            'olddata' => self::encode($olddata),
            'newdata' => self::encode($newdata),
            'changedby' => $changedby,
            'reason' => $reason === null ? null : \core_text::substr($reason, 0, 255),
            'timecreated' => $now ?? time(),
        ];

        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * The history of one user, newest first.
     *
     * @param int $userid The user.
     * @param int $limitfrom Offset.
     * @param int $limitnum Page size, 0 for all rows.
     * @return \stdClass[] Keyed by log row id.
     */
    public function get_for_user(int $userid, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        return $DB->get_records(
            self::TABLE,
            ['userid' => $userid],
            'timecreated DESC, id DESC',
            '*',
            $limitfrom,
            $limitnum,
        );
    }

    /**
     * How many history rows a user has.
     *
     * @param int $userid The user.
     * @return int
     */
    public function count_for_user(int $userid): int {
        global $DB;

        return $DB->count_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Encode a snapshot for storage.
     *
     * @param \stdClass|null $data The snapshot.
     * @return string|null JSON text, or null when there is no snapshot.
     */
    protected static function encode(?\stdClass $data): ?string {
        if ($data === null) {
            return null;
        }
        $json = json_encode($data);
        if ($json === false) {
            throw new \moodle_exception('error:jsonencodefailed', 'local_mohhierarchy', '', json_last_error_msg());
        }

        return $json;
    }
}
