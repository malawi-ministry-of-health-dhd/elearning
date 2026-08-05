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

namespace local_mohhierarchy\local\source;

use local_mohhierarchy\local\hierarchy\facility_repository;

/**
 * Maps remote Zipatala items onto local record shapes.
 *
 * Every method validates before it maps and throws on the first problem, so a payload is either
 * completely usable or completely rejected. Parent references are kept as remote ids here; turning
 * them into local Moodle ids is the sync service's job, once the parent level has been written.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mapper {
    /** @var int Width of the local code style columns. */
    protected const CODE_LENGTH = 100;

    /** @var int Width of the local name style columns. */
    protected const NAME_LENGTH = 255;

    /**
     * Map the zones dataset.
     *
     * @param array[] $items Decoded remote items.
     * @return \stdClass[] Keyed by remote id, carrying externalid, name and description.
     */
    public static function map_zones(array $items): array {
        $mapped = [];
        foreach ($items as $index => $item) {
            $externalid = self::read_id($item, 'zones', $index);
            self::reject_duplicate($mapped, $externalid, 'zones');
            $mapped[$externalid] = (object) [
                'externalid' => $externalid,
                'name' => self::read_required_text($item, 'zone_name', self::NAME_LENGTH, 'zones', $index),
                'description' => self::read_text($item, 'description', null),
            ];
        }

        return $mapped;
    }

    /**
     * Map the districts dataset.
     *
     * @param array[] $items Decoded remote items.
     * @return \stdClass[] Keyed by remote id, carrying zoneexternalid rather than a local zone id.
     */
    public static function map_districts(array $items): array {
        $mapped = [];
        foreach ($items as $index => $item) {
            $externalid = self::read_id($item, 'districts', $index);
            self::reject_duplicate($mapped, $externalid, 'districts');
            $mapped[$externalid] = (object) [
                'externalid' => $externalid,
                'zoneexternalid' => self::read_required_id($item, 'zone_id', 'districts', $index),
                'name' => self::read_required_text($item, 'district_name', self::NAME_LENGTH, 'districts', $index),
                'code' => self::read_text($item, 'district_code', self::CODE_LENGTH),
            ];
        }

        return $mapped;
    }

    /**
     * Map the facilities dataset.
     *
     * @param array[] $items Decoded remote items.
     * @return \stdClass[] Keyed by remote id, carrying districtexternalid rather than a local id.
     */
    public static function map_facilities(array $items): array {
        $mapped = [];
        foreach ($items as $index => $item) {
            $externalid = self::read_id($item, 'facilities', $index);
            self::reject_duplicate($mapped, $externalid, 'facilities');
            $mapped[$externalid] = (object) [
                'externalid' => $externalid,
                'districtexternalid' => self::read_required_id($item, 'district_id', 'facilities', $index),
                'name' => self::read_required_text($item, 'facility_name', self::NAME_LENGTH, 'facilities', $index),
                'code' => self::read_text($item, 'facility_code', self::CODE_LENGTH),
                'codedhis2' => self::read_text($item, 'facility_code_dhis2', self::CODE_LENGTH),
                'codeopenlmis' => self::read_text($item, 'facility_code_openlmis', self::CODE_LENGTH),
                'commonname' => self::read_text($item, 'common_name', self::NAME_LENGTH),
                'registrationnumber' => self::read_text($item, 'registration_number', self::CODE_LENGTH),
                'facilitytypeid' => self::read_id_or_null($item, 'facility_type_id'),
                'ownerid' => self::read_id_or_null($item, 'facility_owner_id'),
                'operationalstatusid' => self::read_id_or_null($item, 'facility_operational_status_id'),
                'regulatorystatusid' => self::read_id_or_null($item, 'facility_regulatory_status_id'),
                'dateopened' => self::read_timestamp($item, 'facility_date_opened'),
                'publishedat' => self::read_timestamp($item, 'published_date'),
                'remotecreatedat' => self::read_timestamp($item, 'created_at'),
                'remoteupdatedat' => self::read_timestamp($item, 'updated_at'),
                'codemappingjson' => facility_repository::encode_code_mapping(
                    self::read_code_mapping($item),
                ),
            ];
        }

        return $mapped;
    }

    /**
     * Read and validate the remote id of an item.
     *
     * @param array $item The remote item.
     * @param string $dataset Dataset name, used only in messages.
     * @param int|string $index Position in the payload, used only in messages.
     * @return int
     */
    protected static function read_id(array $item, string $dataset, int|string $index): int {
        return self::read_required_id($item, 'id', $dataset, $index);
    }

    /**
     * Read a required positive integer property.
     *
     * @param array $item The remote item.
     * @param string $key The remote property name.
     * @param string $dataset Dataset name, used only in messages.
     * @param int|string $index Position in the payload, used only in messages.
     * @return int
     */
    protected static function read_required_id(array $item, string $key, string $dataset, int|string $index): int {
        $value = $item[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && $value !== '' && ctype_digit($value))) {
            self::reject($dataset, $key, $index);
        }
        $id = (int) $value;
        if ($id <= 0) {
            self::reject($dataset, $key, $index);
        }

        return $id;
    }

    /**
     * Read an optional integer property.
     *
     * @param array $item The remote item.
     * @param string $key The remote property name.
     * @return int|null Null when the property is missing, null or not numeric.
     */
    protected static function read_id_or_null(array $item, string $key): ?int {
        $value = $item[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Read a required non-empty text property.
     *
     * @param array $item The remote item.
     * @param string $key The remote property name.
     * @param int $maxlength Width of the local column.
     * @param string $dataset Dataset name, used only in messages.
     * @param int|string $index Position in the payload, used only in messages.
     * @return string
     */
    protected static function read_required_text(
        array $item,
        string $key,
        int $maxlength,
        string $dataset,
        int|string $index,
    ): string {
        $value = $item[$key] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            self::reject($dataset, $key, $index);
        }
        $value = trim((string) $value);
        if ($value === '') {
            self::reject($dataset, $key, $index);
        }

        return \core_text::substr($value, 0, $maxlength);
    }

    /**
     * Read an optional text property.
     *
     * A missing property, an explicit null and an empty string all become null, so that the local
     * copy does not distinguish cases the remote service uses interchangeably.
     *
     * @param array $item The remote item.
     * @param string $key The remote property name.
     * @param int|null $maxlength Width of the local column, or null for an unbounded text column.
     * @return string|null
     */
    protected static function read_text(array $item, string $key, ?int $maxlength = null): ?string {
        $value = $item[$key] ?? null;
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $maxlength === null ? $value : \core_text::substr($value, 0, $maxlength);
    }

    /**
     * Read a remote date and convert it to a Unix timestamp.
     *
     * @param array $item The remote item.
     * @param string $key The remote property name.
     * @return int|null Null when absent, empty or unparseable.
     */
    protected static function read_timestamp(array $item, string $key): ?int {
        $value = $item[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($value)))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Read the facility_code_mapping sub-document.
     *
     * @param array $item The remote item.
     * @return array Empty when absent or not a structure.
     */
    protected static function read_code_mapping(array $item): array {
        $value = $item['facility_code_mapping'] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Refuse a payload that repeats a remote id.
     *
     * @param array $mapped Items mapped so far, keyed by remote id.
     * @param int $externalid The remote id just read.
     * @param string $dataset Dataset name, used only in messages.
     * @return void
     */
    protected static function reject_duplicate(array $mapped, int $externalid, string $dataset): void {
        if (array_key_exists($externalid, $mapped)) {
            throw new \moodle_exception('error:duplicateexternalid', 'local_mohhierarchy', '', (object) [
                'dataset' => $dataset,
                'id' => $externalid,
            ]);
        }
    }

    /**
     * Refuse an item that is missing a required property.
     *
     * @param string $dataset Dataset name.
     * @param string $field The remote property name.
     * @param int|string $index Position in the payload.
     * @return never
     */
    protected static function reject(string $dataset, string $field, int|string $index): never {
        throw new \moodle_exception('error:missingfield', 'local_mohhierarchy', '', (object) [
            'dataset' => $dataset,
            'field' => $field,
            'index' => (string) $index,
        ]);
    }
}
