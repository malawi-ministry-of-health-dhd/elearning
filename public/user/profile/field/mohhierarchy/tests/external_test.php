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

namespace profilefield_mohhierarchy;

use core_external\external_api;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;
use profilefield_mohhierarchy\external\get_districts;
use profilefield_mohhierarchy\external\get_facilities;

/**
 * Tests that the AJAX endpoints never step outside the caller's scope.
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_districts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_facilities::class)]
final class external_test extends \advanced_testcase {
    /** @var \stdClass[] Fixture hierarchy keyed by label. */
    protected array $tree = [];

    /** @var assignment_service Places the actors. */
    protected assignment_service $assignments;

    /** @var int Role carrying the plugin capabilities. */
    protected int $roleid;

    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
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

        $this->roleid = $this->getDataGenerator()->create_role(['shortname' => 'mohbrowser']);
        foreach ([permission_service::CAP_VIEW_HIERARCHY, permission_service::CAP_CREATE_USER] as $capability) {
            assign_capability($capability, CAP_ALLOW, $this->roleid, \context_system::instance()->id, true);
        }
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
     * Log in as an actor with the given scope.
     *
     * @param scope_level|null $level Scope to delegate, null for no assignment.
     * @param string $facility Fixture label of the facility to place the actor at.
     * @return \stdClass The user record.
     */
    protected function login_actor(?scope_level $level, string $facility = 'a1a'): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        if ($level !== null) {
            $this->assignments->assign_user(
                (int) $user->id,
                $this->id($facility),
                $level,
                null,
                assign_source::MIGRATION
            );
        }
        role_assign($this->roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        return $user;
    }

    /**
     * Call get_districts and clean the result through its return description.
     *
     * @param int $zoneid Zone to ask about.
     * @return array
     */
    protected function districts(int $zoneid): array {
        return external_api::clean_returnvalue(get_districts::execute_returns(), get_districts::execute($zoneid));
    }

    /**
     * Call get_facilities and clean the result through its return description.
     *
     * @param int $districtid District to ask about.
     * @return array
     */
    protected function facilities(int $districtid): array {
        return external_api::clean_returnvalue(get_facilities::execute_returns(), get_facilities::execute($districtid));
    }

    /**
     * A site administrator can browse the whole active hierarchy.
     */
    public function test_administrator_sees_everything_active(): void {
        $this->setAdminUser();

        $this->assertEqualsCanonicalizing(
            [$this->id('a1'), $this->id('a2')],
            array_column($this->districts($this->id('zonea')), 'id'),
        );
        $this->assertSame([$this->id('b1')], array_column($this->districts($this->id('zoneb')), 'id'));
        $this->assertSame([$this->id('a1a')], array_column($this->facilities($this->id('a1')), 'id'));
        $this->assertSame([$this->id('b1a')], array_column($this->facilities($this->id('b1')), 'id'));
    }

    /**
     * A zone manager cannot reach another zone, whatever zone id is sent.
     */
    public function test_zone_manager_is_confined_to_their_zone(): void {
        $this->login_actor(scope_level::ZONE);

        $this->assertEqualsCanonicalizing(
            [$this->id('a1'), $this->id('a2')],
            array_column($this->districts($this->id('zonea')), 'id'),
        );
        $this->assertSame([], $this->districts($this->id('zoneb')));
        $this->assertSame([], $this->facilities($this->id('b1')));
        $this->assertSame([$this->id('a2a')], array_column($this->facilities($this->id('a2')), 'id'));
    }

    /**
     * A district manager cannot reach a sibling district.
     */
    public function test_district_manager_is_confined_to_their_district(): void {
        $this->login_actor(scope_level::DISTRICT);

        $this->assertSame([$this->id('a1')], array_column($this->districts($this->id('zonea')), 'id'));
        $this->assertSame([$this->id('a1a')], array_column($this->facilities($this->id('a1')), 'id'));
        $this->assertSame([], $this->facilities($this->id('a2')));
    }

    /**
     * A facility manager sees only their own facility.
     */
    public function test_facility_manager_sees_only_their_facility(): void {
        $this->login_actor(scope_level::FACILITY);

        $this->assertSame([$this->id('a1')], array_column($this->districts($this->id('zonea')), 'id'));
        $this->assertSame([$this->id('a1a')], array_column($this->facilities($this->id('a1')), 'id'));
    }

    /**
     * Scope none and no assignment both return nothing.
     */
    public function test_actors_without_scope_get_nothing(): void {
        $this->login_actor(scope_level::NONE);
        $this->assertSame([], $this->districts($this->id('zonea')));
        $this->assertSame([], $this->facilities($this->id('a1')));

        $this->login_actor(null);
        $this->assertSame([], $this->districts($this->id('zonea')));
        $this->assertSame([], $this->facilities($this->id('a1')));
    }

    /**
     * Inactive units are never returned, to anyone.
     */
    public function test_inactive_units_are_never_returned(): void {
        global $DB;

        $this->setAdminUser();
        $this->assertSame(
            [$this->id('a1a')],
            array_column($this->facilities($this->id('a1')), 'id'),
            'The inactive facility A1b must not be offered',
        );

        $DB->set_field('local_mohh_district', 'active', 0, ['id' => $this->id('a2')]);
        $this->assertSame([$this->id('a1')], array_column($this->districts($this->id('zonea')), 'id'));
        $this->assertSame([], $this->facilities($this->id('a2')));
    }

    /**
     * Nonsense ids produce an empty list rather than an error or a leak.
     *
     * @param int $id The value a tampered request might send.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tampered_provider')]
    public function test_tampered_ids_return_nothing(int $id): void {
        $this->login_actor(scope_level::ZONE);

        $this->assertSame([], $this->districts($id));
        $this->assertSame([], $this->facilities($id));
    }

    /**
     * Values a tampered request might send.
     *
     * @return array[]
     */
    public static function tampered_provider(): array {
        return [
            'zero' => [0],
            'negative' => [-1],
            'nonexistent' => [987654],
        ];
    }

    /**
     * Only local ids and names are returned, never remote ids or extra columns.
     */
    public function test_only_local_ids_and_names_are_returned(): void {
        $this->setAdminUser();

        $districts = $this->districts($this->id('zonea'));
        $this->assertNotEmpty($districts);
        foreach ($districts as $district) {
            $this->assertSame(['id', 'name'], array_keys($district));
        }

        $facilities = $this->facilities($this->id('a1'));
        $this->assertNotEmpty($facilities);
        foreach ($facilities as $facility) {
            $this->assertSame(['id', 'name'], array_keys($facility));
            $this->assertNotEquals(
                $this->tree['a1a']->externalid,
                $facility['id'],
                'A remote Zipatala id must never be returned',
            );
        }
    }

    /**
     * A parameter of the wrong type is refused by the external API's own validation.
     */
    public function test_parameters_are_validated(): void {
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        get_districts::validate_parameters(get_districts::execute_parameters(), ['zoneid' => 'not an integer']);
    }

    /**
     * Both functions are declared as AJAX capable and require login.
     */
    public function test_service_declarations(): void {
        global $CFG;

        $functions = [];
        require($CFG->dirroot . '/user/profile/field/mohhierarchy/db/services.php');

        foreach (['profilefield_mohhierarchy_get_districts', 'profilefield_mohhierarchy_get_facilities'] as $name) {
            $this->assertArrayHasKey($name, $functions);
            $this->assertTrue($functions[$name]['ajax']);
            $this->assertTrue($functions[$name]['loginrequired']);
            $this->assertSame('read', $functions[$name]['type']);
            $this->assertTrue(class_exists($functions[$name]['classname']));
        }
    }
}
