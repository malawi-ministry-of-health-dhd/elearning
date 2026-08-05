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

use local_mohhierarchy\local\scope_level;

/**
 * Database access for the local copy of the facilities.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class facility_repository extends reference_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_facility';

    #[\Override]
    public function get_table(): string {
        return self::TABLE;
    }

    #[\Override]
    public function get_syncable_fields(): array {
        return [
            'districtid',
            'code',
            'codedhis2',
            'codeopenlmis',
            'name',
            'commonname',
            'registrationnumber',
            'facilitytypeid',
            'ownerid',
            'operationalstatusid',
            'regulatorystatusid',
            'dateopened',
            'publishedat',
            'remotecreatedat',
            'remoteupdatedat',
            'codemappingjson',
        ];
    }

    /**
     * List the facilities of one district.
     *
     * @param int $districtid Local district id.
     * @param bool $activeonly Whether to exclude deactivated facilities.
     * @return \stdClass[] Keyed by local id, sorted by name.
     */
    public function get_by_district(int $districtid, bool $activeonly = true): array {
        global $DB;

        $conditions = ['districtid' => $districtid];
        if ($activeonly) {
            $conditions['active'] = 1;
        }

        return $DB->get_records(self::TABLE, $conditions, 'name ASC');
    }

    /**
     * Whether a facility really sits in a district.
     *
     * Used to re-check, on the server, a district and facility pair that arrived from a browser.
     *
     * @param int $facilityid Local facility id.
     * @param int $districtid Local district id.
     * @return bool
     */
    public function belongs_to_district(int $facilityid, int $districtid): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['id' => $facilityid, 'districtid' => $districtid]);
    }

    /**
     * Resolve a facility together with its district and zone.
     *
     * This is the single place the zone and district of an assignment are derived from, so a
     * stored triple can never disagree with the reference tables.
     *
     * @param int $facilityid Local facility id.
     * @return \stdClass|null Null when the facility does not exist.
     */
    public function get_with_ancestors(int $facilityid): ?\stdClass {
        global $DB;

        $sql = "SELECT f.id AS facilityid, f.name AS facilityname, f.code AS facilitycode, f.active AS facilityactive,
                       d.id AS districtid, d.name AS districtname, d.active AS districtactive,
                       z.id AS zoneid, z.name AS zonename, z.active AS zoneactive
                  FROM {local_mohh_facility} f
                  JOIN {local_mohh_district} d ON d.id = f.districtid
                  JOIN {local_mohh_zone} z ON z.id = d.zoneid
                 WHERE f.id = :facilityid";

        return $DB->get_record_sql($sql, ['facilityid' => $facilityid]) ?: null;
    }

    /**
     * Search facilities by facility name or code, district name or zone name.
     *
     * Returns one row per facility carrying both the local id and the remote id at every level, so
     * an administrator can tell the two apart when comparing with the upstream register.
     *
     * @param string $term Free text, matched case insensitively anywhere in the field.
     * @param int $limitfrom Offset for paging.
     * @param int $limitnum Page size, 0 for all rows.
     * @return \stdClass[] Keyed by local facility id, ordered zone then district then facility.
     */
    public function search(
        string $term,
        int $limitfrom = 0,
        int $limitnum = 0,
        ?hierarchy_scope $scope = null,
    ): array {
        global $DB;

        [$where, $params] = $this->search_clause($term, $scope);
        $sql = "SELECT f.id AS facilityid, f.externalid AS facilityexternalid, f.name AS facilityname,
                       f.code AS facilitycode, f.active AS facilityactive,
                       d.id AS districtid, d.externalid AS districtexternalid, d.name AS districtname,
                       d.active AS districtactive,
                       z.id AS zoneid, z.externalid AS zoneexternalid, z.name AS zonename,
                       z.active AS zoneactive
                  FROM {local_mohh_facility} f
                  JOIN {local_mohh_district} d ON d.id = f.districtid
                  JOIN {local_mohh_zone} z ON z.id = d.zoneid
                 WHERE {$where}
              ORDER BY z.name ASC, d.name ASC, f.name ASC, f.id ASC";

        return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * How many facilities a search matches, for the paging bar.
     *
     * @param string $term The same term passed to search().
     * @return int
     */
    public function count_search(string $term, ?hierarchy_scope $scope = null): int {
        global $DB;

        [$where, $params] = $this->search_clause($term, $scope);
        $sql = "SELECT COUNT(f.id)
                  FROM {local_mohh_facility} f
                  JOIN {local_mohh_district} d ON d.id = f.districtid
                  JOIN {local_mohh_zone} z ON z.id = d.zoneid
                 WHERE {$where}";

        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Build the search condition shared by search() and count_search().
     *
     * @param string $term Free text.
     * @param hierarchy_scope|null $scope Restrict results to this delegated scope; null is unrestricted.
     * @return array{0: string, 1: array} SQL fragment and its parameters.
     */
    protected function search_clause(string $term, ?hierarchy_scope $scope = null): array {
        global $DB;

        $conditions = [];
        $params = [];
        $term = trim($term);
        if ($term !== '') {
            $pattern = '%' . $DB->sql_like_escape($term) . '%';
            $columns = ['f.name' => 'fname', 'f.code' => 'fcode', 'd.name' => 'dname', 'z.name' => 'zname'];
            $searchconditions = [];
            foreach ($columns as $column => $placeholder) {
                $searchconditions[] = $DB->sql_like($column, ":{$placeholder}", false, false);
                $params[$placeholder] = $pattern;
            }
            $conditions[] = '(' . implode(' OR ', $searchconditions) . ')';
        }

        [$scopesql, $scopeparams] = $this->scope_clause($scope);
        if ($scopesql !== '') {
            $conditions[] = $scopesql;
            $params += $scopeparams;
        }

        return [$conditions ? implode(' AND ', $conditions) : '1 = 1', $params];
    }

    /**
     * Active facilities available to an assignment form, with ancestor names.
     *
     * @param hierarchy_scope|null $scope Restrict results to this scope; null is unrestricted.
     * @return \stdClass[] Keyed by local facility id.
     */
    public function get_assignment_options(?hierarchy_scope $scope = null): array {
        global $DB;

        [$scopesql, $params] = $this->scope_clause($scope);
        $where = ['f.active = 1', 'd.active = 1', 'z.active = 1'];
        if ($scopesql !== '') {
            $where[] = $scopesql;
        }
        $sql = "SELECT f.id AS facilityid, f.name AS facilityname, f.code AS facilitycode,
                       d.id AS districtid, d.name AS districtname,
                       z.id AS zoneid, z.name AS zonename
                  FROM {local_mohh_facility} f
                  JOIN {local_mohh_district} d ON d.id = f.districtid
                  JOIN {local_mohh_zone} z ON z.id = d.zoneid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY z.name ASC, d.name ASC, f.name ASC, f.id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * SQL condition for facilities within a delegated hierarchy scope.
     *
     * @param hierarchy_scope|null $scope Null grants an unrestricted query for site administrators.
     * @return array{0: string, 1: array}
     */
    protected function scope_clause(?hierarchy_scope $scope): array {
        if ($scope === null) {
            return ['', []];
        }

        return match ($scope->level) {
            scope_level::ZONE => ['z.id = :scopezoneid', ['scopezoneid' => $scope->zoneid]],
            scope_level::DISTRICT => [
                'z.id = :scopezoneid AND d.id = :scopedistrictid',
                ['scopezoneid' => $scope->zoneid, 'scopedistrictid' => $scope->districtid],
            ],
            scope_level::FACILITY => [
                'z.id = :scopezoneid AND d.id = :scopedistrictid AND f.id = :scopefacilityid',
                [
                    'scopezoneid' => $scope->zoneid,
                    'scopedistrictid' => $scope->districtid,
                    'scopefacilityid' => $scope->facilityid,
                ],
            ],
            scope_level::NONE => ['1 = 0', []],
        };
    }

    /**
     * Whether one facility satisfies a set of ancestry constraints, in a single query.
     *
     * This is the targeted check the permission service uses instead of listing every facility a
     * user may reach and searching the result. Passing null for a constraint leaves it unchecked.
     *
     * @param int $facilityid Local facility id.
     * @param int|null $districtid Require this district.
     * @param int|null $zoneid Require this zone.
     * @param bool $activeonly Require the facility, its district and its zone to all be active.
     * @return bool
     */
    public function matches_scope(
        int $facilityid,
        ?int $districtid = null,
        ?int $zoneid = null,
        bool $activeonly = true,
    ): bool {
        global $DB;

        $where = ['f.id = :facilityid'];
        $params = ['facilityid' => $facilityid];
        if ($districtid !== null) {
            $where[] = 'f.districtid = :districtid';
            $params['districtid'] = $districtid;
        }
        if ($zoneid !== null) {
            $where[] = 'd.zoneid = :zoneid';
            $params['zoneid'] = $zoneid;
        }
        if ($activeonly) {
            $where[] = 'f.active = 1';
            $where[] = 'd.active = 1';
            $where[] = 'z.active = 1';
        }

        $sql = "SELECT f.id
                  FROM {local_mohh_facility} f
                  JOIN {local_mohh_district} d ON d.id = f.districtid
                  JOIN {local_mohh_zone} z ON z.id = d.zoneid
                 WHERE " . implode(' AND ', $where);

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Validate and encode the remote facility_code_mapping sub-document for storage.
     *
     * Only this sub-document is kept as JSON: its keys vary by downstream integration and cannot
     * be columnised. The rest of the remote payload is mapped onto columns and discarded.
     *
     * @param array|\stdClass|null $mapping The decoded remote value.
     * @return string|null JSON text, or null when there is nothing to store.
     */
    public static function encode_code_mapping(array|\stdClass|null $mapping): ?string {
        if ($mapping === null) {
            return null;
        }
        $normalised = (array) $mapping;
        if ($normalised === []) {
            return null;
        }
        $json = json_encode($normalised);
        if ($json === false) {
            throw new \moodle_exception('error:invalidcodemapping', 'local_mohhierarchy', '', json_last_error_msg());
        }

        return $json;
    }

    /**
     * Decode a stored code mapping.
     *
     * @param string|null $json The stored JSON text.
     * @return array Empty when the column is null or holds invalid JSON.
     */
    public static function decode_code_mapping(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
