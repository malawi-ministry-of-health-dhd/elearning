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

namespace local_mohhierarchy\local\hierarchy;

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\source\curl_transport;
use local_mohhierarchy\local\source\mapper;
use local_mohhierarchy\local\source\transport;
use local_mohhierarchy\local\source\zipatala_client;
use local_mohhierarchy\local\sync_status;
use local_mohhierarchy\local\sync_trigger;

/**
 * Synchronises the local copy of the hierarchy from the remote service.
 *
 * The run is deliberately split into two halves. The first half fetches, maps and cross-validates
 * all three datasets and touches nothing local. Only once the complete payload is known to be good
 * does the second half open a transaction and write. That is what makes "do not modify local data
 * if the payload cannot be validated" true by construction rather than by care.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_service {
    /** @var string Lock factory namespace. */
    public const LOCK_TYPE = 'local_mohhierarchy';

    /** @var string Lock resource name. One sync at a time, site wide. */
    public const LOCK_RESOURCE = 'sync';

    /** @var int Longest error message stored in the sync log. */
    protected const MAX_ERROR_LENGTH = 1000;

    /** @var zone_repository Zone storage. */
    protected zone_repository $zones;

    /** @var district_repository District storage. */
    protected district_repository $districts;

    /** @var facility_repository Facility storage. */
    protected facility_repository $facilities;

    /** @var sync_log_repository Run log storage. */
    protected sync_log_repository $synclog;

    /** @var zipatala_client Reads the remote hierarchy datasets. */
    protected zipatala_client $client;

    /** @var config Plugin configuration and redaction helper. */
    protected config $config;

    /** @var \core\lock\lock_factory Produces the site-wide synchronisation lock. */
    protected \core\lock\lock_factory $lockfactory;

    /**
     * Constructor.
     *
     * The lock factory is injected because \core\lock\lock_config::get_lock_factory() returns a new
     * instance on every call, and the fail-fast guard against stacked locks lives on the instance.
     * Injecting it lets a test hold the lock and observe that a second run refuses to start.
     *
     * @param zipatala_client $client Reads the remote datasets.
     * @param config $config Supplies settings and the token redaction helper.
     * @param \core\lock\lock_factory $lockfactory Produces the site wide sync lock.
     */
    public function __construct(
        zipatala_client $client,
        config $config,
        \core\lock\lock_factory $lockfactory,
    ) {
        $this->client = $client;
        $this->config = $config;
        $this->lockfactory = $lockfactory;
        $this->zones = new zone_repository();
        $this->districts = new district_repository();
        $this->facilities = new facility_repository();
        $this->synclog = new sync_log_repository();
    }

    /**
     * Build a service wired to the site settings and Moodle's cURL transport.
     *
     * @param transport|null $transport Override the transport, used by tests and by diagnostics.
     * @param config|null $config Override the settings.
     * @return self
     */
    public static function create(?transport $transport = null, ?config $config = null): self {
        $config = $config ?? config::create();
        $transport = $transport ?? new curl_transport($config);

        return new self(
            new zipatala_client($transport, $config),
            $config,
            \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE),
        );
    }

    /**
     * Run a full synchronisation.
     *
     * @param sync_trigger $trigger What started this run.
     * @param int|null $triggeredby The acting user for a manual run, null otherwise.
     * @param int|null $now Timestamp to record throughout the run, defaults to now.
     * @param \core\progress\base|null $progress Optional stored progress reporter for a background task.
     * @return \stdClass The completed local_mohh_synclog row.
     */
    public function run(
        sync_trigger $trigger,
        ?int $triggeredby = null,
        ?int $now = null,
        ?\core\progress\base $progress = null,
    ): \stdClass {
        $now = $now ?? time();

        // A zero timeout means do not queue: an overlapping run is refused, not delayed.
        $lock = $this->lockfactory->get_lock(self::LOCK_RESOURCE, 0);
        if (!$lock) {
            throw new \moodle_exception('error:syncalreadyrunning', 'local_mohhierarchy');
        }

        $runid = $this->synclog->start($trigger, $triggeredby, $now);
        if ($progress !== null) {
            $progress->start_progress(get_string('syncprogress', 'local_mohhierarchy'), 9);
        }
        try {
            $payload = $this->fetch_and_validate($progress);
            $counters = $this->persist($payload, $now, $progress);
            // A facility may have moved district or been deactivated, which changes what a cached
            // scope means, so every resolved scope is dropped.
            $this->progress_phase(
                $progress,
                'syncstage:finalise',
                1,
                static function (?\core\progress\base $phase): void {
                    permission_service::purge_all_scope_caches();
                    $phase?->progress(1);
                },
            );
            $this->synclog->finish($runid, sync_status::SUCCESS, $counters, null, $now);
        } catch (\Throwable $e) {
            $this->synclog->finish($runid, sync_status::FAILED, [], $this->sanitise($e), $now);
            throw $e;
        } finally {
            if ($progress !== null) {
                $progress->end_progress();
            }
            $lock->release();
        }

        return $this->synclog->get($runid);
    }

    /**
     * Fetch all three datasets, in hierarchy order, and validate them as a whole.
     *
     * Nothing local is read or written for the purpose of changing it here; the only local reads
     * are the parent lookups that decide whether a reference can be resolved at all.
     *
     * @param \core\progress\base|null $progress Optional progress reporter.
     * @return \stdClass Carries the mapped zones, districts and facilities.
     */
    protected function fetch_and_validate(?\core\progress\base $progress = null): \stdClass {
        $zones = $this->progress_phase(
            $progress,
            'syncstage:fetchzones',
            1,
            function (?\core\progress\base $phase): array {
                $result = mapper::map_zones($this->client->fetch_zones());
                $phase?->progress(1);
                return $result;
            },
        );
        $districts = $this->progress_phase(
            $progress,
            'syncstage:fetchdistricts',
            1,
            function (?\core\progress\base $phase): array {
                $result = mapper::map_districts($this->client->fetch_districts());
                $phase?->progress(1);
                return $result;
            },
        );
        $facilities = $this->progress_phase(
            $progress,
            'syncstage:fetchfacilities',
            1,
            function (?\core\progress\base $phase): array {
                $result = mapper::map_facilities($this->client->fetch_facilities());
                $phase?->progress(1);
                return $result;
            },
        );

        $relationshipcount = count($districts) + count($facilities);
        $this->progress_phase(
            $progress,
            'syncstage:validate',
            $relationshipcount,
            function (?\core\progress\base $phase) use ($zones, $districts, $facilities): void {
                $processed = 0;
                foreach ($districts as $district) {
                    if (
                        !array_key_exists($district->zoneexternalid, $zones)
                        && $this->zones->get_by_externalid($district->zoneexternalid) === null
                    ) {
                        throw new \moodle_exception('error:unknownzonereference', 'local_mohhierarchy', '', (object) [
                            'district' => $district->externalid,
                            'zone' => $district->zoneexternalid,
                        ]);
                    }
                    $phase?->progress(++$processed);
                }

                foreach ($facilities as $facility) {
                    if (
                        !array_key_exists($facility->districtexternalid, $districts)
                        && $this->districts->get_by_externalid($facility->districtexternalid) === null
                    ) {
                        throw new \moodle_exception(
                            'error:unknowndistrictreference',
                            'local_mohhierarchy',
                            '',
                            (object) [
                                'facility' => $facility->externalid,
                                'district' => $facility->districtexternalid,
                            ],
                        );
                    }
                    $phase?->progress(++$processed);
                }
            },
        );

        return (object) ['zones' => $zones, 'districts' => $districts, 'facilities' => $facilities];
    }

    /**
     * Write a validated payload in one transaction, then deactivate whatever the payload dropped.
     *
     * @param \stdClass $payload The output of fetch_and_validate().
     * @param int $now Timestamp recorded as lastseen on every processed record.
     * @param \core\progress\base|null $progress Optional progress reporter.
     * @return array Counter column name => value, for the sync log.
     */
    protected function persist(
        \stdClass $payload,
        int $now,
        ?\core\progress\base $progress = null,
    ): array {
        global $DB;

        $counters = array_fill_keys(sync_log_repository::COUNTERS, 0);

        $transaction = $DB->start_delegated_transaction();
        try {
            $zonemap = [];
            $this->progress_phase(
                $progress,
                'syncstage:savezones',
                count($payload->zones),
                function (?\core\progress\base $phase) use ($payload, $now, &$zonemap, &$counters): void {
                    $processed = 0;
                    foreach ($payload->zones as $zone) {
                        $result = $this->zones->upsert($zone, $now);
                        $zonemap[$zone->externalid] = $result['id'];
                        $counters['zonescreated'] += $result['created'] ? 1 : 0;
                        $counters['zonesupdated'] += $result['updated'] ? 1 : 0;
                        $phase?->progress(++$processed);
                    }
                },
            );

            // Parents that a child in this payload still references must survive deactivation.
            $keepzones = [];
            $districtmap = [];
            $this->progress_phase(
                $progress,
                'syncstage:savedistricts',
                count($payload->districts),
                function (?\core\progress\base $phase) use (
                    $payload,
                    $now,
                    &$zonemap,
                    &$keepzones,
                    &$districtmap,
                    &$counters,
                ): void {
                    $processed = 0;
                    foreach ($payload->districts as $district) {
                        $zoneid = $zonemap[$district->zoneexternalid]
                            ?? (int) $this->zones->get_by_externalid($district->zoneexternalid)->id;
                        $keepzones[$zoneid] = $zoneid;
                        $result = $this->districts->upsert((object) [
                            'externalid' => $district->externalid,
                            'zoneid' => $zoneid,
                            'name' => $district->name,
                            'code' => $district->code,
                        ], $now);
                        $districtmap[$district->externalid] = $result['id'];
                        $counters['districtscreated'] += $result['created'] ? 1 : 0;
                        $counters['districtsupdated'] += $result['updated'] ? 1 : 0;
                        $phase?->progress(++$processed);
                    }
                },
            );

            $keepdistricts = [];
            $this->progress_phase(
                $progress,
                'syncstage:savefacilities',
                count($payload->facilities),
                function (?\core\progress\base $phase) use (
                    $payload,
                    $now,
                    &$districtmap,
                    &$keepdistricts,
                    &$counters,
                ): void {
                    $processed = 0;
                    foreach ($payload->facilities as $facility) {
                        $districtid = $districtmap[$facility->districtexternalid]
                            ?? (int) $this->districts->get_by_externalid($facility->districtexternalid)->id;
                        $keepdistricts[$districtid] = $districtid;
                        $record = clone $facility;
                        $record->districtid = $districtid;
                        unset($record->districtexternalid);
                        $result = $this->facilities->upsert($record, $now);
                        $counters['facilitiescreated'] += $result['created'] ? 1 : 0;
                        $counters['facilitiesupdated'] += $result['updated'] ? 1 : 0;
                        $phase?->progress(++$processed);
                    }
                },
            );

            // Children first, then parents, so a parent is only considered once its children are.
            // Nothing is ever hard deleted, so existing user assignments keep resolving.
            $this->progress_phase(
                $progress,
                'syncstage:deactivate',
                1,
                function (?\core\progress\base $phase) use (
                    $now,
                    $keepdistricts,
                    $keepzones,
                    &$counters,
                ): void {
                    $counters['itemsdeactivated'] =
                        $this->facilities->deactivate_not_seen_since($now, $now)
                        + $this->districts->deactivate_not_seen_since($now, $now, $keepdistricts)
                        + $this->zones->deactivate_not_seen_since($now, $now, $keepzones);
                    $phase?->progress(1);
                },
            );

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $counters;
    }

    /**
     * Run one named phase and expose its progress to a stored task progress bar.
     *
     * @param \core\progress\base|null $progress Optional progress reporter.
     * @param string $stringkey Language string identifying the phase.
     * @param int $maximum Number of units in this phase.
     * @param callable $callback Work receiving the phase progress reporter, or null.
     * @return mixed Callback result.
     */
    protected function progress_phase(
        ?\core\progress\base $progress,
        string $stringkey,
        int $maximum,
        callable $callback,
    ): mixed {
        if ($progress === null) {
            return $callback(null);
        }

        $progress->start_progress(get_string($stringkey, 'local_mohhierarchy'), $maximum);
        try {
            return $callback($progress);
        } finally {
            $progress->end_progress();
        }
    }

    /**
     * Turn a failure into a short message that is safe to store.
     *
     * The stack trace is never included: it can contain argument values, and one of those arguments
     * could be a URL carrying a credential.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    protected function sanitise(\Throwable $e): string {
        $message = $e->getMessage();
        if (!($e instanceof \moodle_exception)) {
            $message = get_class($e) . ': ' . $message;
        }
        $message = $this->config->redact($message);

        return \core_text::substr(clean_param($message, PARAM_NOTAGS), 0, self::MAX_ERROR_LENGTH);
    }
}
