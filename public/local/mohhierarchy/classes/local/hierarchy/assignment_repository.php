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
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\scope_level;

/**
 * Database access for the canonical user hierarchy assignments.
 *
 * This is the only class that writes the assignment table. The zone and district of an assignment
 * are always derived from the facility, so a stored triple cannot contradict the reference tables.
 * A user with no row here is a valid, fully working Moodle user.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_assign';

    /** @var facility_repository Resolves a facility to its district and zone. */
    protected facility_repository $facilities;

    /** @var assignment_log_repository Records history. */
    protected assignment_log_repository $log;

    /**
     * Constructor.
     *
     * @param facility_repository|null $facilities Injectable for tests.
     * @param assignment_log_repository|null $log Injectable for tests.
     */
    public function __construct(?facility_repository $facilities = null, ?assignment_log_repository $log = null) {
        $this->facilities = $facilities ?? new facility_repository();
        $this->log = $log ?? new assignment_log_repository();
    }

    /**
     * The assignment of one user, active or not.
     *
     * @param int $userid The user.
     * @return \stdClass|null Null when the user has no hierarchy assignment.
     */
    public function get_for_user(int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * The active assignment of one user.
     *
     * @param int $userid The user.
     * @return \stdClass|null Null when the user has no active hierarchy assignment.
     */
    public function get_active_for_user(int $userid): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['userid' => $userid, 'active' => 1]) ?: null;
    }

    /**
     * One user's assignment together with the names of its zone, district and facility.
     *
     * The joins use the ids stored on the assignment rather than re-deriving them from the
     * facility, so a row that has drifted from the reference tables shows the drift instead of
     * hiding it.
     *
     * @param int $userid The user.
     * @return \stdClass|null Null when the user has no assignment, or when the stored triple no
     *      longer resolves.
     */
    public function get_with_names(int $userid): ?\stdClass {
        global $DB;

        $sql = "SELECT a.*,
                       z.name AS zonename, z.active AS zoneactive,
                       d.name AS districtname, d.active AS districtactive,
                       f.name AS facilityname, f.code AS facilitycode, f.active AS facilityactive
                  FROM {local_mohh_assign} a
                  JOIN {local_mohh_zone} z ON z.id = a.zoneid
                  JOIN {local_mohh_district} d ON d.id = a.districtid
                  JOIN {local_mohh_facility} f ON f.id = a.facilityid
                 WHERE a.userid = :userid";

        return $DB->get_record_sql($sql, ['userid' => $userid]) ?: null;
    }

    /**
     * The scope level a user manages with, defaulting to none.
     *
     * @param int $userid The user.
     * @return scope_level
     */
    public function get_scope_level(int $userid): scope_level {
        $assignment = $this->get_active_for_user($userid);
        if ($assignment === null) {
            return scope_level::NONE;
        }

        return scope_level::from_value($assignment->scopelevel);
    }

    /**
     * Create or update the assignment of one user.
     *
     * @param int $userid The user being assigned.
     * @param int $facilityid The facility, from which the district and zone are derived.
     * @param scope_level $scope The management scope delegated to the user.
     * @param assign_source $source Where the change came from.
     * @param int|null $assignedby The acting user, null for migration and automated repair.
     * @param string|null $reason Free-text note for the history.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return \stdClass The stored assignment.
     */
    public function save(
        int $userid,
        int $facilityid,
        scope_level $scope,
        assign_source $source,
        ?int $assignedby = null,
        ?string $reason = null,
        ?int $now = null,
    ): \stdClass {
        return $this->write($userid, $facilityid, $scope, $source, $assignedby, $reason, null, $now);
    }

    /**
     * Re-derive an assignment from its facility, recording the change as a repair.
     *
     * Used by reconciliation when the profile field and this table have drifted apart.
     *
     * @param int $userid The user being repaired.
     * @param int $facilityid The facility to trust.
     * @param scope_level $scope The management scope to keep.
     * @param string|null $reason Why the repair was needed.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return \stdClass The stored assignment.
     */
    public function repair(
        int $userid,
        int $facilityid,
        scope_level $scope,
        ?string $reason = null,
        ?int $now = null,
    ): \stdClass {
        return $this->write(
            $userid,
            $facilityid,
            $scope,
            assign_source::REPAIR,
            null,
            $reason,
            assign_action::REPAIRED,
            $now,
        );
    }

    /**
     * Record a repair which changed the profile mirror but not the canonical assignment.
     *
     * @param int $userid The user whose mirror was repaired.
     * @param string|null $reason Why the repair was needed.
     * @param int|null $now Timestamp to record.
     * @return bool False when there is no assignment to describe.
     */
    public function record_repair(int $userid, ?string $reason = null, ?int $now = null): bool {
        $assignment = $this->get_for_user($userid);
        if ($assignment === null) {
            return false;
        }
        $this->log->add(
            $userid,
            (int) $assignment->id,
            assign_action::REPAIRED,
            $assignment,
            $assignment,
            null,
            $reason,
            $now,
        );

        return true;
    }

    /**
     * Withdraw a user's assignment, keeping the row and the history.
     *
     * @param int $userid The user.
     * @param int|null $changedby The acting user, null for automated changes.
     * @param string|null $reason Free-text note for the history.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return bool False when the user had no assignment to withdraw.
     */
    public function deactivate(int $userid, ?int $changedby = null, ?string $reason = null, ?int $now = null): bool {
        global $DB;

        $existing = $this->get_for_user($userid);
        if ($existing === null) {
            return false;
        }
        $now = $now ?? time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, (object) [
                'id' => $existing->id,
                'active' => 0,
                'timemodified' => $now,
            ]);
            $this->log->add(
                $userid,
                (int) $existing->id,
                assign_action::DEACTIVATED,
                $existing,
                null,
                $changedby,
                $reason,
                $now,
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return true;
    }

    /**
     * Whether a stored assignment still agrees with the reference tables.
     *
     * @param \stdClass $assignment An assignment row.
     * @return bool
     */
    public function is_consistent(\stdClass $assignment): bool {
        $ancestors = $this->facilities->get_with_ancestors((int) $assignment->facilityid);
        if ($ancestors === null) {
            return false;
        }

        return (int) $assignment->districtid === (int) $ancestors->districtid
            && (int) $assignment->zoneid === (int) $ancestors->zoneid;
    }

    /**
     * Search Moodle users by username, first name, surname or email, with their assignment.
     *
     * With no search term only users who already hold an assignment are returned, so opening the
     * administration page does not list every account on the site.
     *
     * @param string $term Free text, matched case insensitively anywhere in the field.
     * @param int $limitfrom Offset for paging.
     * @param int $limitnum Page size, 0 for all rows.
     * @param hierarchy_scope|null $scope Restrict assigned users to this scope; null is unrestricted.
     * @return \stdClass[] Keyed by user id, ordered by name.
     */
    public function search_users(
        string $term,
        int $limitfrom = 0,
        int $limitnum = 0,
        ?hierarchy_scope $scope = null,
    ): array {
        global $DB;

        [$where, $params] = $this->user_search_clause($term, $scope);
        $sql = "SELECT u.id AS userid, u.username, u.firstname, u.lastname, u.email,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       a.id AS assignmentid, a.scopelevel, a.active AS assignmentactive,
                       f.id AS facilityid, f.name AS facilityname, f.active AS facilityactive,
                       d.id AS districtid, d.name AS districtname,
                       z.id AS zoneid, z.name AS zonename
                  FROM {user} u
             LEFT JOIN {local_mohh_assign} a ON a.userid = u.id
             LEFT JOIN {local_mohh_facility} f ON f.id = a.facilityid
             LEFT JOIN {local_mohh_district} d ON d.id = a.districtid
             LEFT JOIN {local_mohh_zone} z ON z.id = a.zoneid
                 WHERE {$where}
              ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC";

        return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * How many users a search matches, for the paging bar.
     *
     * @param string $term The same term passed to search_users().
     * @param hierarchy_scope|null $scope Restrict assigned users to this scope; null is unrestricted.
     * @return int
     */
    public function count_search_users(string $term, ?hierarchy_scope $scope = null): int {
        global $DB;

        [$where, $params] = $this->user_search_clause($term, $scope);
        $sql = "SELECT COUNT(u.id)
                  FROM {user} u
             LEFT JOIN {local_mohh_assign} a ON a.userid = u.id
                 WHERE {$where}";

        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Build the user search condition shared by search_users() and count_search_users().
     *
     * @param string $term Free text.
     * @param hierarchy_scope|null $scope Restrict assigned users to this scope; null is unrestricted.
     * @return array{0: string, 1: array} SQL fragment and its parameters.
     */
    protected function user_search_clause(string $term, ?hierarchy_scope $scope = null): array {
        global $DB;

        $where = ['u.deleted = 0', 'u.username <> :guest'];
        $params = ['guest' => 'guest'];

        $term = trim($term);
        if ($term === '') {
            // Nothing asked for: show the people who already have a placement.
            $where[] = 'a.id IS NOT NULL';
        } else {
            $pattern = '%' . $DB->sql_like_escape($term) . '%';
            $columns = [
                'u.username' => 'username',
                'u.firstname' => 'firstname',
                'u.lastname' => 'lastname',
                'u.email' => 'email',
            ];
            $conditions = [];
            foreach ($columns as $column => $placeholder) {
                $conditions[] = $DB->sql_like($column, ":{$placeholder}", false, false);
                $params[$placeholder] = $pattern;
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        if ($scope !== null) {
            if (!$scope->grants_management()) {
                $where[] = '1 = 0';
            } else {
                // A searched unassigned user may be selected for a new in-scope placement. Users
                // who already have an assignment must sit inside the actor's current scope.
                $scopeconditions = ['a.id IS NULL'];
                switch ($scope->level) {
                    case scope_level::ZONE:
                        $scopeconditions[] = 'a.zoneid = :scopezoneid';
                        $params['scopezoneid'] = $scope->zoneid;
                        break;
                    case scope_level::DISTRICT:
                        $scopeconditions[] = 'a.districtid = :scopedistrictid';
                        $params['scopedistrictid'] = $scope->districtid;
                        break;
                    case scope_level::FACILITY:
                        $scopeconditions[] = 'a.facilityid = :scopefacilityid';
                        $params['scopefacilityid'] = $scope->facilityid;
                        break;
                    case scope_level::NONE:
                        // Already handled by grants_management().
                        break;
                }
                $where[] = '(' . implode(' OR ', $scopeconditions) . ')';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Assignments whose triple no longer agrees with the reference tables.
     *
     * @return \stdClass[] Keyed by assignment id.
     */
    public function get_inconsistent(): array {
        global $DB;

        $sql = "SELECT a.*
                  FROM {local_mohh_assign} a
             LEFT JOIN {local_mohh_facility} f ON f.id = a.facilityid
             LEFT JOIN {local_mohh_district} d ON d.id = f.districtid
                 WHERE f.id IS NULL
                       OR d.id IS NULL
                       OR a.districtid <> f.districtid
                       OR a.zoneid <> d.zoneid";

        return $DB->get_records_sql($sql);
    }

    /**
     * How many users hold an assignment.
     *
     * @param bool $activeonly Whether to exclude withdrawn assignments.
     * @return int
     */
    public function count(bool $activeonly = true): int {
        global $DB;

        return $DB->count_records(self::TABLE, $activeonly ? ['active' => 1] : []);
    }

    /**
     * Write an assignment and its history entry in one transaction.
     *
     * @param int $userid The user being assigned.
     * @param int $facilityid The facility.
     * @param scope_level $scope The management scope.
     * @param assign_source $source Where the change came from.
     * @param int|null $assignedby The acting user.
     * @param string|null $reason Free-text note for the history.
     * @param assign_action|null $action Force a history action, otherwise created or updated.
     * @param int|null $now Timestamp to record.
     * @return \stdClass The stored assignment.
     */
    protected function write(
        int $userid,
        int $facilityid,
        scope_level $scope,
        assign_source $source,
        ?int $assignedby,
        ?string $reason,
        ?assign_action $action,
        ?int $now,
    ): \stdClass {
        global $DB;

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('error:unknownuser', 'local_mohhierarchy', '', $userid);
        }
        $ancestors = $this->facilities->get_with_ancestors($facilityid);
        if ($ancestors === null) {
            throw new \moodle_exception('error:unknownfacility', 'local_mohhierarchy', '', $facilityid);
        }

        $now = $now ?? time();
        $existing = $this->get_for_user($userid);
        $record = (object) [
            'userid' => $userid,
            'zoneid' => (int) $ancestors->zoneid,
            'districtid' => (int) $ancestors->districtid,
            'facilityid' => (int) $ancestors->facilityid,
            'scopelevel' => $scope->value,
            'assignedby' => $assignedby,
            'assignsource' => $source->value,
            'active' => 1,
            'timecreated' => $existing === null ? $now : (int) $existing->timecreated,
            'timemodified' => $now,
        ];

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($existing === null) {
                $record->id = (int) $DB->insert_record(self::TABLE, $record);
            } else {
                $record->id = (int) $existing->id;
                $DB->update_record(self::TABLE, $record);
            }
            $this->log->add(
                $userid,
                $record->id,
                $action ?? ($existing === null ? assign_action::CREATED : assign_action::UPDATED),
                $existing,
                $record,
                $assignedby,
                $reason,
                $now,
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $record;
    }
}
