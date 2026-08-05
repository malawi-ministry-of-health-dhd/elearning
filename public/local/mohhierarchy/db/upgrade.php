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
 * Upgrade steps for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Perform the upgrade steps for local_mohhierarchy.
 *
 * Every future step must follow the standard pattern below: guard on the version it was introduced
 * in, use $dbman rather than raw DDL, and call upgrade_plugin_savepoint() at the end so a failed
 * upgrade can be resumed. Steps must never delete Moodle users, and must never delete rows from
 * local_mohh_assign or local_mohh_assignlog; withdraw an assignment by setting active = 0 instead.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool
 */
function xmldb_local_mohhierarchy_upgrade(int $oldversion): bool {
    if ($oldversion < 2026073001) {
        // Manual tasks queued by the previous release did not yet have Moodle's stored-progress
        // record. Backfill it so an already-waiting task immediately gains the same progress
        // indicator as newly queued tasks.
        $task = \local_mohhierarchy\task\sync_hierarchy_adhoc::get_pending_task();
        if ($task !== null) {
            $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(
                $task::class,
                $task->get_id(),
            );
            if (\core\output\stored_progress_bar::get_by_idnumber($idnumber) === null) {
                $task->initialise_stored_progress();
            }
        }

        upgrade_plugin_savepoint(true, 2026073001, 'local', 'mohhierarchy');
    }

    if ($oldversion < 2026073002) {
        // Facility-first placement changes presentation only; no stored hierarchy data changes.
        upgrade_plugin_savepoint(true, 2026073002, 'local', 'mohhierarchy');
    }

    if ($oldversion < 2026073003) {
        // Searchable bidirectional placement changes presentation only; no stored data changes.
        upgrade_plugin_savepoint(true, 2026073003, 'local', 'mohhierarchy');
    }

    if ($oldversion < 2026080500) {
        // Facility-only autocomplete labels change presentation only; no stored data changes.
        upgrade_plugin_savepoint(true, 2026080500, 'local', 'mohhierarchy');
    }

    if ($oldversion < 2026080501) {
        // Searchable assignment placement changes presentation only; no stored data changes.
        upgrade_plugin_savepoint(true, 2026080501, 'local', 'mohhierarchy');
    }

    if ($oldversion < 2026080502) {
        // Blank required placement on core user creation changes validation only; no stored data changes.
        upgrade_plugin_savepoint(true, 2026080502, 'local', 'mohhierarchy');
    }

    return true;
}
