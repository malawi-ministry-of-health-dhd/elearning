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

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;
use profilefield_mohhierarchy\local\hierarchy_options;

/**
 * Tests for the MoH hierarchy profile field.
 *
 * Fixture hierarchy:
 *
 *     Zone A ── District A1 ── Facility A1a (active)
 *            │              └─ Facility A1b (inactive)
 *            └─ District A2 ── Facility A2a (active)
 *     Zone B ── District B1 ── Facility B1a (active)
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\profile_field_mohhierarchy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hierarchy_options::class)]
final class field_test extends \advanced_testcase {
    /** @var \stdClass[] Fixture hierarchy keyed by label. */
    protected array $tree = [];

    /** @var \stdClass The installed user_info_field row. */
    protected \stdClass $field;

    /** @var assignment_service Places the actors and targets. */
    protected assignment_service $assignments;

    /** @var int Role carrying the plugin and core user capabilities. */
    protected int $roleid;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/user/profile/field/mohhierarchy/field.class.php');
        require_once($CFG->dirroot . '/user/profile/field/mohhierarchy/tests/fixtures/hierarchy_test_form.php');
        parent::setUpBeforeClass();
    }

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

        $this->field = $DB->get_record('user_info_field', ['shortname' => 'mohfacility'], '*', MUST_EXIST);

        $this->roleid = $this->getDataGenerator()->create_role(['shortname' => 'mohcreator']);
        $context = \context_system::instance();
        foreach (
            [
            permission_service::CAP_VIEW_HIERARCHY,
            permission_service::CAP_CREATE_USER,
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            'moodle/user:create',
            'moodle/user:update',
            'moodle/user:viewalldetails',
            ] as $capability
        ) {
            assign_capability($capability, CAP_ALLOW, $this->roleid, $context->id, true);
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
     * Create an actor with the given scope and the plugin role.
     *
     * @param scope_level|null $level Scope to delegate, or null for no assignment at all.
     * @param string $facility Fixture label of the facility to place the actor at.
     * @return \stdClass The user record.
     */
    protected function actor(?scope_level $level, string $facility = 'a1a'): \stdClass {
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

        return $user;
    }

    /**
     * The field object for a given target user.
     *
     * @param int $userid Target user id, or -1 when creating.
     * @return \profile_field_mohhierarchy
     */
    protected function field(int $userid): \profile_field_mohhierarchy {
        return new \profile_field_mohhierarchy((int) $this->field->id, $userid);
    }

    /**
     * The option lists a given actor would see for a given target.
     *
     * @param int $userid Target user id, or -1 when creating.
     * @return array{zones: array, districts: array, facilities: array, fixed: array}
     */
    protected function rendered(int $userid = -1): array {
        global $USER;

        $options = new hierarchy_options();
        $actorid = (int) $USER->id;
        $selection = $options->initial_selection($userid);
        $fixed = $options->fixed_levels($actorid);
        $zones = $options->zones($actorid);
        $picker = $options->facility_picker($actorid);

        return [
            'zones' => $zones,
            'districts' => $picker['districts'],
            'facilities' => $picker['facilities'],
            'fixed' => $fixed,
        ];
    }

    /**
     * A site administrator may choose any active zone, district and facility.
     */
    public function test_site_administrator_options(): void {
        $this->setAdminUser();
        $rendered = $this->rendered();

        $this->assertCount(2, $rendered['zones']);
        $this->assertSame(['zone' => false, 'district' => false, 'facility' => false], $rendered['fixed']);
        $options = new hierarchy_options();
        $this->assertArrayHasKey($this->id('b1a'), $options->facilities((int) get_admin()->id, $this->id('b1')));
        $this->assertArrayNotHasKey($this->id('a1b'), $options->facilities((int) get_admin()->id, $this->id('a1')));
    }

    /**
     * A zone manager has a fixed zone and may choose within it.
     */
    public function test_zone_manager_options(): void {
        $this->setUser($this->actor(scope_level::ZONE));
        $rendered = $this->rendered();

        $this->assertSame([$this->id('zonea')], array_keys($rendered['zones']));
        $this->assertSame(['zone' => true, 'district' => false, 'facility' => false], $rendered['fixed']);
        $this->assertEqualsCanonicalizing([$this->id('a1'), $this->id('a2')], array_keys($rendered['districts']));
    }

    /**
     * A district manager has a fixed zone and district.
     */
    public function test_district_manager_options(): void {
        $this->setUser($this->actor(scope_level::DISTRICT));
        $rendered = $this->rendered();

        $this->assertSame([$this->id('zonea')], array_keys($rendered['zones']));
        $this->assertSame([$this->id('a1')], array_keys($rendered['districts']));
        $this->assertSame(['zone' => true, 'district' => true, 'facility' => false], $rendered['fixed']);
        $this->assertSame([$this->id('a1a')], array_keys($rendered['facilities']));
    }

    /**
     * A facility manager has all three levels fixed.
     */
    public function test_facility_manager_options(): void {
        $this->setUser($this->actor(scope_level::FACILITY));
        $rendered = $this->rendered();

        $this->assertSame(['zone' => true, 'district' => true, 'facility' => true], $rendered['fixed']);
        $this->assertSame([$this->id('a1a')], array_keys($rendered['facilities']));
    }

    /**
     * An actor with no assignment sees nothing and cannot use the field.
     */
    public function test_actor_with_no_assignment(): void {
        $actor = $this->actor(null);
        $this->setUser($actor);

        $rendered = $this->rendered();
        $this->assertSame([], $rendered['zones']);
        $this->assertSame([], $rendered['districts']);
        $this->assertSame([], $rendered['facilities']);
        $this->assertFalse($this->field(-1)->is_editable());
    }

    /**
     * The field renders three elements, all named from the real profile field input name.
     */
    public function test_elements_use_the_field_input_name(): void {
        $this->setAdminUser();
        $form = new \profilefield_mohhierarchy_test_form(new \moodle_url('/user/editadvanced.php'));
        $mform = $form->get_mform();

        $field = $this->field(-1);
        $this->assertTrue($field->edit_field($mform));

        $this->assertTrue($mform->elementExists('profile_field_mohfacility'));
        $this->assertTrue($mform->elementExists('profile_field_mohfacility_zone'));
        $this->assertTrue($mform->elementExists('profile_field_mohfacility_district'));
        $this->assertSame('autocomplete', $mform->getElement('profile_field_mohfacility')->getType());
        $this->assertStringContainsString(
            'mohhierarchy-selector',
            (string) $mform->getElement('profile_field_mohfacility')->getAttribute('class'),
        );

        $html = $form->render();
        $facilityposition = strpos($html, 'name="profile_field_mohfacility"');
        $districtposition = strpos($html, 'name="profile_field_mohfacility_district"');
        $zoneposition = strpos($html, 'name="profile_field_mohfacility_zone"');
        $this->assertNotFalse($facilityposition);
        $this->assertNotFalse($districtposition);
        $this->assertNotFalse($zoneposition);
        $this->assertLessThan($districtposition, $zoneposition);
        $this->assertLessThan($facilityposition, $districtposition);
    }

    /**
     * Moodle's real advanced add-user form receives the hierarchy profile elements.
     */
    public function test_core_advanced_add_user_form_receives_profile_field(): void {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/webservice/lib.php');
        require_once($CFG->dirroot . '/user/editlib.php');
        require_once($CFG->dirroot . '/user/editadvanced_form.php');
        $this->setAdminUser();
        $context = \context_system::instance();
        $PAGE->set_context($context);
        $PAGE->set_url('/user/editadvanced.php', ['id' => -1]);
        $user = (object) [
            'id' => -1,
            'auth' => 'manual',
            'confirmed' => 1,
            'deleted' => 0,
            'timezone' => '99',
            'imagefile' => 0,
        ];
        $editoroptions = [
            'maxfiles' => 0,
            'maxbytes' => 0,
            'trusttext' => false,
            'forcehttps' => false,
            'context' => $context,
        ];
        $filemanageroptions = [
            'maxbytes' => 0,
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => 'optimised_image',
        ];
        $form = new class (new \moodle_url('/user/editadvanced.php', ['id' => -1]), [
            'editoroptions' => $editoroptions,
            'filemanageroptions' => $filemanageroptions,
            'user' => $user,
        ]) extends \user_editadvanced_form {
            /**
             * Exposes the form definition for integration assertions.
             *
             * @return \MoodleQuickForm
             */
            public function get_mform_for_test(): \MoodleQuickForm {
                return $this->_form;
            }
        };
        $mform = $form->get_mform_for_test();

        $this->assertTrue($mform->elementExists('profile_field_mohfacility_zone'));
        $this->assertTrue($mform->elementExists('profile_field_mohfacility_district'));
        $this->assertTrue($mform->elementExists('profile_field_mohfacility'));
    }

    /**
     * A facility manager's fixed facility element is frozen but still carries its value.
     */
    public function test_fixed_facility_is_frozen_but_submits(): void {
        $this->setUser($this->actor(scope_level::FACILITY));
        $form = new \profilefield_mohhierarchy_test_form(new \moodle_url('/user/editadvanced.php'));
        $mform = $form->get_mform();

        $this->assertTrue($this->field(-1)->edit_field($mform));
        $this->assertTrue($mform->getElement('profile_field_mohfacility')->isFrozen());
        $this->assertSame(
            (string) $this->id('a1a'),
            (string) $mform->exportValue('profile_field_mohfacility'),
            'A frozen level must still submit its value',
        );
    }

    /**
     * Creating a user requires both the core and the local capability, plus a usable scope.
     */
    public function test_is_editable_for_a_new_user(): void {
        $this->setAdminUser();
        $this->assertTrue($this->field(-1)->is_editable());

        $this->setUser($this->actor(scope_level::ZONE));
        $this->assertTrue($this->field(-1)->is_editable());

        // Scope none is not a usable scope.
        $this->setUser($this->actor(scope_level::NONE));
        $this->assertFalse($this->field(-1)->is_editable());

        // A user with the assignment but none of the capabilities.
        $plain = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $plain->id, $this->id('a1a'), scope_level::ZONE);
        $this->setUser($plain);
        $this->assertFalse($this->field(-1)->is_editable());
    }

    /**
     * Ordinary users may never change their own hierarchy.
     */
    public function test_users_cannot_edit_their_own_hierarchy(): void {
        $actor = $this->actor(scope_level::ZONE);
        $this->setUser($actor);

        $this->assertFalse(
            $this->field((int) $actor->id)->is_editable(),
            'Even a zone manager may not edit their own hierarchy',
        );

        // An administrator still can, on themselves or anyone else.
        $this->setAdminUser();
        $this->assertTrue($this->field((int) get_admin()->id)->is_editable());
        $this->assertTrue($this->field((int) $actor->id)->is_editable());
    }

    /**
     * Editing an existing user needs the local permission service to agree.
     */
    public function test_is_editable_for_an_existing_user(): void {
        $actor = $this->actor(scope_level::DISTRICT);
        $insidescope = $this->getDataGenerator()->create_user();
        $outsidescope = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $insidescope->id, $this->id('a1a'), scope_level::NONE);
        $this->assignments->assign_user((int) $outsidescope->id, $this->id('a2a'), scope_level::NONE);

        $this->setUser($actor);
        $this->assertTrue($this->field((int) $insidescope->id)->is_editable());
        $this->assertFalse($this->field((int) $outsidescope->id)->is_editable());
    }

    /**
     * Validation requires a facility when creating a user.
     */
    public function test_validation_requires_a_facility_when_creating(): void {
        $this->setAdminUser();
        $field = $this->field(-1);

        $errors = $field->edit_validate_field((object) ['profile_field_mohfacility' => '']);
        $this->assertArrayHasKey('profile_field_mohfacility', $errors);

        $errors = $field->edit_validate_field((object) ['profile_field_mohfacility' => $this->id('a1a')]);
        $this->assertSame([], $errors);
    }

    /**
     * Values that never came from the rendered form are rejected.
     *
     * @param mixed $submitted The value a tampered request might send.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tampered_provider')]
    public function test_validation_rejects_tampered_values(mixed $submitted): void {
        $this->setAdminUser();

        $errors = $this->field(-1)->edit_validate_field((object) ['profile_field_mohfacility' => $submitted]);
        $this->assertArrayHasKey(
            'profile_field_mohfacility',
            $errors,
            'A tampered value must produce an error against the facility element'
        );
    }

    /**
     * Values a tampered request might submit.
     *
     * @return array[]
     */
    public static function tampered_provider(): array {
        return [
            'nonexistent id' => [987654],
            'zero' => [0],
            'negative' => [-5],
            'empty string' => [''],
            'not a number' => ['abc'],
            'sql fragment' => ['1 OR 1=1'],
            'array' => [['1']],
        ];
    }

    /**
     * A facility outside the actor's scope is rejected even though it exists and is active.
     */
    public function test_validation_rejects_a_facility_outside_scope(): void {
        $this->setUser($this->actor(scope_level::DISTRICT));

        $errors = $this->field(-1)->edit_validate_field((object) [
            'profile_field_mohfacility' => $this->id('a2a'),
        ]);
        $this->assertArrayHasKey('profile_field_mohfacility', $errors);
    }

    /**
     * An inactive facility cannot be chosen for a new user.
     */
    public function test_validation_rejects_an_inactive_facility(): void {
        $this->setAdminUser();

        $errors = $this->field(-1)->edit_validate_field((object) [
            'profile_field_mohfacility' => $this->id('a1b'),
        ]);
        $this->assertArrayHasKey('profile_field_mohfacility', $errors);
    }

    /**
     * Saving stores the local facility id and upserts the assignment, with scope none by default.
     */
    public function test_saving_stores_the_facility_and_the_assignment(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $field = $this->field((int) $user->id);

        $usernew = (object) ['id' => $user->id, 'profile_field_mohfacility' => (string) $this->id('a1a')];
        $field->edit_save_data($usernew);

        $stored = $DB->get_field('user_info_data', 'data', ['userid' => $user->id, 'fieldid' => $this->field->id]);
        $this->assertSame((string) $this->id('a1a'), $stored, 'The stored value is the local facility id');
        $this->assertNotEquals(
            (string) $this->tree['a1a']->externalid,
            $stored,
            'The remote Zipatala id must never be stored',
        );

        $assignment = $this->assignments->get_assignment((int) $user->id);
        $this->assertNotNull($assignment);
        $this->assertEquals($this->id('a1a'), $assignment->facilityid);
        $this->assertEquals($this->id('a1'), $assignment->districtid);
        $this->assertEquals($this->id('zonea'), $assignment->zoneid);
        $this->assertSame('none', $assignment->scopelevel, 'A new user gets no delegated management');
        $this->assertSame('userform', $assignment->assignsource);
    }

    /**
     * Saving twice changes nothing the second time.
     */
    public function test_saving_is_idempotent(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $field = $this->field((int) $user->id);
        $usernew = (object) ['id' => $user->id, 'profile_field_mohfacility' => (string) $this->id('a1a')];

        $field->edit_save_data($usernew);
        $first = $this->assignments->get_assignment((int) $user->id);
        $field->edit_save_data($usernew);
        $second = $this->assignments->get_assignment((int) $user->id);

        $this->assertEquals($first->timemodified, $second->timemodified);
        $this->assertSame(1, $DB->count_records('local_mohh_assignlog', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('user_info_data', ['userid' => $user->id]));
    }

    /**
     * Saving never grants scope, and never takes an existing scope away.
     */
    public function test_saving_preserves_an_existing_scope(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $user->id, $this->id('a1a'), scope_level::DISTRICT);

        $field = $this->field((int) $user->id);
        $field->edit_save_data((object) ['id' => $user->id, 'profile_field_mohfacility' => (string) $this->id('a2a')]);

        $assignment = $this->assignments->get_assignment((int) $user->id);
        $this->assertEquals($this->id('a2a'), $assignment->facilityid);
        $this->assertSame('district', $assignment->scopelevel, 'The form must not change a granted scope');
    }

    /**
     * An empty submission for an existing user leaves the assignment alone.
     */
    public function test_saving_nothing_leaves_the_assignment_alone(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $user->id, $this->id('a1a'), scope_level::NONE);

        $this->field((int) $user->id)->edit_save_data((object) [
            'id' => $user->id,
            'profile_field_mohfacility' => '',
        ]);

        $this->assertNotNull($this->assignments->get_assignment((int) $user->id));
        $this->assertEquals($this->id('a1a'), $this->assignments->get_assignment((int) $user->id)->facilityid);
    }

    /**
     * An existing user's three values start from the canonical assignment.
     */
    public function test_existing_user_initial_values(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $user->id, $this->id('a2a'), scope_level::NONE);

        $selection = (new hierarchy_options())->initial_selection((int) $user->id);
        $this->assertNotNull($selection);
        $this->assertSame($this->id('a2a'), $selection->facilityid);
        $this->assertSame($this->id('a2'), $selection->districtid);
        $this->assertSame($this->id('zonea'), $selection->zoneid);
        $this->assertTrue($selection->fromassignment);

        $loaded = (object) [];
        $this->field((int) $user->id)->edit_load_user_data($loaded);
        $this->assertSame((string) $this->id('a2a'), $loaded->profile_field_mohfacility);
    }

    /**
     * With no assignment but a stored facility id, the zone and district are still derived.
     */
    public function test_values_are_derived_when_the_assignment_is_missing(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        // A field value with no assignment behind it, as a partial migration would leave.
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $this->field->id,
            'data' => (string) $this->id('a2a'),
            'dataformat' => 0,
        ]);

        $selection = (new hierarchy_options())->initial_selection((int) $user->id, (string) $this->id('a2a'));
        $this->assertNotNull($selection);
        $this->assertSame($this->id('a2'), $selection->districtid);
        $this->assertSame($this->id('zonea'), $selection->zoneid);
        $this->assertFalse($selection->fromassignment, 'Flagged as derived, so repair can reconcile it later');

        // Rendering must not have written anything.
        $this->assertNull($this->assignments->get_assignment((int) $user->id));
    }

    /**
     * An existing assignment at an inactive facility is shown and can be kept, but not moved to.
     */
    public function test_inactive_current_facility(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('local_mohh_facility', 'active', 1, ['id' => $this->id('a1b')]);
        $this->assignments->assign_user((int) $user->id, $this->id('a1b'), scope_level::NONE);
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $this->id('a1b')]);

        $field = $this->field((int) $user->id);

        // Displayed, with the inactive marker.
        $this->assertStringContainsString('A1b', $field->display_data());
        $this->assertStringContainsString('inactive', $field->display_data());

        // Offered in the form, so an unrelated edit does not force a move.
        $form = new \profilefield_mohhierarchy_test_form(new \moodle_url('/user/editadvanced.php'));
        $field->edit_field($form->get_mform());
        $choices = $form->get_mform()->getElement('profile_field_mohfacility')->getSelected();
        $this->assertSame([$this->id('a1b')], $choices);

        // Re-saving the same inactive facility is accepted.
        $this->assertSame([], $field->edit_validate_field((object) [
            'id' => $user->id,
            'profile_field_mohfacility' => $this->id('a1b'),
        ]));

        // But another user may not be placed there.
        $other = $this->getDataGenerator()->create_user();
        $errors = $this->field((int) $other->id)->edit_validate_field((object) [
            'id' => $other->id,
            'profile_field_mohfacility' => $this->id('a1b'),
        ]);
        $this->assertArrayHasKey('profile_field_mohfacility', $errors);
    }

    /**
     * The display format is Zone / District / Facility.
     */
    public function test_display_data(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $this->assignments->assign_user((int) $user->id, $this->id('a2a'), scope_level::NONE);

        $this->assertSame('Zone A / District A2 / A2a', $this->field((int) $user->id)->display_data());

        $empty = $this->getDataGenerator()->create_user();
        $this->assertSame(
            get_string('notset', 'profilefield_mohhierarchy'),
            $this->field((int) $empty->id)->display_data(),
        );
    }
}
