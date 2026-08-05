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

use local_mohhierarchy\local\hierarchy\district_repository;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\zone_repository;

/**
 * Tests for the zone, district and facility repositories.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(
    \local_mohhierarchy\local\hierarchy\reference_repository::class,
)]
#[\PHPUnit\Framework\Attributes\CoversClass(zone_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(district_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(facility_repository::class)]
final class hierarchy_repository_test extends \advanced_testcase {
    /**
     * The plugin test data generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }

    /**
     * A zone's remote identifier is unique at database level, not just in application code.
     */
    public function test_zone_externalid_is_unique(): void {
        global $DB;

        $this->resetAfterTest();
        $this->generator()->create_zone(['externalid' => 4242]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_mohh_zone', (object) [
            'externalid' => 4242,
            'name' => 'Duplicate zone',
            'active' => 1,
            'lastseen' => 0,
            'timecreated' => 0,
            'timemodified' => 0,
        ]);
    }

    /**
     * A district's remote identifier is unique at database level.
     */
    public function test_district_externalid_is_unique(): void {
        global $DB;

        $this->resetAfterTest();
        $district = $this->generator()->create_district(['externalid' => 4343]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_mohh_district', (object) [
            'externalid' => 4343,
            'zoneid' => $district->zoneid,
            'name' => 'Duplicate district',
            'active' => 1,
            'lastseen' => 0,
            'timecreated' => 0,
            'timemodified' => 0,
        ]);
    }

    /**
     * A facility's remote identifier is unique at database level.
     */
    public function test_facility_externalid_is_unique(): void {
        global $DB;

        $this->resetAfterTest();
        $facility = $this->generator()->create_facility(['externalid' => 4444]);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_mohh_facility', (object) [
            'externalid' => 4444,
            'districtid' => $facility->districtid,
            'name' => 'Duplicate facility',
            'active' => 1,
            'lastseen' => 0,
            'timecreated' => 0,
            'timemodified' => 0,
        ]);
    }

    /**
     * Upserting the same remote identifier refreshes the existing row instead of adding another.
     */
    public function test_upsert_matches_on_externalid(): void {
        global $DB;

        $this->resetAfterTest();
        $repository = new zone_repository();

        $first = $repository->upsert((object) ['externalid' => 11, 'name' => 'North', 'description' => null], 1000);
        $this->assertTrue($first['created']);
        $this->assertFalse($first['updated']);

        $second = $repository->upsert((object) ['externalid' => 11, 'name' => 'North', 'description' => null], 2000);
        $this->assertFalse($second['created']);
        $this->assertFalse($second['updated'], 'An unchanged row should not be reported as updated');
        $this->assertSame($first['id'], $second['id']);

        $third = $repository->upsert((object) ['externalid' => 11, 'name' => 'Far North', 'description' => 'x'], 3000);
        $this->assertTrue($third['updated']);
        $this->assertSame(1, $DB->count_records('local_mohh_zone'));

        $stored = $repository->get_by_id($first['id']);
        $this->assertSame('Far North', $stored->name);
        $this->assertEquals(3000, $stored->lastseen);
        $this->assertEquals(3000, $stored->timemodified);
        $this->assertEquals(1000, $stored->timecreated);
    }

    /**
     * Rows the remote source stops publishing are deactivated, never deleted.
     */
    public function test_deactivate_not_seen_since_keeps_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $repository = new zone_repository();
        $stale = $repository->upsert((object) ['externalid' => 21, 'name' => 'Gone', 'description' => null], 1000);
        $fresh = $repository->upsert((object) ['externalid' => 22, 'name' => 'Here', 'description' => null], 5000);

        $this->assertSame(1, $repository->deactivate_not_seen_since(5000, 6000));
        $this->assertSame(2, $DB->count_records('local_mohh_zone'), 'Rows must never be deleted');
        $this->assertEquals(0, $repository->get_by_id($stale['id'])->active);
        $this->assertEquals(1, $repository->get_by_id($fresh['id'])->active);
        $this->assertSame(1, $repository->count());
        $this->assertSame(2, $repository->count(false));

        // A row that reappears remotely is reactivated rather than duplicated.
        $again = $repository->upsert((object) ['externalid' => 21, 'name' => 'Gone', 'description' => null], 7000);
        $this->assertSame($stale['id'], $again['id']);
        $this->assertEquals(1, $repository->get_by_id($stale['id'])->active);
    }

    /**
     * A district belongs to exactly one zone, and facilities hang off exactly one district.
     */
    public function test_relationships_are_navigable_in_both_directions(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $districts = new district_repository();
        $facilities = new facility_repository();

        $zone = $generator->create_zone(['name' => 'Central']);
        $otherzone = $generator->create_zone(['name' => 'Southern']);
        $district = $generator->create_district(['zoneid' => $zone->id, 'name' => 'Lilongwe']);
        $otherdistrict = $generator->create_district(['zoneid' => $otherzone->id]);
        $facility = $generator->create_facility(['districtid' => $district->id, 'name' => 'Area 25 Health Centre']);

        $this->assertEquals($zone->id, $district->zoneid);
        $this->assertEquals($district->id, $facility->districtid);

        $this->assertArrayHasKey($district->id, $districts->get_by_zone((int) $zone->id));
        $this->assertArrayNotHasKey($otherdistrict->id, $districts->get_by_zone((int) $zone->id));
        $this->assertArrayHasKey($facility->id, $facilities->get_by_district((int) $district->id));
        $this->assertSame([], $facilities->get_by_district((int) $otherdistrict->id));

        $this->assertTrue($districts->belongs_to_zone((int) $district->id, (int) $zone->id));
        $this->assertFalse($districts->belongs_to_zone((int) $district->id, (int) $otherzone->id));
        $this->assertTrue($facilities->belongs_to_district((int) $facility->id, (int) $district->id));
        $this->assertFalse($facilities->belongs_to_district((int) $facility->id, (int) $otherdistrict->id));
    }

    /**
     * A facility resolves to its district and zone in one query.
     */
    public function test_get_with_ancestors(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $facilities = new facility_repository();

        $zone = $generator->create_zone(['name' => 'Central']);
        $district = $generator->create_district(['zoneid' => $zone->id, 'name' => 'Kasungu']);
        $facility = $generator->create_facility(['districtid' => $district->id, 'name' => 'Kasungu District Hospital']);

        $ancestors = $facilities->get_with_ancestors((int) $facility->id);
        $this->assertNotNull($ancestors);
        $this->assertEquals($facility->id, $ancestors->facilityid);
        $this->assertEquals($district->id, $ancestors->districtid);
        $this->assertEquals($zone->id, $ancestors->zoneid);
        $this->assertSame('Kasungu', $ancestors->districtname);
        $this->assertSame('Central', $ancestors->zonename);

        $this->assertNull($facilities->get_with_ancestors((int) $facility->id + 1000));
    }

    /**
     * Remote identifiers map onto local ids in bulk.
     */
    public function test_get_id_map(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new zone_repository();

        $first = $generator->create_zone(['externalid' => 31]);
        $second = $generator->create_zone(['externalid' => 32]);

        $map = $repository->get_id_map([31, 32, 33]);
        $this->assertSame([31 => (int) $first->id, 32 => (int) $second->id], $map);
        $this->assertSame([], $repository->get_id_map([]));
    }

    /**
     * The facility code mapping is stored as validated JSON and read back as an array.
     */
    public function test_code_mapping_json_round_trip(): void {
        $this->resetAfterTest();
        $mapping = ['dhis2' => 'ABC123', 'openlmis' => 'OL-9'];

        $json = facility_repository::encode_code_mapping($mapping);
        $this->assertJson($json);

        $facility = $this->generator()->create_facility(['codemappingjson' => $json]);
        $this->assertSame($mapping, facility_repository::decode_code_mapping($facility->codemappingjson));

        $this->assertNull(facility_repository::encode_code_mapping(null));
        $this->assertNull(facility_repository::encode_code_mapping([]));
        $this->assertSame([], facility_repository::decode_code_mapping(null));
        $this->assertSame([], facility_repository::decode_code_mapping('not json'));
    }
}
