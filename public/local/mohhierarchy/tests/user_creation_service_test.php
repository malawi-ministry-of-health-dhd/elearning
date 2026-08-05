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

use local_mohhierarchy\form\create_user_form;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\hierarchy\selector_options;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\local\user_creation_service;

/**
 * Strict delegated account creation and tampering tests.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(user_creation_service::class)]
final class user_creation_service_test extends \advanced_testcase {
    /** @var \stdClass[] Hierarchy fixture. */
    protected array $tree;

    /** @var int Delegated manager user id. */
    protected int $actorid;

    /** @var user_creation_service Service under test. */
    protected user_creation_service $service;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
        $zonea = $generator->create_zone(['name' => 'Zone A']);
        $zoneb = $generator->create_zone(['name' => 'Zone B']);
        $districta = $generator->create_district(['zoneid' => $zonea->id, 'name' => 'District A']);
        $districtb = $generator->create_district(['zoneid' => $zoneb->id, 'name' => 'District B']);
        $facilitya = $generator->create_facility(['districtid' => $districta->id, 'name' => 'Facility A']);
        $facilityb = $generator->create_facility(['districtid' => $districtb->id, 'name' => 'Facility B']);
        $this->tree = compact('zonea', 'zoneb', 'districta', 'districtb', 'facilitya', 'facilityb');

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'delegatedcreator']);
        foreach ([permission_service::CAP_CREATE_USER, permission_service::CAP_VIEW_HIERARCHY] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        $actor = $this->getDataGenerator()->create_user();
        role_assign($roleid, $actor->id, \context_system::instance()->id);
        (new assignment_service())->assign_user(
            (int) $actor->id,
            (int) $facilitya->id,
            scope_level::ZONE,
            null,
            assign_source::MIGRATION,
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->actorid = (int) $actor->id;
        $this->setUser($actor);
        $this->service = new user_creation_service();
    }

    /**
     * A manager without moodle/user:create can use the strict service inside their own scope.
     */
    public function test_delegated_creation_uses_core_api_and_defaults_scope_to_none(): void {
        global $DB;

        $this->assertFalse(has_capability('moodle/user:create', \context_system::instance(), $this->actorid));
        $sink = $this->redirectEvents();

        $result = $this->service->create_user($this->valid_data(), $this->actorid);

        $created = $DB->get_record('user', ['id' => $result->userid], '*', MUST_EXIST);
        $this->assertSame('delegated.person', $created->username);
        $assignment = (new assignment_service())->get_assignment((int) $created->id);
        $this->assertNotNull($assignment);
        $this->assertSame((int) $this->tree['facilitya']->id, (int) $assignment->facilityid);
        $this->assertSame(scope_level::NONE->value, $assignment->scopelevel);
        $this->assertSame($this->actorid, (int) $assignment->assignedby);

        $events = array_values(array_filter(
            $sink->get_events(),
            static fn(\core\event\base $event): bool => $event instanceof \core\event\user_created,
        ));
        $this->assertCount(1, $events);
        $this->assertSame((int) $created->id, (int) $events[0]->objectid);
    }

    /**
     * Changing the submitted facility to a real facility in another zone cannot escape scope.
     */
    public function test_tampered_facility_outside_scope_creates_nothing(): void {
        global $DB;

        $data = $this->valid_data('outside.person');
        $data->zoneid = (int) $this->tree['zoneb']->id;
        $data->districtid = (int) $this->tree['districtb']->id;
        $data->facilityid = (int) $this->tree['facilityb']->id;

        $errors = $this->service->validate_account_data($data, $this->actorid);
        $this->assertArrayHasKey('facilityid', $errors);

        try {
            $this->service->create_user($data, $this->actorid);
            $this->fail('A facility outside the actor scope must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:usercreationinvalid', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('user', ['username' => 'outside.person']));
    }

    /**
     * Changing submitted ancestor ids cannot relabel an otherwise valid facility.
     */
    public function test_tampered_ancestor_ids_create_nothing(): void {
        global $DB;

        $data = $this->valid_data('mismatch.person');
        $data->zoneid = (int) $this->tree['zoneb']->id;
        $data->districtid = (int) $this->tree['districtb']->id;

        $errors = $this->service->validate_account_data($data, $this->actorid);
        $this->assertSame(
            get_string('error:hierarchymismatch', 'local_mohhierarchy'),
            $errors['facilityid'],
        );
        try {
            $this->service->create_user($data, $this->actorid);
            $this->fail('Mismatched hierarchy ids must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:usercreationinvalid', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('user', ['username' => 'mismatch.person']));
    }

    /**
     * A forged management scope can never be attached during account creation.
     */
    public function test_tampered_scope_is_rejected(): void {
        global $DB;

        $data = $this->valid_data('elevated.person');
        $data->scopelevel = scope_level::ZONE->value;

        $this->assertArrayHasKey(
            'facilityid',
            $this->service->validate_account_data($data, $this->actorid),
        );
        try {
            $this->service->create_user($data, $this->actorid);
            $this->fail('A submitted management scope must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:usercreationinvalid', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('user', ['username' => 'elevated.person']));
    }

    /**
     * Capability without a usable scope is insufficient.
     */
    public function test_creator_without_hierarchy_scope_is_rejected(): void {
        global $DB;

        (new assignment_service())->withdraw_assignment($this->actorid);
        $data = $this->valid_data('unscoped.person');

        $this->assertArrayHasKey(
            'facilityid',
            $this->service->validate_account_data($data, $this->actorid),
        );
        try {
            $this->service->create_user($data, $this->actorid);
            $this->fail('A creator with no active hierarchy scope must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:usercreationinvalid', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('user', ['username' => 'unscoped.person']));
    }

    /**
     * The rendered form receives only the actor's scope-filtered hierarchy options.
     */
    public function test_form_uses_shared_scope_filtered_selectors(): void {
        global $CFG, $PAGE;

        require_once($CFG->libdir . '/formslib.php');
        $PAGE->set_context(\context_system::instance());
        $url = new \moodle_url('/local/mohhierarchy/createuser.php');
        $PAGE->set_url($url);

        $form = new create_user_form($url, [
            'actorid' => $this->actorid,
            'service' => $this->service,
            'selectors' => new selector_options(),
        ]);
        $html = $form->render();

        $this->assertStringContainsString('Facility A', $html);
        $this->assertStringNotContainsString('Facility B', $html);
        $this->assertStringContainsString('Zone A', $html);
        $this->assertStringNotContainsString('Zone B', $html);
        $this->assertStringContainsString('mohhierarchy-selector', $html);
        $this->assertStringContainsString('data-fieldtype="autocomplete"', $html);
        $this->assertStringContainsString(get_string('createhierarchyuser', 'local_mohhierarchy'), $html);

        $adminform = new create_user_form($url, [
            'actorid' => (int) get_admin()->id,
            'service' => $this->service,
            'selectors' => new selector_options(),
        ]);
        $adminhtml = $adminform->render();
        $facilityposition = strpos($adminhtml, 'name="facilityid"');
        $districtposition = strpos($adminhtml, 'name="districtid"');
        $zoneposition = strpos($adminhtml, 'name="zoneid"');
        $this->assertNotFalse($facilityposition);
        $this->assertNotFalse($districtposition);
        $this->assertNotFalse($zoneposition);
        $this->assertLessThan($districtposition, $zoneposition);
        $this->assertLessThan($facilityposition, $districtposition);
    }

    /**
     * Valid strict-form data for the actor's own facility.
     *
     * @param string $username Unique username.
     * @return \stdClass
     */
    protected function valid_data(string $username = 'delegated.person'): \stdClass {
        return (object) [
            'username' => $username,
            'firstname' => 'Delegated',
            'lastname' => 'Person',
            'email' => $username . '@example.invalid',
            'auth' => 'manual',
            'createpassword' => 0,
            'newpassword' => 'ValidPassword!9274',
            'zoneid' => (int) $this->tree['zonea']->id,
            'districtid' => (int) $this->tree['districta']->id,
            'facilityid' => (int) $this->tree['facilitya']->id,
        ];
    }
}
