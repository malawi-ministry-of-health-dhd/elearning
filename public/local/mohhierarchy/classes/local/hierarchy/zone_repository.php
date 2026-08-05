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
 * Database access for the local copy of the zones.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zone_repository extends reference_repository {
    /** @var string The table this repository owns. */
    public const TABLE = 'local_mohh_zone';

    #[\Override]
    public function get_table(): string {
        return self::TABLE;
    }

    #[\Override]
    public function get_syncable_fields(): array {
        return ['name', 'description'];
    }

    /**
     * List zones for display and for building selectors.
     *
     * @param bool $activeonly Whether to exclude deactivated zones.
     * @return \stdClass[] Keyed by local id, sorted by name.
     */
    public function get_all(bool $activeonly = true): array {
        global $DB;

        return $DB->get_records(self::TABLE, $activeonly ? ['active' => 1] : [], 'name ASC');
    }
}
