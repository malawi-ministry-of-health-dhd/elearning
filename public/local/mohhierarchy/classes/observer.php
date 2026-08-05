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

namespace local_mohhierarchy;

use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\repair_service;

/**
 * User lifecycle observers.
 *
 * These callbacks maintain already-authorised data. They never decide whether an account or
 * assignment may be created and never recreate a deleted user.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Withdraw the operational assignment after Moodle deletes an account.
     *
     * @param \core\event\user_deleted $event Deleted-user event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        (new assignment_service())->withdraw_assignment(
            (int) $event->objectid,
            null,
            get_string('repairreason:user_deleted', 'local_mohhierarchy'),
        );
    }

    /**
     * Verify mirrors after a validated account-creation flow.
     *
     * @param \core\event\user_created $event Created-user event.
     * @return void
     */
    public static function user_created(\core\event\user_created $event): void {
        self::verify((int) $event->objectid);
    }

    /**
     * Verify mirrors after a user update.
     *
     * @param \core\event\user_updated $event Updated-user event.
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        self::verify((int) $event->objectid);
    }

    /**
     * Apply only the repair service's non-conflicting actions.
     *
     * @param int $userid Event target.
     * @return void
     */
    protected static function verify(int $userid): void {
        try {
            (new repair_service())->repair_user($userid);
        } catch (\Throwable $e) {
            // A lifecycle event must not turn repair contention into a failed core user operation.
            debugging('MoH hierarchy consistency verification was deferred: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
