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

namespace profilefield_mohhierarchy\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the hierarchy custom profile-field mirror.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Profile datatype. */
    protected const DATATYPE = 'mohhierarchy';

    #[\Override]
    public static function get_metadata(collection $collection): collection {
        return $collection->add_database_table('user_info_data', [
            'userid' => 'privacy:metadata:userid',
            'fieldid' => 'privacy:metadata:fieldid',
            'data' => 'privacy:metadata:data',
            'dataformat' => 'privacy:metadata:dataformat',
        ], 'privacy:metadata:table');
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $sql = "SELECT COUNT(uid.id)
                  FROM {user_info_data} uid
                  JOIN {user_info_field} uif ON uif.id = uid.fieldid
                 WHERE uid.userid = :userid AND uif.datatype = :datatype";
        if ($DB->count_records_sql($sql, ['userid' => $userid, 'datatype' => self::DATATYPE])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        $sql = "SELECT uid.userid
                  FROM {user_info_data} uid
                  JOIN {user_info_field} uif ON uif.id = uid.fieldid
                 WHERE uid.userid = :userid AND uif.datatype = :datatype";
        $userlist->add_from_sql('userid', $sql, [
            'userid' => $context->instanceid,
            'datatype' => self::DATATYPE,
        ]);
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== (int) $user->id) {
                continue;
            }
            $sql = "SELECT uid.id, uif.name, uif.description, uid.data
                      FROM {user_info_data} uid
                      JOIN {user_info_field} uif ON uif.id = uid.fieldid
                     WHERE uid.userid = :userid AND uif.datatype = :datatype";
            $records = array_values($DB->get_records_sql($sql, [
                'userid' => $user->id,
                'datatype' => self::DATATYPE,
            ]));
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'profilefield_mohhierarchy')],
                (object) ['fields' => $records],
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_user_data((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === (int) $user->id) {
                self::delete_user_data((int) $user->id);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ((int) $userid === (int) $context->instanceid) {
                self::delete_user_data((int) $userid);
            }
        }
    }

    /**
     * Delete only this datatype's mirror rows.
     *
     * @param int $userid User id.
     * @return void
     */
    protected static function delete_user_data(int $userid): void {
        global $DB;

        $DB->delete_records_select(
            'user_info_data',
            'userid = :userid AND fieldid IN (
                 SELECT id FROM {user_info_field} WHERE datatype = :datatype
             )',
            ['userid' => $userid, 'datatype' => self::DATATYPE],
        );
    }
}
