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

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\scope_level;

/**
 * The single entry point for changing a user's hierarchy assignment.
 *
 * Forms, pages, tasks, CLI scripts and external functions call this service. None of them touch
 * local_mohh_assign, and none of them decide what a consistent assignment looks like: the zone and
 * district are always derived here from the facility, so a submitted zone or district id can never
 * influence what is stored.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_service {
    /** @var string Lock namespace shared by hierarchy writes. */
    public const LOCK_TYPE = 'local_mohhierarchy';

    /** @var string Lock resource prefix for one user's assignment. */
    public const LOCK_PREFIX = 'assignment:';

    /** @var assignment_repository Assignment storage. */
    protected assignment_repository $assignments;

    /** @var facility_repository Facility lookups. */
    protected facility_repository $facilities;

    /** @var \core\lock\lock_factory Serialises concurrent changes to one assignment. */
    protected \core\lock\lock_factory $lockfactory;

    /**
     * Constructor.
     *
     * @param assignment_repository|null $assignments Injectable for tests.
     * @param facility_repository|null $facilities Injectable for tests.
     * @param \core\lock\lock_factory|null $lockfactory Injectable for tests.
     */
    public function __construct(
        ?assignment_repository $assignments = null,
        ?facility_repository $facilities = null,
        ?\core\lock\lock_factory $lockfactory = null,
    ) {
        $this->assignments = $assignments ?? new assignment_repository();
        $this->facilities = $facilities ?? new facility_repository();
        $this->lockfactory = $lockfactory ?? \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
    }

    /**
     * Create or update one user's hierarchy assignment.
     *
     * Idempotent: calling it twice with the same arguments leaves the assignment untouched the
     * second time, adds no history entry and does not move timemodified.
     *
     * @param int $userid The user being assigned.
     * @param int $facilityid The facility. The district and zone are derived from it.
     * @param scope_level $scopelevel The management scope to delegate.
     * @param int|null $actorid The user making the change, null for migration and automated repair.
     * @param assign_source $source Where the change came from.
     * @param string|null $reason Free-text note recorded in the history.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return \stdClass The stored assignment.
     */
    public function assign_user(
        int $userid,
        int $facilityid,
        scope_level $scopelevel,
        ?int $actorid = null,
        assign_source $source = assign_source::ADMIN,
        ?string $reason = null,
        ?int $now = null,
    ): \stdClass {
        // Assignments wait for no synchronisation: a facility must not be deactivated or moved
        // between the active-state check and the assignment write.
        $synclock = $this->lockfactory->get_lock(sync_service::LOCK_RESOURCE, 0);
        if (!$synclock) {
            throw new \moodle_exception('error:hierarchywritebusy', 'local_mohhierarchy');
        }
        $lock = $this->lockfactory->get_lock(self::LOCK_PREFIX . $userid, 0);
        if (!$lock) {
            $synclock->release();
            throw new \moodle_exception('error:assignmentlocked', 'local_mohhierarchy');
        }

        try {
            return $this->assign_user_locked(
                $userid,
                $facilityid,
                $scopelevel,
                $actorid,
                $source,
                $reason,
                $now,
            );
        } finally {
            $lock->release();
            $synclock->release();
        }
    }

    /**
     * Perform an assignment change while the per-user lock is held.
     *
     * @param int $userid The user being assigned.
     * @param int $facilityid The facility.
     * @param scope_level $scopelevel The management scope to delegate.
     * @param int|null $actorid The actor.
     * @param assign_source $source Where the change came from.
     * @param string|null $reason Audit reason.
     * @param int|null $now Timestamp.
     * @return \stdClass
     */
    protected function assign_user_locked(
        int $userid,
        int $facilityid,
        scope_level $scopelevel,
        ?int $actorid,
        assign_source $source,
        ?string $reason,
        ?int $now,
    ): \stdClass {
        global $DB;

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('error:unknownuser', 'local_mohhierarchy', '', $userid);
        }

        // The facility is the only hierarchy input that is trusted, and even it is looked up.
        $facility = $this->facilities->get_with_ancestors($facilityid);
        if ($facility === null) {
            throw new \moodle_exception('error:unknownfacility', 'local_mohhierarchy', '', $facilityid);
        }

        $existing = $this->assignments->get_for_user($userid);
        $movingfacility = $existing === null || (int) $existing->facilityid !== $facilityid;
        if ($movingfacility && (int) $facility->facilityactive !== 1) {
            // A facility that is no longer published cannot take new people. An existing
            // assignment may still be edited, so that a scope level can be corrected for someone
            // already sitting at a facility that has since been withdrawn upstream.
            throw new \moodle_exception('error:inactivefacility', 'local_mohhierarchy', '', (object) [
                'facility' => s($facility->facilityname),
                'id' => $facilityid,
            ]);
        }

        if ($existing !== null && $this->is_unchanged($existing, $facility, $scopelevel)) {
            return $existing;
        }

        $assignment = $this->assignments->save(
            $userid,
            $facilityid,
            $scopelevel,
            $source,
            $actorid,
            $reason,
            $now,
        );
        $this->purge_scope($userid);

        return $assignment;
    }

    /**
     * Withdraw a user's assignment, keeping the row and the history.
     *
     * @param int $userid The user.
     * @param int|null $actorid The user making the change.
     * @param string|null $reason Free-text note recorded in the history.
     * @param int|null $now Timestamp to record, defaults to now.
     * @return bool False when the user had no assignment.
     */
    public function withdraw_assignment(
        int $userid,
        ?int $actorid = null,
        ?string $reason = null,
        ?int $now = null,
    ): bool {
        $synclock = $this->lockfactory->get_lock(sync_service::LOCK_RESOURCE, 0);
        if (!$synclock) {
            throw new \moodle_exception('error:hierarchywritebusy', 'local_mohhierarchy');
        }
        $lock = $this->lockfactory->get_lock(self::LOCK_PREFIX . $userid, 0);
        if (!$lock) {
            $synclock->release();
            throw new \moodle_exception('error:assignmentlocked', 'local_mohhierarchy');
        }

        try {
            $existing = $this->assignments->get_active_for_user($userid);
            if ($existing === null) {
                // Already withdrawn, or never assigned. Nothing to record.
                return false;
            }
            $withdrawn = $this->assignments->deactivate($userid, $actorid, $reason, $now);
            $this->purge_scope($userid);

            return $withdrawn;
        } finally {
            $lock->release();
            $synclock->release();
        }
    }

    /**
     * Re-derive the stored zone and district from the canonical facility.
     *
     * @param int $userid The user to repair.
     * @param string|null $reason Audit reason.
     * @return \stdClass The repaired assignment.
     */
    public function repair_ancestry(int $userid, ?string $reason = null): \stdClass {
        return $this->with_assignment_lock($userid, function () use ($userid, $reason): \stdClass {
            $assignment = $this->assignments->get_for_user($userid);
            if ($assignment === null) {
                throw new \moodle_exception('error:unknownassignment', 'local_mohhierarchy', '', $userid);
            }
            $facility = $this->facilities->get_with_ancestors((int) $assignment->facilityid);
            if (
                $facility === null
                || (int) $facility->facilityactive !== 1
                || (int) $facility->districtactive !== 1
                || (int) $facility->zoneactive !== 1
            ) {
                throw new \moodle_exception('error:repairinactivepath', 'local_mohhierarchy');
            }
            $repaired = $this->assignments->repair(
                $userid,
                (int) $assignment->facilityid,
                scope_level::from_value($assignment->scopelevel),
                $reason,
            );
            $this->purge_scope($userid);

            return $repaired;
        });
    }

    /**
     * Add audit history for a profile-mirror repair without modifying the assignment.
     *
     * @param int $userid The repaired user.
     * @param string|null $reason Audit reason.
     * @return bool Whether an assignment existed.
     */
    public function record_mirror_repair(int $userid, ?string $reason = null): bool {
        return $this->with_assignment_lock(
            $userid,
            fn(): bool => $this->assignments->record_repair($userid, $reason),
        );
    }

    /**
     * Run a callback while synchronisation and per-user assignment locks are held.
     *
     * @param int $userid The target user.
     * @param callable $callback Work to perform.
     * @return mixed Callback result.
     */
    protected function with_assignment_lock(int $userid, callable $callback): mixed {
        $synclock = $this->lockfactory->get_lock(sync_service::LOCK_RESOURCE, 0);
        if (!$synclock) {
            throw new \moodle_exception('error:hierarchywritebusy', 'local_mohhierarchy');
        }
        $lock = $this->lockfactory->get_lock(self::LOCK_PREFIX . $userid, 0);
        if (!$lock) {
            $synclock->release();
            throw new \moodle_exception('error:assignmentlocked', 'local_mohhierarchy');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
            $synclock->release();
        }
    }

    /**
     * One user's assignment with the names of its zone, district and facility.
     *
     * @param int $userid The user.
     * @return \stdClass|null Null when the user has no assignment.
     */
    public function get_assignment_with_names(int $userid): ?\stdClass {
        return $this->assignments->get_with_names($userid);
    }

    /**
     * One user's assignment as stored, without the hierarchy names.
     *
     * @param int $userid The user.
     * @return \stdClass|null
     */
    public function get_assignment(int $userid): ?\stdClass {
        return $this->assignments->get_for_user($userid);
    }

    /**
     * Whether an assignment already says exactly what a new one would say.
     *
     * Only the facts that define the assignment count: the derived triple, the scope level and
     * whether it is active. A different source or actor on an otherwise identical assignment is
     * not a change worth a history entry.
     *
     * @param \stdClass $existing The stored assignment.
     * @param \stdClass $facility The output of facility_repository::get_with_ancestors().
     * @param scope_level $scopelevel The requested scope level.
     * @return bool
     */
    protected function is_unchanged(\stdClass $existing, \stdClass $facility, scope_level $scopelevel): bool {
        return (int) $existing->facilityid === (int) $facility->facilityid
            && (int) $existing->districtid === (int) $facility->districtid
            && (int) $existing->zoneid === (int) $facility->zoneid
            && $existing->scopelevel === $scopelevel->value
            && (int) $existing->active === 1;
    }

    /**
     * Drop the cached scope of a user whose assignment just changed.
     *
     * @param int $userid The user.
     * @return void
     */
    protected function purge_scope(int $userid): void {
        permission_service::purge_scope_cache($userid);
    }
}
