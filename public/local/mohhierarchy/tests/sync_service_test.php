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
use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\assignment_repository;
use local_mohhierarchy\local\hierarchy\district_repository;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\sync_log_repository;
use local_mohhierarchy\local\hierarchy\sync_service;
use local_mohhierarchy\local\hierarchy\zone_repository;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\local\source\zipatala_client;
use local_mohhierarchy\local\sync_trigger;

/**
 * Tests for the synchronisation engine.
 *
 * No test here touches the network: the HTTP transport is replaced with a fixture player.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sync_service::class)]
final class sync_service_test extends \advanced_testcase {
    /** @var string Base URL used by every test here. Never resolved: the transport is faked. */
    protected const BASE = 'https://zipatala.example.invalid/api';

    /** @var config The configuration under test. */
    protected config $config;

    /** @var \local_mohhierarchy_fake_transport The fixture player. */
    protected \local_mohhierarchy_fake_transport $transport;

    /** @var zone_repository Zone reads. */
    protected zone_repository $zones;

    /** @var district_repository District reads. */
    protected district_repository $districts;

    /** @var facility_repository Facility reads. */
    protected facility_repository $facilities;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/local/mohhierarchy/tests/fixtures/fake_transport.php');
        parent::setUpBeforeClass();
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->config = new config(self::BASE);
        $this->transport = new \local_mohhierarchy_fake_transport([
            $this->config->zones_url() => $this->fixture('zones.json'),
            $this->config->districts_url() => $this->fixture('districts.json'),
            $this->config->facilities_url() => $this->fixture('facilities.json'),
        ]);
        $this->zones = new zone_repository();
        $this->districts = new district_repository();
        $this->facilities = new facility_repository();
    }

    /**
     * Read a fixture file.
     *
     * @param string $name File name inside tests/fixtures.
     * @return string
     */
    protected function fixture(string $name): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/local/mohhierarchy/tests/fixtures/' . $name);
    }

    /**
     * Build the service under test.
     *
     * @param \core\lock\lock_factory|null $lockfactory Override the lock factory.
     * @param config|null $config Override the configuration.
     * @return sync_service
     */
    protected function service(?\core\lock\lock_factory $lockfactory = null, ?config $config = null): sync_service {
        $config = $config ?? $this->config;

        return new sync_service(
            new zipatala_client($this->transport, $config),
            $config,
            $lockfactory ?? \core\lock\lock_config::get_lock_factory(sync_service::LOCK_TYPE),
        );
    }

    /**
     * A complete payload creates every level, in order, with resolved local relationships.
     */
    public function test_successful_complete_sync(): void {
        global $DB;

        $run = $this->service()->run(sync_trigger::MANUAL, null, 1000);

        $this->assertSame('success', $run->status);
        $this->assertSame('manual', $run->triggerkind);
        $this->assertEquals(1000, $run->startedat);
        $this->assertEquals(1000, $run->finishedat);
        $this->assertEquals(2, $run->zonescreated);
        $this->assertEquals(2, $run->districtscreated);
        $this->assertEquals(2, $run->facilitiescreated);
        $this->assertEquals(0, $run->zonesupdated);
        $this->assertEquals(0, $run->itemsdeactivated);
        $this->assertNull($run->errormessage);

        // Zones, districts and facilities are requested in that order, once each.
        $this->assertSame([
            self::BASE . '/zones',
            self::BASE . '/districts',
            self::BASE . '/facilities',
        ], $this->transport->requested);

        $this->assertSame(2, $DB->count_records('local_mohh_zone', ['active' => 1]));
        $this->assertSame(2, $DB->count_records('local_mohh_district', ['active' => 1]));
        $this->assertSame(2, $DB->count_records('local_mohh_facility', ['active' => 1]));

        // Relationships use local Moodle ids, not remote ids.
        $zone = $this->zones->get_by_externalid(2);
        $district = $this->districts->get_by_externalid(3);
        $facility = $this->facilities->get_by_externalid(1);
        $this->assertEquals($zone->id, $district->zoneid);
        $this->assertEquals($district->id, $facility->districtid);
        $this->assertNotEquals(3, $district->id, 'A remote id must not be reused as a local id');

        // Lastseen is stamped on every processed record.
        foreach (['local_mohh_zone', 'local_mohh_district', 'local_mohh_facility'] as $table) {
            $this->assertSame(0, $DB->count_records_select($table, 'lastseen <> :now', ['now' => 1000]));
        }

        $ancestors = $this->facilities->get_with_ancestors((int) $facility->id);
        $this->assertSame('Dedza', $ancestors->districtname);
        $this->assertSame('Central West Zone', $ancestors->zonename);
    }

    /**
     * A background task receives named, determinate progress phases.
     */
    public function test_sync_reports_progress(): void {
        $progress = new class extends \core\progress\none {
            /** @var string[] Phase descriptions in start order. */
            public array $started = [];

            #[\Override]
            public function start_progress(
                $description,
                $max = self::INDETERMINATE,
                $parentcount = 1,
            ) {
                $this->started[] = $description;
                parent::start_progress($description, $max, $parentcount);
            }
        };

        $run = $this->service()->run(sync_trigger::MANUAL, null, 1000, $progress);

        $this->assertSame('success', $run->status);
        $this->assertSame([
            get_string('syncprogress', 'local_mohhierarchy'),
            get_string('syncstage:fetchzones', 'local_mohhierarchy'),
            get_string('syncstage:fetchdistricts', 'local_mohhierarchy'),
            get_string('syncstage:fetchfacilities', 'local_mohhierarchy'),
            get_string('syncstage:validate', 'local_mohhierarchy'),
            get_string('syncstage:savezones', 'local_mohhierarchy'),
            get_string('syncstage:savedistricts', 'local_mohhierarchy'),
            get_string('syncstage:savefacilities', 'local_mohhierarchy'),
            get_string('syncstage:deactivate', 'local_mohhierarchy'),
            get_string('syncstage:finalise', 'local_mohhierarchy'),
        ], $progress->started);
    }

    /**
     * Facility columns are populated from the remote properties.
     */
    public function test_facility_fields_are_stored(): void {
        $this->service()->run(sync_trigger::CLI, null, 1000);

        $facility = $this->facilities->get_by_externalid(1);
        $this->assertSame('Dedza District Hospital', $facility->name);
        $this->assertSame('Dedza DH', $facility->commonname);
        $this->assertSame('MC010001', $facility->code);
        $this->assertNull($facility->codedhis2);
        $this->assertSame('Reg No.', $facility->registrationnumber);
        $this->assertEquals(9, $facility->facilitytypeid);
        $this->assertEquals(6, $facility->ownerid);
        $this->assertEquals(strtotime('1975-01-01T00:00:00+00:00'), $facility->dateopened);
        $this->assertEquals(strtotime('2019-06-25T14:40:37+00:00'), $facility->remoteupdatedat);
        $this->assertSame(
            [['url' => '', 'code' => 'DHIS2 CODE', 'system' => 'DHIS2']],
            facility_repository::decode_code_mapping($facility->codemappingjson),
        );

        // The record that omitted every optional property stores nulls.
        $minimal = $this->facilities->get_by_externalid(2);
        $this->assertSame('Salima Health Post', $minimal->name);
        $this->assertNull($minimal->code);
        $this->assertNull($minimal->dateopened);
        $this->assertNull($minimal->codemappingjson);
    }

    /**
     * A second run updates changed records, creates new ones and leaves unchanged ones alone.
     */
    public function test_second_run_updates_and_creates(): void {
        global $DB;

        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
        $before = $this->facilities->get_by_externalid(1);

        $this->transport->set_response($this->config->facilities_url(), $this->fixture('facilities_changed.json'));
        $run = $this->service()->run(sync_trigger::SCHEDULED, null, 2000);

        $this->assertSame('success', $run->status);
        $this->assertEquals(1, $run->facilitiescreated, 'Facility 6 is new');
        $this->assertEquals(1, $run->facilitiesupdated, 'Facility 1 changed, facility 2 did not');
        $this->assertEquals(0, $run->zonescreated);
        $this->assertEquals(0, $run->zonesupdated, 'An unchanged zone is not counted as updated');
        $this->assertEquals(0, $run->itemsdeactivated);

        $after = $this->facilities->get_by_externalid(1);
        $this->assertSame('Dedza District Hospital (renamed)', $after->name);
        $this->assertSame('DHIS-1001', $after->codedhis2);
        $this->assertEquals($before->id, $after->id, 'Upsert matches on externalid, it does not re-insert');
        $this->assertEquals($before->timecreated, $after->timecreated);
        $this->assertEquals(2000, $after->timemodified);
        $this->assertEquals(2000, $after->lastseen);

        // An unchanged record still has its lastseen refreshed, but not its timemodified.
        $unchanged = $this->facilities->get_by_externalid(2);
        $this->assertEquals(2000, $unchanged->lastseen);
        $this->assertEquals(1000, $unchanged->timemodified);

        $this->assertSame(3, $DB->count_records('local_mohh_facility'));
        $this->assertNotNull($this->facilities->get_by_externalid(6));
    }

    /**
     * Repeating an identical complete payload changes no business records.
     */
    public function test_identical_repeat_sync_is_idempotent(): void {
        global $DB;

        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
        $before = $this->facilities->get_by_externalid(1);

        $run = $this->service()->run(sync_trigger::SCHEDULED, null, 2000);

        $this->assertSame('success', $run->status);
        foreach (sync_log_repository::COUNTERS as $counter) {
            $this->assertSame(0, (int) $run->{$counter}, "{$counter} must remain zero");
        }
        $after = $this->facilities->get_by_externalid(1);
        $this->assertEquals($before->id, $after->id);
        $this->assertEquals($before->timemodified, $after->timemodified);
        $this->assertEquals(2000, $after->lastseen);
        $this->assertSame(2, $DB->count_records('local_mohh_synclog', ['status' => 'success']));
    }

    /**
     * A database failure after writes begin rolls the complete hierarchy transaction back.
     */
    public function test_transaction_rolls_back_after_mid_write_failure(): void {
        global $DB;

        $service = $this->service();
        $failingzones = new class extends zone_repository {
            /** @var int Calls made during this run. */
            protected int $calls = 0;

            #[\Override]
            public function upsert(\stdClass $record, ?int $now = null): array {
                $result = parent::upsert($record, $now);
                $this->calls++;
                if ($this->calls === 2) {
                    throw new \dml_write_exception('forcedtransactionfailure', 'INSERT test fixture', []);
                }

                return $result;
            }
        };
        $property = (new \ReflectionClass(sync_service::class))->getProperty('zones');
        $property->setValue($service, $failingzones);

        try {
            $service->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('The injected database failure must escape the synchronisation.');
        } catch (\dml_write_exception $e) {
            $this->assertSame('dmlwriteexception', $e->errorcode);
            $this->assertStringContainsString('forcedtransactionfailure', $e->debuginfo);
        }

        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
        $this->assertSame(0, $DB->count_records('local_mohh_district'));
        $this->assertSame(0, $DB->count_records('local_mohh_facility'));
        $log = $DB->get_record('local_mohh_synclog', [], '*', MUST_EXIST);
        $this->assertSame('failed', $log->status);
    }

    /**
     * A response that is not JSON fails the run and changes nothing locally.
     */
    public function test_invalid_json_fails_the_run_and_changes_nothing(): void {
        global $DB;

        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_invalid.json'));

        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected invalid JSON to fail the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidjson', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
        $this->assertSame(0, $DB->count_records('local_mohh_district'));
        $this->assertSame(0, $DB->count_records('local_mohh_facility'));

        $log = $DB->get_record('local_mohh_synclog', []);
        $this->assertSame('failed', $log->status);
        $this->assertNotEmpty($log->errormessage);
        $this->assertStringNotContainsString('Gateway timeout', $log->errormessage);
    }

    /**
     * An HTTP error fails the run before anything is written, even for datasets already fetched.
     */
    public function test_http_error_fails_the_run(): void {
        global $DB;

        $this->transport->set_response(
            $this->config->districts_url(),
            new \moodle_exception('error:httpstatus', 'local_mohhierarchy', '', (object) [
                'url' => self::BASE . '/districts',
                'status' => 500,
            ]),
        );

        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected an HTTP error to fail the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:httpstatus', $e->errorcode);
        }

        // The zones response was valid and was fetched first, but writing never started.
        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
        $log = $DB->get_record('local_mohh_synclog', []);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('500', $log->errormessage);
    }

    /**
     * A repeated remote id fails the run.
     */
    public function test_duplicate_external_ids_fail_the_run(): void {
        global $DB;

        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_duplicate_id.json'));

        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected duplicate remote ids to fail the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:duplicateexternalid', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
    }

    /**
     * A district pointing at a zone that is in neither the payload nor the local copy fails the run.
     */
    public function test_district_with_unknown_zone_fails_the_run(): void {
        global $DB;

        $this->transport->set_response($this->config->districts_url(), $this->fixture('districts_unknown_zone.json'));

        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected an unknown zone reference to fail the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:unknownzonereference', $e->errorcode);
            $this->assertStringContainsString('999', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
        $this->assertSame(0, $DB->count_records('local_mohh_district'));
    }

    /**
     * A facility pointing at an unknown district fails the run.
     */
    public function test_facility_with_unknown_district_fails_the_run(): void {
        global $DB;

        $this->transport->set_response(
            $this->config->facilities_url(),
            $this->fixture('facilities_unknown_district.json'),
        );

        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected an unknown district reference to fail the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:unknowndistrictreference', $e->errorcode);
            $this->assertStringContainsString('888', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
        $this->assertSame(0, $DB->count_records('local_mohh_facility'));
    }

    /**
     * A child may reference a parent that is absent from this run but already stored locally.
     */
    public function test_parent_may_come_from_the_local_copy(): void {
        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);

        // Zone 1 disappears from the zones response, but district 4 still references it.
        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_subset.json'));
        $run = $this->service()->run(sync_trigger::SCHEDULED, null, 2000);

        $this->assertSame('success', $run->status);
        $this->assertEquals(
            1,
            $this->zones->get_by_externalid(1)->active,
            'A zone still referenced by a district in the same payload must stay active'
        );
        $this->assertEquals(0, $run->itemsdeactivated);
    }

    /**
     * Nothing is deactivated when the run fails, even if an earlier dataset was a subset.
     */
    public function test_no_deactivation_after_a_failed_run(): void {
        global $DB;

        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);
        $this->assertSame(2, $DB->count_records('local_mohh_zone', ['active' => 1]));

        // Zones and districts shrink, but the facilities response is broken.
        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_subset.json'));
        $this->transport->set_response($this->config->districts_url(), $this->fixture('districts_subset.json'));
        $this->transport->set_response($this->config->facilities_url(), '{"unexpected": true}');

        $this->expectException(\moodle_exception::class);
        try {
            $this->service()->run(sync_trigger::SCHEDULED, null, 2000);
        } finally {
            $this->assertSame(2, $DB->count_records('local_mohh_zone', ['active' => 1]));
            $this->assertSame(2, $DB->count_records('local_mohh_district', ['active' => 1]));
            $this->assertSame(2, $DB->count_records('local_mohh_facility', ['active' => 1]));
            $this->assertEquals(
                1000,
                $this->zones->get_by_externalid(1)->lastseen,
                'A failed run must not restamp lastseen'
            );
        }
    }

    /**
     * Records missing from a complete successful payload are deactivated, never deleted.
     */
    public function test_deactivation_only_after_a_complete_successful_run(): void {
        global $DB;

        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);

        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_subset.json'));
        $this->transport->set_response($this->config->districts_url(), $this->fixture('districts_subset.json'));
        $this->transport->set_response($this->config->facilities_url(), $this->fixture('facilities_subset.json'));

        $run = $this->service()->run(sync_trigger::SCHEDULED, null, 2000);

        $this->assertSame('success', $run->status);
        $this->assertEquals(3, $run->itemsdeactivated, 'Zone 1, district 4 and facility 2 are gone');

        // Nothing was hard deleted.
        $this->assertSame(2, $DB->count_records('local_mohh_zone'));
        $this->assertSame(2, $DB->count_records('local_mohh_district'));
        $this->assertSame(2, $DB->count_records('local_mohh_facility'));

        $this->assertEquals(0, $this->zones->get_by_externalid(1)->active);
        $this->assertEquals(0, $this->districts->get_by_externalid(4)->active);
        $this->assertEquals(0, $this->facilities->get_by_externalid(2)->active);
        $this->assertEquals(1, $this->zones->get_by_externalid(2)->active);
        $this->assertEquals(1, $this->facilities->get_by_externalid(1)->active);

        // A record that comes back is reactivated rather than duplicated.
        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones.json'));
        $this->transport->set_response($this->config->districts_url(), $this->fixture('districts.json'));
        $this->transport->set_response($this->config->facilities_url(), $this->fixture('facilities.json'));
        $this->service()->run(sync_trigger::SCHEDULED, null, 3000);

        $this->assertEquals(1, $this->zones->get_by_externalid(1)->active);
        $this->assertSame(2, $DB->count_records('local_mohh_zone'));
    }

    /**
     * User assignments survive deactivation of the facility they point at.
     */
    public function test_user_assignments_are_preserved(): void {
        global $DB;

        $this->service()->run(sync_trigger::SCHEDULED, null, 1000);

        $user = $this->getDataGenerator()->create_user();
        $facility = $this->facilities->get_by_externalid(2);
        $assignments = new assignment_repository();
        $before = $assignments->save(
            (int) $user->id,
            (int) $facility->id,
            scope_level::FACILITY,
            assign_source::ADMIN,
            null,
            null,
            1500,
        );

        // Facility 2 disappears upstream.
        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_subset.json'));
        $this->transport->set_response($this->config->districts_url(), $this->fixture('districts_subset.json'));
        $this->transport->set_response($this->config->facilities_url(), $this->fixture('facilities_subset.json'));
        $this->service()->run(sync_trigger::SCHEDULED, null, 2000);

        $after = $assignments->get_for_user((int) $user->id);
        $this->assertNotNull($after, 'Synchronisation must never remove an assignment');
        $this->assertEquals($before->id, $after->id);
        $this->assertEquals($before->facilityid, $after->facilityid);
        $this->assertEquals(1500, $after->timemodified, 'Synchronisation must not touch assignments');
        $this->assertSame(1, $DB->count_records('local_mohh_assign'));
        $this->assertNotNull($this->facilities->get_by_id((int) $facility->id));
    }

    /**
     * A second run cannot start while the first holds the lock.
     */
    public function test_lock_prevents_overlapping_syncs(): void {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory(sync_service::LOCK_TYPE);
        $held = $factory->get_lock(sync_service::LOCK_RESOURCE, 0);
        $this->assertNotFalse($held, 'Could not acquire the lock to set the test up');

        try {
            $this->service($factory)->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected the second run to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:syncalreadyrunning', $e->errorcode);
        } finally {
            $held->release();
        }

        $this->assertSame([], $this->transport->requested, 'A refused run must not call the API');
        $this->assertSame(0, $DB->count_records('local_mohh_synclog'), 'A refused run must not open a log row');
        $this->assertSame(0, $DB->count_records('local_mohh_zone'));
    }

    /**
     * Once the lock is released the next run proceeds normally.
     */
    public function test_lock_is_released_after_a_failed_run(): void {
        $this->transport->set_response($this->config->zones_url(), $this->fixture('zones_invalid.json'));
        $factory = \core\lock\lock_config::get_lock_factory(sync_service::LOCK_TYPE);

        try {
            $this->service($factory)->run(sync_trigger::SCHEDULED, null, 1000);
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidjson', $e->errorcode);
        }

        // The same factory can take the lock again, which it could not if the run had kept it.
        $lock = $factory->get_lock(sync_service::LOCK_RESOURCE, 0);
        $this->assertNotFalse($lock, 'A failed run must release the lock');
        $lock->release();
    }

    /**
     * The bearer token never reaches the sync log or the exception the caller sees.
     */
    public function test_token_never_appears_in_errors_or_logs(): void {
        global $DB;

        $token = 'sup3r-s3cret-zipatala-token';
        $config = new config(self::BASE, token: $token);

        // Simulate a third party message that leaked the token, which redaction must catch.
        $this->transport->set_response(
            $config->zones_url(),
            new \moodle_exception('error:transportfailed', 'local_mohhierarchy', '', (object) [
                'url' => self::BASE . '/zones?access_token=' . $token,
                'detail' => 'Connection reset while sending Authorization: Bearer ' . $token,
            ]),
        );

        try {
            $this->service(null, $config)->run(sync_trigger::SCHEDULED, null, 1000);
            $this->fail('Expected the transport failure to propagate');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:transportfailed', $e->errorcode);
            // This is the form the sync page and the CLI script display.
            $this->assertStringNotContainsString($token, $config->redact($e->getMessage()));
        }

        $log = $DB->get_record('local_mohh_synclog', []);
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString($token, $log->errormessage);
        $this->assertStringContainsString(config::REDACTED, $log->errormessage);
    }

    /**
     * The scheduled task does nothing when scheduled synchronisation is switched off.
     */
    public function test_scheduled_sync_can_be_disabled(): void {
        global $DB;

        set_config('scheduledsyncenabled', 0, 'local_mohhierarchy');
        $this->assertFalse(config::create()->scheduled_sync_enabled());

        ob_start();
        (new \local_mohhierarchy\task\sync_hierarchy())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('disabled', $output);
        $this->assertSame(0, $DB->count_records('local_mohh_synclog'));
    }
}
