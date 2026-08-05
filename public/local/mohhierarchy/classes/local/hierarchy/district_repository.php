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

/**
 * Database access for the local copy of the districts.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class district_repository extends reference_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_district';

    #[\Override]
    public function get_table(): string {
        return self::TABLE;
    }

    #[\Override]
    public function get_syncable_fields(): array {
        return ['zoneid', 'name', 'code'];
    }

    /**
     * List the districts of one zone.
     *
     * @param int $zoneid Local zone id.
     * @param bool $activeonly Whether to exclude deactivated districts.
     * @return \stdClass[] Keyed by local id, sorted by name.
     */
    public function get_by_zone(int $zoneid, bool $activeonly = true): array {
        global $DB;

        $conditions = ['zoneid' => $zoneid];
        if ($activeonly) {
            $conditions['active'] = 1;
        }

        return $DB->get_records(self::TABLE, $conditions, 'name ASC');
    }

    /**
     * Whether a district really sits in a zone.
     *
     * Used to re-check, on the server, a zone and district pair that arrived from a browser.
     *
     * @param int $districtid Local district id.
     * @param int $zoneid Local zone id.
     * @return bool
     */
    public function belongs_to_zone(int $districtid, int $zoneid): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['id' => $districtid, 'zoneid' => $zoneid]);
    }
}
