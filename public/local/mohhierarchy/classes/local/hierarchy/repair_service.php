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

namespace local_mohhierarchy\local\hierarchy;

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\scope_level;

/**
 * Detects and safely repairs drift between assignments and their profile-field mirror.
 *
 * local_mohh_assign is canonical. Automatic actions may create a legacy assignment from the
 * profile field when no assignment has ever existed, restore an empty mirror, or re-derive zone
 * and district from the assignment's existing facility. A facility disagreement is never changed.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repair_service {
    /** @var string Profile-field datatype owned by the companion plugin. */
    public const DATATYPE = 'mohhierarchy';

    /** @var assignment_service Assignment write boundary. */
    protected assignment_service $assignments;

    /** @var facility_repository Facility lookup. */
    protected facility_repository $facilities;

    /**
     * Constructor.
     *
     * @param assignment_service|null $assignments Injectable assignment service.
     * @param facility_repository|null $facilities Injectable facility repository.
     */
    public function __construct(
        ?assignment_service $assignments = null,
        ?facility_repository $facilities = null,
    ) {
        $this->assignments = $assignments ?? new assignment_service();
        $this->facilities = $facilities ?? new facility_repository();
    }

    /**
     * Scan all relevant users, or one user for an event-driven verification.
     *
     * @param int|null $onlyuserid Restrict the scan to one non-deleted user.
     * @return array{fieldcount: int, issues: array, userschecked: int, repairable: int, conflicts: int}
     */
    public function scan(?int $onlyuserid = null): array {
        global $DB;

        $fields = $DB->get_records('user_info_field', ['datatype' => self::DATATYPE], 'id ASC', 'id, name');
        $issues = [];
        if (count($fields) > 1) {
            $issues[] = $this->issue(
                null,
                'multiple_fields',
                get_string('repairissue:multiple_fields', 'local_mohhierarchy', count($fields)),
            );
        } else if (!$fields) {
            $issues[] = $this->issue(null, 'missing_field', get_string('repairissue:missing_field', 'local_mohhierarchy'));
        }

        $params = [];
        $userfilter = '';
        if ($onlyuserid !== null) {
            $userfilter = 'AND u.id = :onlyuserid';
            $params['onlyuserid'] = $onlyuserid;
        }
        $sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {user} u
             LEFT JOIN {local_mohh_assign} a ON a.userid = u.id
             LEFT JOIN {user_info_data} uid ON uid.userid = u.id
             LEFT JOIN {user_info_field} uif ON uif.id = uid.fieldid
                       AND uif.datatype = :datatype
                 WHERE u.deleted = 0
                       AND (a.id IS NOT NULL OR uif.id IS NOT NULL)
                       {$userfilter}
              ORDER BY u.id";
        $params['datatype'] = self::DATATYPE;
        $users = $DB->get_records_sql($sql, $params);
        $field = count($fields) === 1 ? reset($fields) : null;

        foreach ($users as $user) {
            $issues = array_merge($issues, $this->scan_user($user, $field));
        }

        $repairable = count(array_filter($issues, static fn(array $issue): bool => $issue['repairable']));

        return [
            'fieldcount' => count($fields),
            'issues' => $issues,
            'userschecked' => count($users),
            'repairable' => $repairable,
            'conflicts' => count($issues) - $repairable,
        ];
    }

    /**
     * Apply every currently safe action, or report what would be applied.
     *
     * @param bool $dryrun True to make no writes.
     * @param int|null $onlyuserid Restrict to one user.
     * @param bool $allowlegacy Whether profile-only users may gain a no-scope repair assignment.
     * @return array Scan result with planned/applied counters.
     */
    public function repair(bool $dryrun = true, ?int $onlyuserid = null, bool $allowlegacy = true): array {
        $report = $this->scan($onlyuserid);
        $actions = [];
        foreach ($report['issues'] as $issue) {
            if ($issue['repairable'] && ($allowlegacy || $issue['action'] !== 'create_assignment')) {
                $actions[$issue['userid'] . ':' . $issue['action']] = $issue;
            }
        }

        $applied = 0;
        if (!$dryrun && $report['fieldcount'] === 1) {
            foreach ($actions as $issue) {
                // Re-scan immediately before each action so an administrator report cannot apply
                // stale conclusions after another request has changed the user.
                $current = $this->scan((int) $issue['userid']);
                $stillvalid = array_filter(
                    $current['issues'],
                    static fn(array $candidate): bool =>
                        $candidate['repairable']
                        && ($allowlegacy || $candidate['action'] !== 'create_assignment')
                        && $candidate['action'] === $issue['action'],
                );
                if (!$stillvalid) {
                    continue;
                }
                $this->apply($issue);
                $applied++;
            }
        }
        $report['dryrun'] = $dryrun;
        $report['planned'] = count($actions);
        $report['applied'] = $applied;

        return $report;
    }

    /**
     * Verification used by user-created and user-updated observers.
     *
     * @param int $userid The event target.
     * @return array Repair report.
     */
    public function repair_user(int $userid): array {
        // Generic lifecycle events have no evidence that a submitted legacy profile value passed
        // hierarchy permission checks. They may reconcile an existing canonical assignment, but
        // only the capability-protected administrator report may migrate a profile-only user.
        return $this->repair(false, $userid, false);
    }

    /**
     * Inspect one user.
     *
     * @param \stdClass $user User identity.
     * @param \stdClass|null $field The unique field instance, if configured.
     * @return array[]
     */
    protected function scan_user(\stdClass $user, ?\stdClass $field): array {
        global $DB;

        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $user->id]) ?: null;
        $data = $field === null
            ? null
            : $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field->id]);
        $rawvalue = trim((string) ($data->data ?? ''));
        $profilefacilityid = ctype_digit($rawvalue) ? (int) $rawvalue : 0;
        $profilefacility = $profilefacilityid > 0
            ? $this->facilities->get_with_ancestors($profilefacilityid)
            : null;
        $issues = [];

        if ($assignment === null) {
            if (
                $field !== null
                && $profilefacility !== null
                && $this->path_is_active($profilefacility)
            ) {
                $issues[] = $this->issue(
                    $user,
                    'custom_only',
                    get_string('repairissue:custom_only', 'local_mohhierarchy'),
                    true,
                    'create_assignment',
                    $profilefacilityid,
                );
            } else if ($rawvalue !== '') {
                $issues[] = $this->issue(
                    $user,
                    'invalid_custom',
                    get_string('repairissue:invalid_custom', 'local_mohhierarchy'),
                );
            }

            return $issues;
        }

        $storedzone = $DB->get_record('local_mohh_zone', ['id' => $assignment->zoneid], 'id, active');
        $storeddistrict = $DB->get_record(
            'local_mohh_district',
            ['id' => $assignment->districtid],
            'id, zoneid, active',
        );
        $storedfacility = $DB->get_record(
            'local_mohh_facility',
            ['id' => $assignment->facilityid],
            'id, districtid, active',
        );
        if (!$storedzone || !$storeddistrict || !$storedfacility) {
            $issues[] = $this->issue(
                $user,
                'missing_reference',
                get_string('repairissue:missing_reference', 'local_mohhierarchy'),
            );
        } else {
            if (
                (int) $storedzone->active !== 1
                || (int) $storeddistrict->active !== 1
                || (int) $storedfacility->active !== 1
            ) {
                $issues[] = $this->issue(
                    $user,
                    'inactive_reference',
                    get_string('repairissue:inactive_reference', 'local_mohhierarchy'),
                );
            }
            $activepath = (int) $assignment->active === 1
                && (int) $storedzone->active === 1
                && (int) $storeddistrict->active === 1
                && (int) $storedfacility->active === 1;
            if ((int) $storeddistrict->zoneid !== (int) $assignment->zoneid) {
                $issues[] = $this->issue(
                    $user,
                    'zone_district_mismatch',
                    get_string('repairissue:zone_district_mismatch', 'local_mohhierarchy'),
                    $activepath,
                    $activepath ? 'repair_ancestry' : '',
                );
            }
            if ((int) $storedfacility->districtid !== (int) $assignment->districtid) {
                $issues[] = $this->issue(
                    $user,
                    'district_facility_mismatch',
                    get_string('repairissue:district_facility_mismatch', 'local_mohhierarchy'),
                    $activepath,
                    $activepath ? 'repair_ancestry' : '',
                );
            }
        }

        if ((int) $assignment->active !== 1) {
            if ($rawvalue !== '') {
                $issues[] = $this->issue(
                    $user,
                    'withdrawn_with_custom',
                    get_string('repairissue:withdrawn_with_custom', 'local_mohhierarchy'),
                );
            }

            return $issues;
        }

        if ($field !== null) {
            if ($rawvalue === '') {
                $path = $this->facilities->get_with_ancestors((int) $assignment->facilityid);
                $safe = $path !== null && $this->path_is_active($path);
                $issues[] = $this->issue(
                    $user,
                    'assignment_profile_empty',
                    get_string('repairissue:assignment_profile_empty', 'local_mohhierarchy'),
                    $safe,
                    $safe ? 'sync_profile' : '',
                    (int) $assignment->facilityid,
                );
            } else if ($profilefacility === null || !$this->path_is_active($profilefacility)) {
                $issues[] = $this->issue(
                    $user,
                    'invalid_custom',
                    get_string('repairissue:invalid_custom', 'local_mohhierarchy'),
                );
            } else if ($profilefacilityid !== (int) $assignment->facilityid) {
                $issues[] = $this->issue(
                    $user,
                    'facility_conflict',
                    get_string('repairissue:facility_conflict', 'local_mohhierarchy', (object) [
                        'assignment' => $assignment->facilityid,
                        'profile' => $rawvalue,
                    ]),
                );
            }
        }

        return $issues;
    }

    /**
     * Apply one previously revalidated action.
     *
     * @param array $issue Issue/action data.
     * @return void
     */
    protected function apply(array $issue): void {
        global $DB;

        $userid = (int) $issue['userid'];
        if ($issue['action'] === 'create_assignment') {
            $this->assignments->assign_user(
                $userid,
                (int) $issue['facilityid'],
                scope_level::NONE,
                null,
                assign_source::REPAIR,
                get_string('repairreason:custom_only', 'local_mohhierarchy'),
            );
            return;
        }
        if ($issue['action'] === 'repair_ancestry') {
            $this->assignments->repair_ancestry(
                $userid,
                get_string('repairreason:ancestry', 'local_mohhierarchy'),
            );
            return;
        }
        if ($issue['action'] === 'sync_profile') {
            $fieldid = (int) $DB->get_field('user_info_field', 'id', ['datatype' => self::DATATYPE], MUST_EXIST);
            $transaction = $DB->start_delegated_transaction();
            $record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);
            if ($record) {
                $record->data = (string) $issue['facilityid'];
                $record->dataformat = 0;
                $DB->update_record('user_info_data', $record);
            } else {
                $DB->insert_record('user_info_data', (object) [
                    'userid' => $userid,
                    'fieldid' => $fieldid,
                    'data' => (string) $issue['facilityid'],
                    'dataformat' => 0,
                ]);
            }
            $this->assignments->record_mirror_repair(
                $userid,
                get_string('repairreason:profile_empty', 'local_mohhierarchy'),
            );
            $transaction->allow_commit();
        }
    }

    /**
     * Build a presentation-neutral issue.
     *
     * @param \stdClass|null $user Affected user, null for configuration issues.
     * @param string $code Stable issue code.
     * @param string $detail Localised explanation.
     * @param bool $repairable Whether the action is safe.
     * @param string $action Internal action code.
     * @param int $facilityid Facility used by an action.
     * @return array
     */
    protected function issue(
        ?\stdClass $user,
        string $code,
        string $detail,
        bool $repairable = false,
        string $action = '',
        int $facilityid = 0,
    ): array {
        return [
            'userid' => $user === null ? 0 : (int) $user->id,
            'fullname' => $user === null ? get_string('repairconfiguration', 'local_mohhierarchy') : fullname($user),
            'username' => $user->username ?? '',
            'code' => $code,
            'detail' => $detail,
            'repairable' => $repairable,
            'action' => $action,
            'facilityid' => $facilityid,
        ];
    }

    /**
     * Whether all three remote-owned reference rows are active.
     *
     * @param \stdClass $path Facility with ancestors.
     * @return bool
     */
    protected function path_is_active(\stdClass $path): bool {
        return (int) $path->facilityactive === 1
            && (int) $path->districtactive === 1
            && (int) $path->zoneactive === 1;
    }
}
