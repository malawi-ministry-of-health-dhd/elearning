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

use local_mohhierarchy\local\scope_level;

/**
 * Decides what part of the hierarchy an actor may act on.
 *
 * Two things must both be true for anything to be allowed: the actor holds the relevant capability,
 * and the target sits inside the actor's hierarchy scope. Role names are never consulted. Site
 * administrators satisfy both by definition and see the whole active hierarchy.
 *
 * Every method here is safe to call with values that came from a browser: the target is always
 * re-resolved from the database, so a tampered facility, a district from another zone or a facility
 * from another district simply fails the check.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permission_service {
    /** @var string Configure endpoints and run a synchronisation. */
    public const CAP_MANAGE_SYNC = 'local/mohhierarchy:managesync';

    /** @var string Browse the hierarchy selectors. */
    public const CAP_VIEW_HIERARCHY = 'local/mohhierarchy:viewhierarchy';

    /** @var string Change another user's assignment. */
    public const CAP_MANAGE_ASSIGNMENTS = 'local/mohhierarchy:manageassignments';

    /** @var string Create a user placed at a facility. */
    public const CAP_CREATE_USER = 'local/mohhierarchy:createuser';

    /** @var string See where users are assigned. */
    public const CAP_VIEW_ASSIGNMENTS = 'local/mohhierarchy:viewassignments';

    /** @var string View and apply consistency repairs. */
    public const CAP_REPAIR_CONSISTENCY = 'local/mohhierarchy:repairconsistency';

    /** @var string Cache area holding resolved scopes. */
    protected const CACHE_AREA = 'scope';

    /** @var assignment_repository Assignment lookups. */
    protected assignment_repository $assignments;

    /** @var zone_repository Zone lookups. */
    protected zone_repository $zones;

    /** @var district_repository District lookups. */
    protected district_repository $districts;

    /** @var facility_repository Facility lookups. */
    protected facility_repository $facilities;

    /**
     * Constructor.
     *
     * @param assignment_repository|null $assignments Injectable for tests.
     * @param zone_repository|null $zones Injectable for tests.
     * @param district_repository|null $districts Injectable for tests.
     * @param facility_repository|null $facilities Injectable for tests.
     */
    public function __construct(
        ?assignment_repository $assignments = null,
        ?zone_repository $zones = null,
        ?district_repository $districts = null,
        ?facility_repository $facilities = null,
    ) {
        $this->assignments = $assignments ?? new assignment_repository();
        $this->zones = $zones ?? new zone_repository();
        $this->districts = $districts ?? new district_repository();
        $this->facilities = $facilities ?? new facility_repository();
    }

    /**
     * Whether the actor may create a user placed at this facility.
     *
     * @param int $actorid The acting user.
     * @param int $facilityid The facility the new user would be placed at.
     * @return bool
     */
    public function can_create_user_in_facility(int $actorid, int $facilityid): bool {
        return $this->can_use_facility($actorid, $facilityid, self::CAP_CREATE_USER);
    }

    /**
     * Whether the actor may create or change this user's assignment.
     *
     * A delegated actor may transfer only a target with an active assignment inside the actor's
     * current scope. Unassigned and withdrawn users are not part of a delegated jurisdiction.
     * Site administrators may place an unassigned user, but still cannot edit themselves.
     *
     * @param int $actorid The acting user.
     * @param int $targetuserid The user whose assignment would change.
     * @return bool
     */
    public function can_manage_assignment(int $actorid, int $targetuserid): bool {
        global $DB;

        if ($targetuserid <= 0) {
            return false;
        }
        // Nobody may change their own scope: that would let a district manager promote themselves.
        if ($actorid === $targetuserid) {
            return false;
        }
        $target = $DB->get_record('user', ['id' => $targetuserid], 'id, username, deleted');
        if ($target === false || (int) $target->deleted === 1 || isguestuser($target)) {
            return false;
        }
        // Match core's administrator protection: only another site administrator may alter a site
        // administrator. This check happens before the unassigned-user shortcut below.
        if (is_siteadmin($targetuserid) && !$this->is_admin($actorid)) {
            return false;
        }
        if ($this->is_admin($actorid)) {
            return true;
        }
        if (!$this->has_capability($actorid, self::CAP_MANAGE_ASSIGNMENTS)) {
            return false;
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return false;
        }

        $target = $this->assignments->get_active_for_user($targetuserid);
        if ($target === null) {
            return false;
        }

        // A delegated manager may manage peers and less-privileged users, but never somebody
        // whose delegated authority is broader than their own. Location alone is insufficient:
        // for example, a district manager must not transfer or demote a zone manager merely
        // because that zone manager happens to be placed at a facility in the district.
        $targetscope = scope_level::tryFrom((string) $target->scopelevel);
        if ($targetscope === null || self::breadth($targetscope) > self::breadth($scope->level)) {
            return false;
        }

        return $this->facility_in_scope($scope, (int) $target->facilityid, false);
    }

    /**
     * Whether the actor may place a user at this facility.
     *
     * @param int $actorid The acting user.
     * @param int $facilityid The facility.
     * @return bool
     */
    public function can_assign_user_to_facility(int $actorid, int $facilityid): bool {
        return $this->can_use_facility($actorid, $facilityid, self::CAP_MANAGE_ASSIGNMENTS);
    }

    /**
     * The active zones the actor may choose from.
     *
     * @param int $actorid The acting user.
     * @return \stdClass[] Keyed by local zone id, sorted by name. Empty when nothing is allowed.
     */
    public function get_allowed_zones(int $actorid): array {
        if ($this->is_admin($actorid)) {
            return $this->zones->get_all();
        }
        if (!$this->has_capability($actorid, self::CAP_VIEW_HIERARCHY)) {
            return [];
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return [];
        }

        // Every scope level above none is anchored on exactly one zone: the actor's own.
        $zone = $this->zones->get_by_id($scope->zoneid);
        if ($zone === null || (int) $zone->active !== 1) {
            return [];
        }

        return [(int) $zone->id => $zone];
    }

    /**
     * The active districts of one zone that the actor may choose from.
     *
     * A zone the actor may not use yields an empty list, so a district from another zone can never
     * be reached by passing that zone's id.
     *
     * @param int $actorid The acting user.
     * @param int $zoneid The zone being browsed.
     * @return \stdClass[] Keyed by local district id, sorted by name.
     */
    public function get_allowed_districts(int $actorid, int $zoneid): array {
        $zone = $this->zones->get_by_id($zoneid);
        if ($zone === null || (int) $zone->active !== 1) {
            return [];
        }
        if ($this->is_admin($actorid)) {
            return $this->districts->get_by_zone($zoneid);
        }
        if (!$this->has_capability($actorid, self::CAP_VIEW_HIERARCHY)) {
            return [];
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management() || $scope->zoneid !== $zoneid) {
            return [];
        }

        if ($scope->level === scope_level::ZONE) {
            return $this->districts->get_by_zone($zoneid);
        }

        // District and facility scopes see only their own district, and only in their own zone.
        $district = $this->districts->get_by_id($scope->districtid);
        if ($district === null || (int) $district->active !== 1 || (int) $district->zoneid !== $zoneid) {
            return [];
        }

        return [(int) $district->id => $district];
    }

    /**
     * The active facilities of one district that the actor may choose from.
     *
     * A district the actor may not use yields an empty list, so a facility from another district
     * can never be reached by passing that district's id.
     *
     * @param int $actorid The acting user.
     * @param int $districtid The district being browsed.
     * @return \stdClass[] Keyed by local facility id, sorted by name.
     */
    public function get_allowed_facilities(int $actorid, int $districtid): array {
        $isadmin = $this->is_admin($actorid);
        if (!$isadmin && !$this->has_capability($actorid, self::CAP_VIEW_HIERARCHY)) {
            return [];
        }

        $district = $this->districts->get_by_id($districtid);
        if ($district === null || (int) $district->active !== 1) {
            return [];
        }
        $zone = $this->zones->get_by_id((int) $district->zoneid);
        if ($zone === null || (int) $zone->active !== 1) {
            return [];
        }
        if ($isadmin) {
            return $this->facilities->get_by_district($districtid);
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return [];
        }
        // The district must itself be inside the actor's scope before its facilities are listed.
        if ((int) $district->zoneid !== $scope->zoneid) {
            return [];
        }
        if ($scope->level !== scope_level::ZONE && $districtid !== $scope->districtid) {
            return [];
        }

        if ($scope->level === scope_level::FACILITY) {
            $facility = $this->facilities->get_by_id($scope->facilityid);
            if ($facility === null || (int) $facility->active !== 1 || (int) $facility->districtid !== $districtid) {
                return [];
            }
            return [(int) $facility->id => $facility];
        }

        return $this->facilities->get_by_district($districtid);
    }

    /**
     * Active facility paths the actor may browse and select from.
     *
     * This is the facility-first equivalent of the three cascading list methods. It applies the
     * same view capability and hierarchy scope in SQL, and returns the ancestors required to derive
     * District and Zone after a facility is selected.
     *
     * @param int $actorid The acting user.
     * @return \stdClass[] Keyed by local facility id.
     */
    public function get_allowed_facility_paths(int $actorid): array {
        if ($this->is_admin($actorid)) {
            return $this->facilities->get_assignment_options();
        }
        if (!$this->has_capability($actorid, self::CAP_VIEW_HIERARCHY)) {
            return [];
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return [];
        }

        return $this->facilities->get_assignment_options($scope);
    }

    /**
     * Whether the actor may delegate this scope level to somebody else.
     *
     * Nobody may hand out more authority than they hold: a district manager cannot create a zone
     * manager, and a facility manager cannot create a district or zone manager. Site administrators
     * may grant any level. Granting "none", which delegates nothing, is always available to an actor
     * who may manage assignments at all.
     *
     * @param int $actorid The acting user.
     * @param scope_level $scope The level the actor wants to grant.
     * @return bool
     */
    public function can_grant_scope(int $actorid, scope_level $scope): bool {
        if ($this->is_admin($actorid)) {
            return true;
        }
        if (!$this->has_capability($actorid, self::CAP_MANAGE_ASSIGNMENTS)) {
            return false;
        }
        $own = $this->get_scope($actorid);
        if (!$own->grants_management()) {
            return false;
        }

        return self::breadth($scope) <= self::breadth($own->level);
    }

    /**
     * Whether the actor may assign this scope to this particular user.
     *
     * Keeping the target and requested-scope checks together prevents controllers from checking
     * only one half of the decision. Site administrators retain Moodle's explicit administrator
     * override, except that nobody may edit their own hierarchy assignment.
     *
     * @param int $actorid The acting user.
     * @param int $targetuserid The user whose scope would change.
     * @param scope_level $scope The requested new scope.
     * @return bool
     */
    public function can_grant_scope_to_user(int $actorid, int $targetuserid, scope_level $scope): bool {
        return $this->can_manage_assignment($actorid, $targetuserid)
            && $this->can_grant_scope($actorid, $scope);
    }

    /**
     * The scope levels the actor may choose from, for building a form menu.
     *
     * @param int $actorid The acting user.
     * @return scope_level[] Narrowest first. Empty when the actor may grant nothing.
     */
    public function grantable_scopes(int $actorid): array {
        return array_values(array_filter(
            scope_level::cases(),
            fn(scope_level $scope): bool => $this->can_grant_scope($actorid, $scope),
        ));
    }

    /**
     * Active facilities that the actor may use on the assignment administration form.
     *
     * Each row includes its zone and district names so a facility can be presented unambiguously.
     * The repository applies the actor's scope in SQL instead of loading the whole hierarchy and
     * filtering it in PHP.
     *
     * @param int $actorid The acting user.
     * @return \stdClass[] Keyed by local facility id.
     */
    public function get_assignable_facilities(int $actorid): array {
        if ($this->is_admin($actorid)) {
            return $this->facilities->get_assignment_options();
        }
        if (!$this->has_capability($actorid, self::CAP_MANAGE_ASSIGNMENTS)) {
            return [];
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return [];
        }

        return $this->facilities->get_assignment_options($scope);
    }

    /**
     * How much of the hierarchy a scope level reaches, for comparing two levels.
     *
     * @param scope_level $level The level.
     * @return int Zero for none, rising with breadth.
     */
    protected static function breadth(scope_level $level): int {
        return match ($level) {
            scope_level::NONE => 0,
            scope_level::FACILITY => 1,
            scope_level::DISTRICT => 2,
            scope_level::ZONE => 3,
        };
    }

    /**
     * The actor's resolved scope, from cache when possible.
     *
     * @param int $actorid The acting user.
     * @return hierarchy_scope
     */
    public function get_scope(int $actorid): hierarchy_scope {
        if ($actorid <= 0) {
            return hierarchy_scope::none();
        }

        $cache = self::cache();
        $cached = $cache->get($actorid);
        if (is_array($cached)) {
            return hierarchy_scope::from_array($cached);
        }

        $assignment = $this->assignments->get_active_for_user($actorid);
        $scope = $assignment === null ? hierarchy_scope::none() : hierarchy_scope::from_assignment($assignment);
        $cache->set($actorid, $scope->to_array());

        return $scope;
    }

    /**
     * Forget one user's cached scope.
     *
     * @param int $userid The user whose assignment changed.
     * @return void
     */
    public static function purge_scope_cache(int $userid): void {
        self::cache()->delete($userid);
    }

    /**
     * Forget every cached scope.
     *
     * Called after a successful synchronisation, because a facility may have moved district or
     * been deactivated, which changes what a cached scope means.
     *
     * @return void
     */
    public static function purge_all_scope_caches(): void {
        self::cache()->purge();
    }

    /**
     * Shared check behind the two facility level decisions.
     *
     * @param int $actorid The acting user.
     * @param int $facilityid The facility.
     * @param string $capability The capability the action requires.
     * @return bool
     */
    protected function can_use_facility(int $actorid, int $facilityid, string $capability): bool {
        if ($facilityid <= 0) {
            return false;
        }
        if ($this->is_admin($actorid)) {
            // Even an administrator may only place people at an active facility.
            return $this->facilities->matches_scope($facilityid);
        }
        if (!$this->has_capability($actorid, $capability)) {
            return false;
        }
        $scope = $this->get_scope($actorid);
        if (!$scope->grants_management()) {
            return false;
        }

        return $this->facility_in_scope($scope, $facilityid, true);
    }

    /**
     * Whether a facility sits inside a scope, resolved in one query.
     *
     * @param hierarchy_scope $scope The actor's scope.
     * @param int $facilityid The facility.
     * @param bool $activeonly Whether the facility and its ancestors must be active.
     * @return bool
     */
    protected function facility_in_scope(hierarchy_scope $scope, int $facilityid, bool $activeonly): bool {
        return match ($scope->level) {
            scope_level::ZONE => $this->facilities->matches_scope($facilityid, null, $scope->zoneid, $activeonly),
            scope_level::DISTRICT => $this->facilities->matches_scope(
                $facilityid,
                $scope->districtid,
                $scope->zoneid,
                $activeonly,
            ),
            scope_level::FACILITY => $facilityid === $scope->facilityid
                && $this->facilities->matches_scope($facilityid, $scope->districtid, $scope->zoneid, $activeonly),
            scope_level::NONE => false,
        };
    }

    /**
     * Whether the actor is a site administrator.
     *
     * @param int $actorid The acting user.
     * @return bool
     */
    protected function is_admin(int $actorid): bool {
        return $actorid > 0 && is_siteadmin($actorid);
    }

    /**
     * Whether the actor holds a capability at the system context.
     *
     * @param int $actorid The acting user.
     * @param string $capability The capability name.
     * @return bool
     */
    protected function has_capability(int $actorid, string $capability): bool {
        return has_capability($capability, \context_system::instance(), $actorid);
    }

    /**
     * The scope cache.
     *
     * @return \cache
     */
    protected static function cache(): \cache {
        return \cache::make('local_mohhierarchy', self::CACHE_AREA);
    }
}
