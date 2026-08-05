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

use local_mohhierarchy\local\assign_action;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_log_repository;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\sync_service;
use local_mohhierarchy\local\scope_level;

/**
 * Tests for the central assignment service.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(assignment_service::class)]
final class assignment_service_test extends \advanced_testcase {
    /** @var assignment_service The service under test. */
    protected assignment_service $service;

    /** @var assignment_log_repository History reads. */
    protected assignment_log_repository $log;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->service = new assignment_service();
        $this->log = new assignment_log_repository();
    }

    /**
     * The plugin test data generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }

    /**
     * Assigning derives the district and zone from the facility and stores the triple together.
     */
    public function test_assign_user_derives_the_hierarchy(): void {
        $zone = $this->generator()->create_zone();
        $district = $this->generator()->create_district(['zoneid' => $zone->id]);
        $facility = $this->generator()->create_facility(['districtid' => $district->id]);
        $user = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();

        $assignment = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::DISTRICT,
            (int) $actor->id,
            assign_source::USERFORM,
            'Initial placement',
        );

        $this->assertEquals($zone->id, $assignment->zoneid);
        $this->assertEquals($district->id, $assignment->districtid);
        $this->assertEquals($facility->id, $assignment->facilityid);
        $this->assertSame('district', $assignment->scopelevel);
        $this->assertSame('userform', $assignment->assignsource);
        $this->assertEquals($actor->id, $assignment->assignedby);
        $this->assertSame(1, $this->log->count_for_user((int) $user->id));
    }

    /**
     * A submitted zone or district cannot influence what is stored: the method does not accept them.
     */
    public function test_only_the_facility_decides_the_triple(): void {
        $wrongzone = $this->generator()->create_zone();
        $this->generator()->create_district(['zoneid' => $wrongzone->id]);
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $assignment = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
        );

        $ancestors = (new facility_repository())->get_with_ancestors((int) $facility->id);
        $this->assertEquals($ancestors->districtid, $assignment->districtid);
        $this->assertEquals($ancestors->zoneid, $assignment->zoneid);
        $this->assertNotEquals($wrongzone->id, $assignment->zoneid);
    }

    /**
     * Repeating the same call changes nothing and records no history.
     */
    public function test_assign_user_is_idempotent(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $first = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
            null,
            1000,
        );
        $second = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
            null,
            2000,
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1000, $second->timemodified, 'An unchanged assignment must not be restamped');
        $this->assertSame(1, $this->log->count_for_user((int) $user->id), 'No history entry for a no-op');
        $this->assertSame(1, $DB->count_records('local_mohh_assign'));
    }

    /**
     * A different source or actor alone is not a change worth recording.
     */
    public function test_changing_only_the_source_is_a_no_op(): void {
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();

        $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::NONE,
            null,
            assign_source::MIGRATION,
            null,
            1000
        );
        $again = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::NONE,
            (int) $actor->id,
            assign_source::ADMIN,
            null,
            2000
        );

        $this->assertSame('migration', $again->assignsource);
        $this->assertSame(1, $this->log->count_for_user((int) $user->id));
    }

    /**
     * A real change is recorded, with the before and after values.
     */
    public function test_a_real_change_is_recorded(): void {
        $first = $this->generator()->create_facility();
        $second = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $this->service->assign_user(
            (int) $user->id,
            (int) $first->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
            null,
            1000
        );
        $moved = $this->service->assign_user(
            (int) $user->id,
            (int) $second->id,
            scope_level::ZONE,
            null,
            assign_source::ADMIN,
            'Transferred',
            2000
        );

        $this->assertEquals($second->id, $moved->facilityid);
        $this->assertEquals($second->districtid, $moved->districtid);
        $this->assertSame('zone', $moved->scopelevel);
        $this->assertEquals(2000, $moved->timemodified);
        $this->assertSame(2, $this->log->count_for_user((int) $user->id));

        $entries = array_values($this->log->get_for_user((int) $user->id));
        $update = $entries[0];
        $this->assertSame(assign_action::UPDATED->value, $update->action);
        $this->assertSame('Transferred', $update->reason);
        $this->assertEquals($first->id, json_decode($update->olddata)->facilityid);
        $this->assertEquals($second->id, json_decode($update->newdata)->facilityid);
    }

    /**
     * Changing only the scope level is a change.
     */
    public function test_changing_the_scope_level_is_recorded(): void {
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
            null,
            1000
        );
        $promoted = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::DISTRICT,
            null,
            assign_source::ADMIN,
            null,
            2000
        );

        $this->assertSame('district', $promoted->scopelevel);
        $this->assertSame(2, $this->log->count_for_user((int) $user->id));
    }

    /**
     * A deleted user cannot be assigned.
     */
    public function test_deleted_user_is_rejected(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        delete_user($user);

        try {
            $this->service->assign_user((int) $user->id, (int) $facility->id, scope_level::FACILITY);
            $this->fail('Expected a deleted user to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:unknownuser', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_mohh_assign'));
    }

    /**
     * A facility id that does not exist is rejected.
     */
    public function test_unknown_facility_is_rejected(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        try {
            $this->service->assign_user((int) $user->id, 4242, scope_level::FACILITY);
            $this->fail('Expected an unknown facility to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:unknownfacility', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_mohh_assign'));
    }

    /**
     * An inactive facility cannot take a new assignment.
     */
    public function test_inactive_facility_is_rejected_for_a_new_assignment(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $facility->id]);
        $user = $this->getDataGenerator()->create_user();

        try {
            $this->service->assign_user((int) $user->id, (int) $facility->id, scope_level::FACILITY);
            $this->fail('Expected an inactive facility to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:inactivefacility', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_mohh_assign'));
    }

    /**
     * Someone already at a facility that has since been withdrawn can still be edited.
     */
    public function test_existing_assignment_at_an_inactive_facility_can_still_be_edited(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
            null,
            1000
        );

        // The facility disappears upstream and is deactivated by a later sync.
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $facility->id]);

        $corrected = $this->service->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::NONE,
            null,
            assign_source::ADMIN,
            'Scope corrected',
            2000
        );
        $this->assertSame('none', $corrected->scopelevel);

        // Moving that user to another inactive facility is still refused.
        $other = $this->generator()->create_facility();
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $other->id]);
        $this->expectException(\moodle_exception::class);
        $this->service->assign_user((int) $user->id, (int) $other->id, scope_level::NONE);
    }

    /**
     * The assignment can be read back with the hierarchy names attached.
     */
    public function test_get_assignment_with_names(): void {
        $zone = $this->generator()->create_zone(['name' => 'Central West Zone']);
        $district = $this->generator()->create_district(['zoneid' => $zone->id, 'name' => 'Dedza']);
        $facility = $this->generator()->create_facility([
            'districtid' => $district->id,
            'name' => 'Dedza District Hospital',
            'code' => 'MC010001',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->service->assign_user((int) $user->id, (int) $facility->id, scope_level::DISTRICT);

        $stored = $this->service->get_assignment_with_names((int) $user->id);
        $this->assertNotNull($stored);
        $this->assertSame('Central West Zone', $stored->zonename);
        $this->assertSame('Dedza', $stored->districtname);
        $this->assertSame('Dedza District Hospital', $stored->facilityname);
        $this->assertSame('MC010001', $stored->facilitycode);
        $this->assertEquals(1, $stored->facilityactive);
        $this->assertSame('district', $stored->scopelevel);

        $other = $this->getDataGenerator()->create_user();
        $this->assertNull($this->service->get_assignment_with_names((int) $other->id));
    }

    /**
     * A concurrent write to the same user is rejected instead of losing an update.
     */
    public function test_assignment_lock_prevents_concurrent_update(): void {
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $factory = \core\lock\lock_config::get_lock_factory(assignment_service::LOCK_TYPE);
        $held = $factory->get_lock(assignment_service::LOCK_PREFIX . $user->id, 0);
        $this->assertNotFalse($held);

        try {
            $service = new assignment_service(null, null, $factory);
            $service->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);
            $this->fail('A concurrent assignment update must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:assignmentlocked', $e->errorcode);
        } finally {
            $held->release();
        }

        $this->assertNull($this->service->get_assignment((int) $user->id));
    }

    /**
     * Assignment writes do not race a hierarchy synchronisation.
     */
    public function test_sync_lock_prevents_assignment_write(): void {
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $factory = \core\lock\lock_config::get_lock_factory(assignment_service::LOCK_TYPE);
        $held = $factory->get_lock(sync_service::LOCK_RESOURCE, 0);
        $this->assertNotFalse($held);

        try {
            $service = new assignment_service(null, null, $factory);
            $service->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);
            $this->fail('An assignment must not race hierarchy synchronisation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:hierarchywritebusy', $e->errorcode);
        } finally {
            $held->release();
        }

        $this->assertNull($this->service->get_assignment((int) $user->id));
    }

    /**
     * Withdrawing keeps the row and the history, and is itself idempotent.
     */
    public function test_withdraw_assignment(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $this->service->assign_user((int) $user->id, (int) $facility->id, scope_level::FACILITY);

        $this->assertTrue($this->service->withdraw_assignment((int) $user->id, null, 'Left the service'));
        $this->assertSame(1, $DB->count_records('local_mohh_assign'));
        $this->assertEquals(0, $this->service->get_assignment((int) $user->id)->active);
        $this->assertSame(2, $this->log->count_for_user((int) $user->id));

        $this->assertFalse($this->service->withdraw_assignment((int) $user->id));
        $this->assertSame(2, $this->log->count_for_user((int) $user->id), 'A second withdrawal records nothing');
    }
}
