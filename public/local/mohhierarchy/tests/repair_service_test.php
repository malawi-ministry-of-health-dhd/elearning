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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_mohhierarchy;

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\repair_service;
use local_mohhierarchy\local\scope_level;

/**
 * Tests every consistency state handled by the repair service.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(repair_service::class)]
final class repair_service_test extends \advanced_testcase {
    /** @var repair_service Service under test. */
    protected repair_service $service;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->service = new repair_service();
    }

    /**
     * A valid legacy profile value creates a no-scope repair assignment.
     */
    public function test_custom_only_is_repaired_with_none_scope(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $this->set_profile((int) $user->id, (string) $facility->id);

        $dryrun = $this->service->repair(true, (int) $user->id);
        $this->assert_issue($dryrun, 'custom_only', true);
        $this->assertFalse($DB->record_exists('local_mohh_assign', ['userid' => $user->id]));

        $this->service->repair(false, (int) $user->id);
        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(scope_level::NONE->value, $assignment->scopelevel);
        $this->assertSame(assign_source::REPAIR->value, $assignment->assignsource);
        $this->assertEquals($facility->id, $assignment->facilityid);
    }

    /**
     * An empty mirror is restored without changing canonical assignment provenance.
     */
    public function test_assignment_with_empty_profile_is_repaired(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            null,
            assign_source::ADMIN,
        );

        $report = $this->service->repair(false, (int) $user->id);
        $this->assert_issue($report, 'assignment_profile_empty', true);
        $this->assertSame((string) $facility->id, $this->profile_value((int) $user->id));
        $this->assertSame(
            assign_source::ADMIN->value,
            $DB->get_field('local_mohh_assign', 'assignsource', ['userid' => $user->id]),
        );
        $this->assertSame(2, $DB->count_records('local_mohh_assignlog', ['userid' => $user->id]));
    }

    /**
     * A facility conflict is review-only and neither store moves.
     */
    public function test_facility_conflict_is_never_repaired(): void {
        global $DB;

        $canonical = $this->generator()->create_facility();
        $different = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user(
            (int) $user->id,
            (int) $canonical->id,
            scope_level::NONE,
        );
        $this->set_profile((int) $user->id, (string) $different->id);

        $report = $this->service->repair(false, (int) $user->id);
        $this->assert_issue($report, 'facility_conflict', false);
        $this->assertEquals(
            $canonical->id,
            $DB->get_field('local_mohh_assign', 'facilityid', ['userid' => $user->id]),
        );
        $this->assertSame((string) $different->id, $this->profile_value((int) $user->id));
    }

    /**
     * Both derived ancestry mismatch states are detected and repaired from the same facility.
     */
    public function test_zone_and_district_mismatches_are_rederived(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $otherfacility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);
        $this->set_profile((int) $user->id, (string) $facility->id);
        $otherpath = (new \local_mohhierarchy\local\hierarchy\facility_repository())
            ->get_with_ancestors((int) $otherfacility->id);
        $canonicalpath = (new \local_mohhierarchy\local\hierarchy\facility_repository())
            ->get_with_ancestors((int) $facility->id);
        $DB->set_field('local_mohh_assign', 'zoneid', $canonicalpath->zoneid, ['userid' => $user->id]);
        $DB->set_field('local_mohh_assign', 'districtid', $otherpath->districtid, ['userid' => $user->id]);

        $scan = $this->service->scan((int) $user->id);
        $this->assert_issue($scan, 'zone_district_mismatch', true);
        $this->assert_issue($scan, 'district_facility_mismatch', true);

        $this->service->repair(false, (int) $user->id);
        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $user->id], '*', MUST_EXIST);
        $path = (new \local_mohhierarchy\local\hierarchy\facility_repository())
            ->get_with_ancestors((int) $facility->id);
        $this->assertEquals($path->zoneid, $assignment->zoneid);
        $this->assertEquals($path->districtid, $assignment->districtid);
    }

    /**
     * Inactive hierarchy references block automatic repair.
     */
    public function test_inactive_reference_is_reported(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);
        $this->set_profile((int) $user->id, (string) $facility->id);
        $DB->set_field('local_mohh_facility', 'active', 0, ['id' => $facility->id]);

        $this->assert_issue($this->service->scan((int) $user->id), 'inactive_reference', false);
    }

    /**
     * A missing reference is detected rather than hidden by an inner join.
     */
    public function test_missing_reference_is_reported(): void {
        global $DB;

        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);
        $this->set_profile((int) $user->id, (string) $facility->id);
        $DB->set_field('local_mohh_assign', 'zoneid', 99999999, ['userid' => $user->id]);

        $this->assert_issue($this->service->scan((int) $user->id), 'missing_reference', false);
    }

    /**
     * Unexpected multiple field instances disable all automatic actions.
     */
    public function test_multiple_field_instances_are_reported(): void {
        global $DB;

        $field = $this->field();
        $DB->insert_record('user_info_field', (object) [
            'shortname' => 'mohfacilityduplicate',
            'name' => 'Duplicate hierarchy',
            'datatype' => repair_service::DATATYPE,
            'categoryid' => $field->categoryid,
        ]);

        $report = $this->service->scan();
        $this->assertSame(2, $report['fieldcount']);
        $this->assert_issue($report, 'multiple_fields', false);
    }

    /**
     * Find an issue and assert its repair status.
     *
     * @param array $report Service report.
     * @param string $code Issue code.
     * @param bool $repairable Expected safe status.
     * @return void
     */
    protected function assert_issue(array $report, string $code, bool $repairable): void {
        $matches = array_values(array_filter(
            $report['issues'],
            static fn(array $issue): bool => $issue['code'] === $code,
        ));
        $this->assertNotEmpty($matches, "Issue {$code} was not reported");
        $this->assertSame($repairable, $matches[0]['repairable']);
    }

    /**
     * Store the companion profile value.
     *
     * @param int $userid User id.
     * @param string $value Value.
     * @return void
     */
    protected function set_profile(int $userid, string $value): void {
        global $DB;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $this->field()->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * Read the profile mirror.
     *
     * @param int $userid User id.
     * @return string
     */
    protected function profile_value(int $userid): string {
        global $DB;

        return (string) $DB->get_field('user_info_data', 'data', [
            'userid' => $userid,
            'fieldid' => $this->field()->id,
        ]);
    }

    /**
     * The installed hierarchy profile field.
     *
     * @return \stdClass
     */
    protected function field(): \stdClass {
        global $DB;

        return $DB->get_record(
            'user_info_field',
            ['datatype' => repair_service::DATATYPE],
            '*',
            MUST_EXIST,
        );
    }

    /**
     * Plugin generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }
}
