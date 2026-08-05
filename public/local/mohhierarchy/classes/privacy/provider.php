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

namespace local_mohhierarchy\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_mohhierarchy\local\hierarchy\assignment_service;

/**
 * Privacy provider for hierarchy assignments and their audit records.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_mohh_assign', [
            'userid' => 'privacy:metadata:assign:userid',
            'zoneid' => 'privacy:metadata:assign:zoneid',
            'districtid' => 'privacy:metadata:assign:districtid',
            'facilityid' => 'privacy:metadata:assign:facilityid',
            'scopelevel' => 'privacy:metadata:assign:scopelevel',
            'assignedby' => 'privacy:metadata:assign:assignedby',
            'assignsource' => 'privacy:metadata:assign:assignsource',
            'active' => 'privacy:metadata:assign:active',
            'timecreated' => 'privacy:metadata:assign:timecreated',
            'timemodified' => 'privacy:metadata:assign:timemodified',
        ], 'privacy:metadata:assign');
        $collection->add_database_table('local_mohh_assignlog', [
            'userid' => 'privacy:metadata:assignlog:userid',
            'assignmentid' => 'privacy:metadata:assignlog:assignmentid',
            'action' => 'privacy:metadata:assignlog:action',
            'olddata' => 'privacy:metadata:assignlog:olddata',
            'newdata' => 'privacy:metadata:assignlog:newdata',
            'changedby' => 'privacy:metadata:assignlog:changedby',
            'reason' => 'privacy:metadata:assignlog:reason',
            'timecreated' => 'privacy:metadata:assignlog:timecreated',
        ], 'privacy:metadata:assignlog');
        $collection->add_database_table('local_mohh_synclog', [
            'triggeredby' => 'privacy:metadata:synclog:triggeredby',
        ], 'privacy:metadata:synclog');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasdata = $DB->record_exists('local_mohh_assign', ['userid' => $userid])
            || $DB->record_exists('local_mohh_assign', ['assignedby' => $userid])
            || $DB->record_exists('local_mohh_assignlog', ['userid' => $userid])
            || $DB->record_exists('local_mohh_assignlog', ['changedby' => $userid])
            || $DB->record_exists('local_mohh_synclog', ['triggeredby' => $userid]);
        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_mohh_assign}', []);
        $userlist->add_from_sql('userid', 'SELECT assignedby AS userid FROM {local_mohh_assign}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_mohh_assignlog}', []);
        $userlist->add_from_sql('userid', 'SELECT changedby AS userid FROM {local_mohh_assignlog}', []);
        $userlist->add_from_sql('userid', 'SELECT triggeredby AS userid FROM {local_mohh_synclog}', []);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist)) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $userid]);
        $history = array_values($DB->get_records(
            'local_mohh_assignlog',
            ['userid' => $userid],
            'timecreated ASC, id ASC',
        ));
        $actorassignments = array_values($DB->get_records('local_mohh_assign', ['assignedby' => $userid]));
        $actorhistory = array_values($DB->get_records('local_mohh_assignlog', ['changedby' => $userid]));
        $syncruns = array_values($DB->get_records('local_mohh_synclog', ['triggeredby' => $userid]));
        foreach ($history as $row) {
            $row->timecreated = transform::datetime($row->timecreated);
        }
        if ($assignment) {
            $assignment->timecreated = transform::datetime($assignment->timecreated);
            $assignment->timemodified = transform::datetime($assignment->timemodified);
        }
        $data = (object) [
            'assignment' => $assignment ?: null,
            'assignmenthistory' => $history,
            'assignmentsmadebyuser' => $actorassignments,
            'historychangesmadebyuser' => $actorhistory,
            'synchronisationsstartedbyuser' => $syncruns,
        ];
        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_mohhierarchy')],
            $data,
        );
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }
        foreach ($DB->get_fieldset_select('local_mohh_assign', 'userid', 'active = 1', []) as $userid) {
            (new assignment_service())->withdraw_assignment(
                (int) $userid,
                null,
                get_string('privacy:erasure', 'local_mohhierarchy'),
            );
        }
        $DB->set_field('local_mohh_assign', 'assignedby', null);
        $DB->set_field('local_mohh_assignlog', 'changedby', null);
        $DB->set_field('local_mohh_synclog', 'triggeredby', null);
        self::anonymise_snapshots(null);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (self::has_system_context($contextlist)) {
            self::erase_user((int) $contextlist->get_user()->id);
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::erase_user((int) $userid);
        }
    }

    /**
     * Apply the documented retention/anonymisation policy to one user.
     *
     * @param int $userid User being erased.
     * @return void
     */
    protected static function erase_user(int $userid): void {
        global $DB;

        (new assignment_service())->withdraw_assignment(
            $userid,
            null,
            get_string('privacy:erasure', 'local_mohhierarchy'),
        );
        $DB->set_field('local_mohh_assign', 'assignedby', null, ['assignedby' => $userid]);
        $DB->set_field('local_mohh_assignlog', 'changedby', null, ['changedby' => $userid]);
        $DB->set_field('local_mohh_synclog', 'triggeredby', null, ['triggeredby' => $userid]);
        self::anonymise_snapshots($userid);
    }

    /**
     * Remove actor ids embedded in retained JSON assignment snapshots.
     *
     * @param int|null $userid One actor, or null for all actor references.
     * @return void
     */
    protected static function anonymise_snapshots(?int $userid): void {
        global $DB;

        $recordset = $DB->get_recordset('local_mohh_assignlog', null, '', 'id, olddata, newdata');
        foreach ($recordset as $record) {
            $changed = false;
            foreach (['olddata', 'newdata'] as $field) {
                if (!$record->{$field}) {
                    continue;
                }
                $snapshot = json_decode($record->{$field});
                if (
                    is_object($snapshot)
                    && property_exists($snapshot, 'assignedby')
                    && ($userid === null || (int) $snapshot->assignedby === $userid)
                ) {
                    $snapshot->assignedby = null;
                    $record->{$field} = json_encode($snapshot);
                    $changed = true;
                }
            }
            if ($changed) {
                $DB->update_record('local_mohh_assignlog', $record);
            }
        }
        $recordset->close();
    }

    /**
     * Whether the system context was approved.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return bool
     */
    protected static function has_system_context(approved_contextlist $contextlist): bool {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                return true;
            }
        }

        return false;
    }
}
