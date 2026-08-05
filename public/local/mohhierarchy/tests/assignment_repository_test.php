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
use local_mohhierarchy\local\hierarchy\assignment_repository;
use local_mohhierarchy\local\hierarchy\district_repository;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\scope_level;

/**
 * Tests for the canonical assignment table and its history.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(assignment_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assignment_log_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scope_level::class)]
final class assignment_repository_test extends \advanced_testcase {
    /**
     * The plugin test data generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }

    /**
     * Saving an assignment stores a consistent triple derived from the facility.
     */
    public function test_save_derives_zone_and_district_from_facility(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new assignment_repository();

        $zone = $generator->create_zone();
        $district = $generator->create_district(['zoneid' => $zone->id]);
        $facility = $generator->create_facility(['districtid' => $district->id]);
        $user = $this->getDataGenerator()->create_user();
        $admin = get_admin();

        $assignment = $repository->save(
            (int) $user->id,
            (int) $facility->id,
            scope_level::DISTRICT,
            assign_source::USERFORM,
            (int) $admin->id,
        );

        $this->assertEquals($zone->id, $assignment->zoneid);
        $this->assertEquals($district->id, $assignment->districtid);
        $this->assertEquals($facility->id, $assignment->facilityid);
        $this->assertSame('district', $assignment->scopelevel);
        $this->assertSame('userform', $assignment->assignsource);
        $this->assertEquals($admin->id, $assignment->assignedby);
        $this->assertTrue($repository->is_consistent($assignment));
        $this->assertSame(scope_level::DISTRICT, $repository->get_scope_level((int) $user->id));
    }

    /**
     * A user has at most one assignment: a second save updates the same row.
     */
    public function test_save_updates_the_single_row_per_user(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new assignment_repository();

        $first = $generator->create_facility();
        $second = $generator->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $created = $repository->save(
            (int) $user->id,
            (int) $first->id,
            scope_level::FACILITY,
            assign_source::USERFORM,
            null,
            null,
            1000,
        );
        $updated = $repository->save(
            (int) $user->id,
            (int) $second->id,
            scope_level::ZONE,
            assign_source::ADMIN,
            null,
            null,
            2000,
        );

        $this->assertSame($created->id, $updated->id);
        $this->assertSame(1, $DB->count_records('local_mohh_assign', ['userid' => $user->id]));
        $this->assertEquals($second->id, $updated->facilityid);
        $this->assertEquals($second->districtid, $updated->districtid);
        $this->assertEquals(1000, $updated->timecreated, 'timecreated must survive an update');
        $this->assertEquals(2000, $updated->timemodified);
    }

    /**
     * The one-assignment-per-user rule is enforced by the database, not only by application code.
     */
    public function test_userid_is_unique_at_database_level(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->generator();
        $facility = $generator->create_facility();
        $user = $this->getDataGenerator()->create_user();

        (new assignment_repository())->save(
            (int) $user->id,
            (int) $facility->id,
            scope_level::NONE,
            assign_source::MIGRATION,
        );

        $zoneid = $DB->get_field('local_mohh_district', 'zoneid', ['id' => $facility->districtid]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_mohh_assign', (object) [
            'userid' => $user->id,
            'zoneid' => $zoneid,
            'districtid' => $facility->districtid,
            'facilityid' => $facility->id,
            'scopelevel' => 'none',
            'assignsource' => 'admin',
            'active' => 1,
            'timecreated' => 0,
            'timemodified' => 0,
        ]);
    }

    /**
     * A user with no assignment is a valid, fully working user.
     */
    public function test_user_without_assignment_is_supported(): void {
        $this->resetAfterTest();
        $repository = new assignment_repository();
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull($repository->get_for_user((int) $user->id));
        $this->assertNull($repository->get_active_for_user((int) $user->id));
        $this->assertSame(scope_level::NONE, $repository->get_scope_level((int) $user->id));
        $this->assertFalse($repository->deactivate((int) $user->id));
        $this->assertSame(0, $repository->count());
    }

    /**
     * An unknown facility is rejected before anything is written.
     */
    public function test_save_rejects_unknown_facility(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        try {
            (new assignment_repository())->save(
                (int) $user->id,
                987654,
                scope_level::FACILITY,
                assign_source::ADMIN,
            );
            $this->fail('Expected a moodle_exception for an unknown facility');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('987654', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_mohh_assign'));
        $this->assertSame(0, $DB->count_records('local_mohh_assignlog'));
    }

    /**
     * An unknown or deleted user is rejected before anything is written.
     */
    public function test_save_rejects_unknown_user(): void {
        global $DB;

        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        delete_user($user);

        $this->expectException(\moodle_exception::class);
        try {
            (new assignment_repository())->save(
                (int) $user->id,
                (int) $facility->id,
                scope_level::FACILITY,
                assign_source::ADMIN,
            );
        } finally {
            $this->assertSame(0, $DB->count_records('local_mohh_assign'));
        }
    }

    /**
     * History accumulates and is never overwritten.
     */
    public function test_history_is_append_only(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new assignment_repository();
        $log = new assignment_log_repository();

        $first = $generator->create_facility();
        $second = $generator->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $repository->save((int) $user->id, (int) $first->id, scope_level::FACILITY, assign_source::USERFORM);
        $this->assertSame(1, $log->count_for_user((int) $user->id));

        $repository->save((int) $user->id, (int) $second->id, scope_level::DISTRICT, assign_source::ADMIN);
        $this->assertSame(2, $log->count_for_user((int) $user->id));

        $repository->deactivate((int) $user->id, null, 'Left the service');
        $this->assertSame(3, $log->count_for_user((int) $user->id));

        $entries = array_values($log->get_for_user((int) $user->id));
        $actions = array_column($entries, 'action');
        $this->assertContains(assign_action::CREATED->value, $actions);
        $this->assertContains(assign_action::UPDATED->value, $actions);
        $this->assertContains(assign_action::DEACTIVATED->value, $actions);

        // The creation entry still records the original facility, unchanged by later saves.
        $creation = array_values(array_filter(
            $entries,
            fn(\stdClass $entry): bool => $entry->action === assign_action::CREATED->value,
        ))[0];
        $this->assertNull($creation->olddata);
        $this->assertEquals($first->id, json_decode($creation->newdata)->facilityid);
    }

    /**
     * Withdrawing an assignment keeps the row and records why.
     */
    public function test_deactivate_keeps_the_row(): void {
        global $DB;

        $this->resetAfterTest();
        $repository = new assignment_repository();
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();

        $repository->save((int) $user->id, (int) $facility->id, scope_level::FACILITY, assign_source::USERFORM);
        $this->assertTrue($repository->deactivate((int) $user->id, null, 'Transferred out'));

        $this->assertSame(1, $DB->count_records('local_mohh_assign', ['userid' => $user->id]));
        $this->assertNotNull($repository->get_for_user((int) $user->id));
        $this->assertNull($repository->get_active_for_user((int) $user->id));
        $this->assertSame(0, $repository->count());
        $this->assertSame(1, $repository->count(false));

        $entries = $DB->get_records('local_mohh_assignlog', ['action' => assign_action::DEACTIVATED->value]);
        $entry = reset($entries);
        $this->assertSame('Transferred out', $entry->reason);
        $this->assertNull($entry->newdata);
    }

    /**
     * Drift between an assignment and the reference tables is detectable and repairable.
     */
    public function test_inconsistent_assignments_are_detected_and_repairable(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new assignment_repository();
        $districts = new district_repository();

        $facility = $generator->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $assignment = $repository->save(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            assign_source::USERFORM,
        );
        $this->assertSame([], $repository->get_inconsistent());

        // The remote source moves the facility into a different district, in a different zone.
        $newdistrict = $generator->create_district();
        $moved = clone $facility;
        $moved->districtid = $newdistrict->id;
        (new facility_repository())->upsert($moved);

        $inconsistent = $repository->get_inconsistent();
        $this->assertArrayHasKey($assignment->id, $inconsistent);
        $this->assertFalse($repository->is_consistent($repository->get_for_user((int) $user->id)));

        $repaired = $repository->repair((int) $user->id, (int) $facility->id, scope_level::FACILITY, 'Facility moved');
        $this->assertEquals($newdistrict->id, $repaired->districtid);
        $this->assertEquals($districts->get_by_id((int) $newdistrict->id)->zoneid, $repaired->zoneid);
        $this->assertSame('repair', $repaired->assignsource);
        $this->assertSame([], $repository->get_inconsistent());

        $entries = (new assignment_log_repository())->get_for_user((int) $user->id);
        $this->assertContains(assign_action::REPAIRED->value, array_column(array_values($entries), 'action'));
    }

    /**
     * Only the four documented scope levels are accepted.
     */
    public function test_scope_levels_are_restricted(): void {
        $this->assertSame(['none', 'facility', 'district', 'zone'], scope_level::values());
        $this->assertSame(scope_level::ZONE, scope_level::from_value('zone'));
        $this->assertFalse(scope_level::NONE->grants_management());
        $this->assertTrue(scope_level::FACILITY->grants_management());

        $this->expectException(\moodle_exception::class);
        scope_level::from_value('national');
    }

    /**
     * Only the five documented assignment sources are accepted.
     */
    public function test_assign_sources_are_restricted(): void {
        $this->assertSame(['userform', 'admin', 'migration', 'repair', 'cli'], assign_source::values());

        $this->expectException(\moodle_exception::class);
        assign_source::from_value('guesswork');
    }
}
