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
 * Shared, scope-filtered option lists for hierarchy selectors.
 *
 * Both the custom profile field and the strict delegated creation form use this class. It contains
 * presentation conversion only; permission_service remains the security boundary and every save
 * revalidates the submitted facility independently.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selector_options {
    /** @var permission_service Decides what the actor may see. */
    protected permission_service $permissions;

    /**
     * Constructor.
     *
     * @param permission_service|null $permissions Injectable for tests.
     */
    public function __construct(?permission_service $permissions = null) {
        $this->permissions = $permissions ?? new permission_service();
    }

    /**
     * The zones the actor may choose from.
     *
     * @param int $actorid The acting user.
     * @return string[] Local zone id => formatted name.
     */
    public function zones(int $actorid): array {
        return $this->to_menu($this->permissions->get_allowed_zones($actorid));
    }

    /**
     * The districts of one allowed zone.
     *
     * @param int $actorid The acting user.
     * @param int $zoneid Local zone id.
     * @return string[] Local district id => formatted name.
     */
    public function districts(int $actorid, int $zoneid): array {
        if ($zoneid <= 0) {
            return [];
        }

        return $this->to_menu($this->permissions->get_allowed_districts($actorid, $zoneid));
    }

    /**
     * The facilities of one allowed district.
     *
     * @param int $actorid The acting user.
     * @param int $districtid Local district id.
     * @return string[] Local facility id => formatted name.
     */
    public function facilities(int $actorid, int $districtid): array {
        if ($districtid <= 0) {
            return [];
        }

        return $this->to_menu($this->permissions->get_allowed_facilities($actorid, $districtid));
    }

    /**
     * Facility-first picker data with the ancestors needed by the browser.
     *
     * @param int $actorid The acting user.
     * @return array{
     *     facilities: string[],
     *     districts: string[],
     *     paths: array<int, array{zoneid: int, districtid: int}>
     * }
     */
    public function facility_picker(int $actorid): array {
        $context = \context_system::instance();
        $facilities = [];
        $districts = [];
        $paths = [];

        foreach ($this->permissions->get_allowed_facility_paths($actorid) as $row) {
            $facilityname = format_string($row->facilityname, true, ['context' => $context]);
            $districtname = format_string($row->districtname, true, ['context' => $context]);
            $facilityid = (int) $row->facilityid;
            $districtid = (int) $row->districtid;

            $facilities[$facilityid] = $facilityname;
            $districts[$districtid] = $districtname;
            $paths[$facilityid] = [
                'zoneid' => (int) $row->zoneid,
                'districtid' => $districtid,
            ];
        }

        return [
            'facilities' => $facilities,
            'districts' => $districts,
            'paths' => $paths,
        ];
    }

    /**
     * Which levels are fixed for an actor rather than selectable.
     *
     * @param int $actorid The acting user.
     * @return array{zone: bool, district: bool, facility: bool}
     */
    public function fixed_levels(int $actorid): array {
        if (is_siteadmin($actorid)) {
            return ['zone' => false, 'district' => false, 'facility' => false];
        }
        $level = $this->permissions->get_scope($actorid)->level;

        return [
            'zone' => true,
            'district' => $level !== scope_level::ZONE,
            'facility' => $level === scope_level::FACILITY,
        ];
    }

    /**
     * Default ids anchored on the actor's own scope.
     *
     * @param int $actorid The acting user.
     * @return array{zone: int, district: int, facility: int}
     */
    public function defaults(int $actorid): array {
        if (is_siteadmin($actorid)) {
            return ['zone' => 0, 'district' => 0, 'facility' => 0];
        }
        $scope = $this->permissions->get_scope($actorid);

        return [
            'zone' => $scope->zoneid,
            'district' => $scope->districtid,
            'facility' => $scope->facilityid,
        ];
    }

    /**
     * Reduce hierarchy rows to a safely formatted form menu.
     *
     * @param \stdClass[] $rows Rows carrying id and name.
     * @return string[]
     */
    protected function to_menu(array $rows): array {
        $context = \context_system::instance();
        $menu = [];
        foreach ($rows as $row) {
            $menu[(int) $row->id] = format_string($row->name, true, ['context' => $context]);
        }

        return $menu;
    }
}
