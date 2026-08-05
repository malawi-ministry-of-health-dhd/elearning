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

use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\source\mapper;

/**
 * Tests for the remote to local field mapping.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mapper::class)]
final class mapper_test extends \advanced_testcase {
    /**
     * Decode a fixture file into remote items.
     *
     * @param string $name File name inside tests/fixtures.
     * @return array[]
     */
    protected function items(string $name): array {
        global $CFG;

        return json_decode(file_get_contents($CFG->dirroot . '/local/mohhierarchy/tests/fixtures/' . $name), true);
    }

    /**
     * A zone maps onto externalid, name and description.
     */
    public function test_zone_mapping(): void {
        $zones = mapper::map_zones($this->items('zones.json'));

        $this->assertSame([1, 2], array_keys($zones));
        $this->assertSame(1, $zones[1]->externalid);
        $this->assertSame('Central East Zone', $zones[1]->name);
        $this->assertNull($zones[1]->description, 'An explicit null description stays null');
        $this->assertSame('Covers the central west districts.', $zones[2]->description);
    }

    /**
     * A district keeps its remote zone reference for the sync service to resolve.
     */
    public function test_district_mapping(): void {
        $districts = mapper::map_districts($this->items('districts.json'));

        $this->assertSame([3, 4], array_keys($districts));
        $this->assertSame(3, $districts[3]->externalid);
        $this->assertSame(2, $districts[3]->zoneexternalid);
        $this->assertSame('Dedza', $districts[3]->name);
        $this->assertSame('DE', $districts[3]->code);
        $this->assertNull($districts[4]->code, 'An explicit null district_code stays null');
    }

    /**
     * Every supplied facility property lands in its local column.
     */
    public function test_facility_mapping(): void {
        $facilities = mapper::map_facilities($this->items('facilities.json'));
        $facility = $facilities[1];

        $this->assertSame(1, $facility->externalid);
        $this->assertSame(3, $facility->districtexternalid);
        $this->assertSame('Dedza District Hospital', $facility->name);
        $this->assertSame('Dedza DH', $facility->commonname);
        $this->assertSame('MC010001', $facility->code);
        $this->assertNull($facility->codedhis2);
        $this->assertNull($facility->codeopenlmis);
        $this->assertSame('Reg No.', $facility->registrationnumber);
        $this->assertSame(9, $facility->facilitytypeid);
        $this->assertSame(6, $facility->ownerid);
        $this->assertSame(1, $facility->operationalstatusid);
        $this->assertSame(1, $facility->regulatorystatusid);

        // Dates arrive as ISO 8601 with a Z offset and are stored as Unix timestamps.
        $this->assertSame(strtotime('1975-01-01T00:00:00+00:00'), $facility->dateopened);
        $this->assertSame(strtotime('2019-06-25T14:40:37+00:00'), $facility->publishedat);
        $this->assertSame(strtotime('2019-06-25T14:40:37+00:00'), $facility->remotecreatedat);
        $this->assertSame(strtotime('2019-06-25T14:40:37+00:00'), $facility->remoteupdatedat);

        $this->assertSame(
            [['url' => '', 'code' => 'DHIS2 CODE', 'system' => 'DHIS2']],
            facility_repository::decode_code_mapping($facility->codemappingjson),
        );
    }

    /**
     * Properties the remote service simply omits become null, not empty strings or zeroes.
     */
    public function test_missing_optional_properties_become_null(): void {
        $facilities = mapper::map_facilities($this->items('facilities.json'));
        $minimal = $facilities[2];

        $this->assertSame('Salima Health Post', $minimal->name);
        $this->assertSame(4, $minimal->districtexternalid);
        foreach (
            [
            'code', 'codedhis2', 'codeopenlmis', 'commonname', 'registrationnumber', 'facilitytypeid',
            'ownerid', 'operationalstatusid', 'regulatorystatusid', 'dateopened', 'publishedat',
            'remotecreatedat', 'remoteupdatedat', 'codemappingjson',
            ] as $field
        ) {
            $this->assertNull($minimal->{$field}, "{$field} should be null when the property is absent");
        }
    }

    /**
     * Every dataset refuses a payload that repeats a remote id.
     */
    public function test_duplicate_remote_ids_are_rejected(): void {
        try {
            mapper::map_zones($this->items('zones_duplicate_id.json'));
            $this->fail('Expected a duplicate remote id to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:duplicateexternalid', $e->errorcode);
            $this->assertStringContainsString('zones', $e->getMessage());
        }
    }

    /**
     * A zone with no zone_name is refused.
     */
    public function test_zone_without_name_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/zone_name/');
        mapper::map_zones($this->items('zones_missing_name.json'));
    }

    /**
     * A district with no zone_id is refused.
     */
    public function test_district_without_zone_id_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/zone_id/');
        mapper::map_districts($this->items('districts_missing_zone.json'));
    }

    /**
     * Records with no usable id are refused, whichever dataset they came from.
     *
     * @param string $dataset Mapper method suffix.
     * @param array $item A single remote item.
     * @param string $expectedfield The property named in the failure.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_required_provider')]
    public function test_required_properties_are_enforced(string $dataset, array $item, string $expectedfield): void {
        $method = 'map_' . $dataset;

        try {
            mapper::{$method}([$item]);
            $this->fail("Expected {$dataset} record to be rejected");
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingfield', $e->errorcode);
            $this->assertStringContainsString($expectedfield, $e->getMessage());
        }
    }

    /**
     * Cases for test_required_properties_are_enforced.
     *
     * @return array[]
     */
    public static function missing_required_provider(): array {
        return [
            'zone without id' => ['zones', ['zone_name' => 'Zone'], 'id'],
            'zone with zero id' => ['zones', ['id' => 0, 'zone_name' => 'Zone'], 'id'],
            'zone with non numeric id' => ['zones', ['id' => 'abc', 'zone_name' => 'Zone'], 'id'],
            'zone with empty name' => ['zones', ['id' => 1, 'zone_name' => '   '], 'zone_name'],
            'district without id' => ['districts', ['district_name' => 'D', 'zone_id' => 1], 'id'],
            'district without name' => ['districts', ['id' => 1, 'zone_id' => 1], 'district_name'],
            'district without zone' => ['districts', ['id' => 1, 'district_name' => 'D'], 'zone_id'],
            'facility without id' => ['facilities', ['facility_name' => 'F', 'district_id' => 1], 'id'],
            'facility without name' => ['facilities', ['id' => 1, 'district_id' => 1], 'facility_name'],
            'facility without district' => ['facilities', ['id' => 1, 'facility_name' => 'F'], 'district_id'],
        ];
    }

    /**
     * Numeric ids arriving as strings are accepted, since JSON APIs are inconsistent about this.
     */
    public function test_numeric_string_ids_are_accepted(): void {
        $zones = mapper::map_zones([['id' => '7', 'zone_name' => 'Seven']]);
        $this->assertSame([7], array_keys($zones));
        $this->assertSame(7, $zones[7]->externalid);
    }

    /**
     * An unparseable date becomes null rather than failing the whole run.
     */
    public function test_unparseable_dates_become_null(): void {
        $facilities = mapper::map_facilities([[
            'id' => 1,
            'facility_name' => 'F',
            'district_id' => 1,
            'facility_date_opened' => 'not a date',
            'published_date' => '',
        ]]);

        $this->assertNull($facilities[1]->dateopened);
        $this->assertNull($facilities[1]->publishedat);
    }

    /**
     * Over-long values are trimmed to the local column width rather than failing the write.
     */
    public function test_values_are_trimmed_to_the_column_width(): void {
        $facilities = mapper::map_facilities([[
            'id' => 1,
            'facility_name' => str_repeat('n', 300),
            'facility_code' => str_repeat('c', 200),
            'district_id' => 1,
        ]]);

        $this->assertSame(255, \core_text::strlen($facilities[1]->name));
        $this->assertSame(100, \core_text::strlen($facilities[1]->code));
    }

    /**
     * The properties with no local column are dropped, not smuggled in as extra fields.
     */
    public function test_unmapped_remote_properties_are_dropped(): void {
        $facilities = mapper::map_facilities($this->items('facilities.json'));
        $stored = (array) $facilities[1];

        $this->assertArrayNotHasKey('client_id', $stored);
        $this->assertArrayNotHasKey('archived_date', $stored);
    }
}
