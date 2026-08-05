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

namespace local_mohhierarchy;

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;

/**
 * The permission matrix for hierarchy scope and capabilities.
 *
 * The fixture is two zones, so that "another zone" and "another district" are real places rather
 * than made up ids:
 *
 *     Zone A ── District A1 ── Facility A1a (active)
 *            │              └─ Facility A1b (inactive)
 *            └─ District A2 ── Facility A2a (active)
 *     Zone B ── District B1 ── Facility B1a (active)
 *
 * Every actor below is assigned to Facility A1a and differs only in scope level and capabilities.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(permission_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(
    \local_mohhierarchy\local\hierarchy\hierarchy_scope::class,
)]
final class permission_service_test extends \advanced_testcase {
    /** @var permission_service The service under test. */
    protected permission_service $permissions;

    /** @var assignment_service Used to place the actors. */
    protected assignment_service $assignments;

    /** @var \stdClass[] The fixture hierarchy, keyed by label. */
    protected array $tree = [];

    /** @var int Role id carrying every plugin capability. */
    protected int $roleid;

    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->permissions = new permission_service();
        $this->assignments = new assignment_service();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
        $this->tree['zonea'] = $generator->create_zone(['name' => 'Zone A']);
        $this->tree['zoneb'] = $generator->create_zone(['name' => 'Zone B']);
        $this->tree['a1'] = $generator->create_district(['zoneid' => $this->tree['zonea']->id, 'name' => 'District A1']);
        $this->tree['a2'] = $generator->create_district(['zoneid' => $this->tree['zonea']->id, 'name' => 'District A2']);
        $this->tree['b1'] = $generator->create_district(['zoneid' => $this->tree['zoneb']->id, 'name' => 'District B1']);
        $this->tree['a1a'] = $generator->create_facility(['districtid' => $this->tree['a1']->id, 'name' => 'A1a']);
        $this->tree['a1b'] = $generator->create_facility(['districtid' => $this->tree['a1']->id, 'name' => 'A1b']);
        $this->tree['a2a'] = $generator->create_facility(['districtid' => $this->tree['a2']->id, 'name' => 'A2a']);
        $this->tree['b1a'] = $generator->create_facility(['districtid' => $this->tree['b1']->id, 'name' => 'B1a']);
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $this->tree['a1b']->id]);

        $this->roleid = $this->getDataGenerator()->create_role(['shortname' => 'mohmanager']);
        foreach (
            [
            permission_service::CAP_VIEW_HIERARCHY,
            permission_service::CAP_CREATE_USER,
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            permission_service::CAP_VIEW_ASSIGNMENTS,
            ] as $capability
        ) {
            assign_capability($capability, CAP_ALLOW, $this->roleid, \context_system::instance()->id, true);
        }
        // Assign_capability() does not invalidate access data on its own.
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Local id of a fixture node.
     *
     * @param string $label Key into the fixture tree.
     * @return int
     */
    protected function id(string $label): int {
        return (int) $this->tree[$label]->id;
    }

    /**
     * Create an actor with the given scope, optionally without the plugin capabilities.
     *
     * @param scope_level $level The scope level to delegate.
     * @param string $facility Fixture label of the facility to place the actor at.
     * @param bool $withcapability Whether to give the actor the plugin role.
     * @return int The actor's user id.
     */
    protected function actor(
        scope_level $level,
        string $facility = 'a1a',
        bool $withcapability = true,
    ): int {
        $user = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user(
            (int) $user->id,
            $this->id($facility),
            $level,
            null,
            assign_source::MIGRATION,
        );
        if ($withcapability) {
            role_assign($this->roleid, $user->id, \context_system::instance()->id);
        }

        return (int) $user->id;
    }

    /**
     * An actor holding the capabilities but with no assignment at all.
     *
     * @return int
     */
    protected function actor_without_assignment(): int {
        $user = $this->getDataGenerator()->create_user();
        role_assign($this->roleid, $user->id, \context_system::instance()->id);

        return (int) $user->id;
    }

    /**
     * A site administrator sees the whole active hierarchy and bypasses scope.
     */
    public function test_site_administrator(): void {
        $admin = (int) get_admin()->id;

        $this->assertCount(2, $this->permissions->get_allowed_zones($admin));
        $this->assertCount(2, $this->permissions->get_allowed_districts($admin, $this->id('zonea')));
        $this->assertCount(1, $this->permissions->get_allowed_districts($admin, $this->id('zoneb')));

        // Both zones' facilities are reachable, but the inactive one is not offered.
        $a1 = $this->permissions->get_allowed_facilities($admin, $this->id('a1'));
        $this->assertArrayHasKey($this->id('a1a'), $a1);
        $this->assertArrayNotHasKey($this->id('a1b'), $a1, 'An inactive facility is never offered');
        $this->assertArrayHasKey($this->id('b1a'), $this->permissions->get_allowed_facilities($admin, $this->id('b1')));

        $this->assertTrue($this->permissions->can_create_user_in_facility($admin, $this->id('a1a')));
        $this->assertTrue($this->permissions->can_create_user_in_facility($admin, $this->id('b1a')));
        $this->assertFalse(
            $this->permissions->can_create_user_in_facility($admin, $this->id('a1b')),
            'Not even an administrator may place a user at an inactive facility',
        );
        $this->assertFalse($this->permissions->can_create_user_in_facility($admin, 999999));
    }

    /**
     * A zone manager reaches every district and active facility of their own zone, and no other.
     */
    public function test_zone_manager(): void {
        $actor = $this->actor(scope_level::ZONE);

        $zones = $this->permissions->get_allowed_zones($actor);
        $this->assertSame([$this->id('zonea')], array_keys($zones));

        $districts = $this->permissions->get_allowed_districts($actor, $this->id('zonea'));
        $this->assertEqualsCanonicalizing([$this->id('a1'), $this->id('a2')], array_keys($districts));
        $this->assertSame(
            [],
            $this->permissions->get_allowed_districts($actor, $this->id('zoneb')),
            'A district in another zone is unreachable'
        );

        $this->assertSame([$this->id('a1a')], array_keys($this->permissions->get_allowed_facilities($actor, $this->id('a1'))));
        $this->assertSame([$this->id('a2a')], array_keys($this->permissions->get_allowed_facilities($actor, $this->id('a2'))));
        $this->assertSame(
            [],
            $this->permissions->get_allowed_facilities($actor, $this->id('b1')),
            'A facility in another zone is unreachable'
        );
        $paths = $this->permissions->get_allowed_facility_paths($actor);
        $this->assertEqualsCanonicalizing(
            [$this->id('a1a'), $this->id('a2a')],
            array_keys($paths),
        );
        $this->assertArrayNotHasKey($this->id('a1b'), $paths);
        $this->assertArrayNotHasKey($this->id('b1a'), $paths);

        $this->assertTrue($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));
        $this->assertTrue($this->permissions->can_create_user_in_facility($actor, $this->id('a2a')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a1b')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('b1a')));
    }

    /**
     * A district manager reaches only their own district, inside their own zone.
     */
    public function test_district_manager(): void {
        $actor = $this->actor(scope_level::DISTRICT);

        $this->assertSame([$this->id('zonea')], array_keys($this->permissions->get_allowed_zones($actor)));
        $this->assertSame([$this->id('a1')], array_keys($this->permissions->get_allowed_districts($actor, $this->id('zonea'))));
        $this->assertSame([], $this->permissions->get_allowed_districts($actor, $this->id('zoneb')));

        $this->assertSame([$this->id('a1a')], array_keys($this->permissions->get_allowed_facilities($actor, $this->id('a1'))));
        $this->assertSame(
            [],
            $this->permissions->get_allowed_facilities($actor, $this->id('a2')),
            'A sibling district in the same zone is out of scope'
        );
        $this->assertSame([], $this->permissions->get_allowed_facilities($actor, $this->id('b1')));

        $this->assertTrue($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a2a')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('b1a')));
    }

    /**
     * A facility manager reaches only their own facility.
     */
    public function test_facility_manager(): void {
        $actor = $this->actor(scope_level::FACILITY);

        $this->assertSame([$this->id('zonea')], array_keys($this->permissions->get_allowed_zones($actor)));
        $this->assertSame([$this->id('a1')], array_keys($this->permissions->get_allowed_districts($actor, $this->id('zonea'))));
        $this->assertSame([$this->id('a1a')], array_keys($this->permissions->get_allowed_facilities($actor, $this->id('a1'))));
        $this->assertSame([], $this->permissions->get_allowed_facilities($actor, $this->id('a2')));

        $this->assertTrue($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a2a')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('b1a')));
    }

    /**
     * Scope none delegates nothing, however many capabilities the actor holds.
     */
    public function test_scope_none(): void {
        $actor = $this->actor(scope_level::NONE);

        $this->assertSame([], $this->permissions->get_allowed_zones($actor));
        $this->assertSame([], $this->permissions->get_allowed_districts($actor, $this->id('zonea')));
        $this->assertSame([], $this->permissions->get_allowed_facilities($actor, $this->id('a1')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));

        $target = $this->getDataGenerator()->create_user();
        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) $target->id));
    }

    /**
     * Hierarchy scope alone is not enough: the capability is also required.
     */
    public function test_actor_without_plugin_capability(): void {
        $actor = $this->actor(scope_level::ZONE, 'a1a', false);

        $this->assertSame(
            scope_level::ZONE,
            $this->permissions->get_scope($actor)->level,
            'The scope is real, only the capability is missing',
        );
        $this->assertSame([], $this->permissions->get_allowed_zones($actor));
        $this->assertSame([], $this->permissions->get_allowed_districts($actor, $this->id('zonea')));
        $this->assertSame([], $this->permissions->get_allowed_facilities($actor, $this->id('a1')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));

        $target = $this->getDataGenerator()->create_user();
        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) $target->id));
    }

    /**
     * The capability alone is not enough either: without an assignment there is no scope.
     */
    public function test_actor_with_capability_but_no_assignment(): void {
        $actor = $this->actor_without_assignment();

        $this->assertTrue(has_capability(permission_service::CAP_CREATE_USER, \context_system::instance(), $actor));
        $this->assertSame(scope_level::NONE, $this->permissions->get_scope($actor)->level);
        $this->assertSame([], $this->permissions->get_allowed_zones($actor));
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));

        $target = $this->getDataGenerator()->create_user();
        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) $target->id));
    }

    /**
     * A withdrawn assignment leaves the actor with no scope.
     */
    public function test_withdrawn_assignment_removes_scope(): void {
        $actor = $this->actor(scope_level::ZONE);
        $this->assertTrue($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));

        $this->assignments->withdraw_assignment($actor);

        $this->assertSame(
            scope_level::NONE,
            $this->permissions->get_scope($actor)->level,
            'The cached scope must be dropped when the assignment changes'
        );
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a1a')));
    }

    /**
     * An inactive facility is never offered and never accepted.
     */
    public function test_inactive_facility(): void {
        global $DB;

        $zoneactor = $this->actor(scope_level::ZONE);

        $this->assertArrayNotHasKey(
            $this->id('a1b'),
            $this->permissions->get_allowed_facilities($zoneactor, $this->id('a1')),
        );
        $this->assertFalse($this->permissions->can_create_user_in_facility($zoneactor, $this->id('a1b')));

        // An inactive ancestor takes its whole subtree out of play.
        $DB->set_field('local_mohh_district', 'active', 0, ['id' => $this->id('a2')]);
        $this->assertSame([], $this->permissions->get_allowed_facilities($zoneactor, $this->id('a2')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($zoneactor, $this->id('a2a')));
        $this->assertSame(
            [$this->id('a1')],
            array_keys($this->permissions->get_allowed_districts($zoneactor, $this->id('zonea')))
        );
    }

    /**
     * A facility id that does not exist, or is not a positive integer, is refused.
     *
     * @param int $facilityid The value a tampered request might submit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tampered_id_provider')]
    public function test_tampered_facility_id(int $facilityid): void {
        $zoneactor = $this->actor(scope_level::ZONE);
        $admin = (int) get_admin()->id;

        $this->assertFalse($this->permissions->can_create_user_in_facility($zoneactor, $facilityid));
        $this->assertFalse($this->permissions->can_create_user_in_facility($admin, $facilityid));
    }

    /**
     * Values a tampered request might submit as a facility id.
     *
     * @return array[]
     */
    public static function tampered_id_provider(): array {
        return [
            'zero' => [0],
            'negative' => [-1],
            'nonexistent' => [987654],
        ];
    }

    /**
     * A facility that exists but belongs to another district is refused for a district manager.
     */
    public function test_facility_in_another_district(): void {
        $actor = $this->actor(scope_level::DISTRICT);

        // A2a is a real, active facility in the actor's own zone, but not in their district.
        $this->assertTrue($this->permissions->get_scope($actor)->grants_management());
        $this->assertFalse($this->permissions->can_create_user_in_facility($actor, $this->id('a2a')));
        $this->assertSame([], $this->permissions->get_allowed_facilities($actor, $this->id('a2')));

        // Asking for the facilities of the actor's own district does not leak the sibling district.
        $allowed = $this->permissions->get_allowed_facilities($actor, $this->id('a1'));
        $this->assertArrayNotHasKey($this->id('a2a'), $allowed);
    }

    /**
     * A district that exists but belongs to another zone is refused.
     */
    public function test_district_in_another_zone(): void {
        $zoneactor = $this->actor(scope_level::ZONE);

        // B1 is a real, active district, but in Zone B.
        $this->assertSame([], $this->permissions->get_allowed_districts($zoneactor, $this->id('zoneb')));
        $this->assertSame([], $this->permissions->get_allowed_facilities($zoneactor, $this->id('b1')));
        $this->assertFalse($this->permissions->can_create_user_in_facility($zoneactor, $this->id('b1a')));

        // Passing the actor's own zone id while naming a district from another zone fails too.
        $this->assertArrayNotHasKey(
            $this->id('b1'),
            $this->permissions->get_allowed_districts($zoneactor, $this->id('zonea')),
        );
    }

    /**
     * Delegated managers cannot grant a scope broader than their own.
     */
    public function test_scope_escalation_is_rejected(): void {
        $facilityactor = $this->actor(scope_level::FACILITY);
        $districtactor = $this->actor(scope_level::DISTRICT);
        $zoneactor = $this->actor(scope_level::ZONE);
        $admin = (int) get_admin()->id;

        $this->assertTrue($this->permissions->can_grant_scope($facilityactor, scope_level::NONE));
        $this->assertTrue($this->permissions->can_grant_scope($facilityactor, scope_level::FACILITY));
        $this->assertFalse($this->permissions->can_grant_scope($facilityactor, scope_level::DISTRICT));
        $this->assertFalse($this->permissions->can_grant_scope($facilityactor, scope_level::ZONE));

        $this->assertTrue($this->permissions->can_grant_scope($districtactor, scope_level::DISTRICT));
        $this->assertFalse($this->permissions->can_grant_scope($districtactor, scope_level::ZONE));
        $this->assertTrue($this->permissions->can_grant_scope($zoneactor, scope_level::ZONE));

        foreach (scope_level::cases() as $scope) {
            $this->assertTrue($this->permissions->can_grant_scope($admin, $scope));
        }
    }

    /**
     * Managing another user's assignment needs the capability, and the target must be in scope.
     */
    public function test_can_manage_assignment(): void {
        $districtactor = $this->actor(scope_level::DISTRICT);
        $admin = (int) get_admin()->id;

        $unassigned = $this->getDataGenerator()->create_user();
        $insidescope = $this->getDataGenerator()->create_user();
        $outsidescope = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $insidescope->id, $this->id('a1a'), scope_level::NONE);
        $this->assignments->assign_user((int) $outsidescope->id, $this->id('a2a'), scope_level::NONE);

        $this->assertTrue(
            $this->permissions->can_manage_assignment($districtactor, (int) $unassigned->id),
            'A user with no assignment yet may be placed; the facility is scope checked separately'
        );
        $this->assertTrue($this->permissions->can_manage_assignment($districtactor, (int) $insidescope->id));
        $this->assertFalse(
            $this->permissions->can_manage_assignment($districtactor, (int) $outsidescope->id),
            'A target in a sibling district is out of scope'
        );
        $this->assertFalse(
            $this->permissions->can_manage_assignment($districtactor, $districtactor),
            'Nobody may edit their own scope'
        );
        $this->assertFalse($this->permissions->can_manage_assignment($districtactor, 0));

        $this->assertTrue($this->permissions->can_manage_assignment($admin, (int) $outsidescope->id));
        $this->assertFalse(
            $this->permissions->can_manage_assignment($admin, $admin),
            'No user needs to change their own delegated scope, including a site administrator',
        );
    }

    /**
     * Deleted users, guests and site administrators cannot be targeted by delegated managers.
     */
    public function test_protected_assignment_targets(): void {
        global $DB;

        $actor = $this->actor(scope_level::ZONE);
        $deleted = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) $deleted->id));
        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) get_admin()->id));
        $this->assertFalse($this->permissions->can_manage_assignment($actor, (int) guest_user()->id));
        $this->assertTrue(
            $this->permissions->can_manage_assignment((int) get_admin()->id, $actor),
            'Site administrators may manage other users, including delegated managers.',
        );
    }

    /**
     * Assigning a user refreshes that user's cached scope.
     */
    public function test_scope_cache_is_purged_on_assignment(): void {
        $actor = $this->actor(scope_level::FACILITY);
        $this->assertSame(scope_level::FACILITY, $this->permissions->get_scope($actor)->level);
        $this->assertSame([$this->id('a1a')], array_keys($this->permissions->get_allowed_facilities($actor, $this->id('a1'))));

        $this->assignments->assign_user($actor, $this->id('a1a'), scope_level::ZONE);

        $this->assertSame(scope_level::ZONE, $this->permissions->get_scope($actor)->level);
        $this->assertEqualsCanonicalizing(
            [$this->id('a1'), $this->id('a2')],
            array_keys($this->permissions->get_allowed_districts($actor, $this->id('zonea'))),
        );
    }

    /**
     * Purging every scope makes the next check re-read the database.
     */
    public function test_purge_all_scope_caches(): void {
        global $DB;

        $actor = $this->actor(scope_level::ZONE);
        $cache = \cache::make('local_mohhierarchy', 'scope');

        $this->assertSame(scope_level::ZONE, $this->permissions->get_scope($actor)->level);
        $this->assertIsArray($cache->get($actor), 'The resolved scope should now be cached');

        // Change the row behind the service's back, as a synchronisation repair would.
        $DB->set_field('local_mohh_assign', 'scopelevel', scope_level::FACILITY->value, ['userid' => $actor]);

        permission_service::purge_all_scope_caches();
        $this->assertFalse($cache->get($actor), 'The cache entry should be gone');
        $this->assertSame(scope_level::FACILITY, $this->permissions->get_scope($actor)->level);
    }
}
