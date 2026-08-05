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

namespace profilefield_mohhierarchy\local;

use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\hierarchy_scope;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\hierarchy\selector_options;

/**
 * Turns the local hierarchy into option lists for the form and the AJAX endpoints.
 *
 * This class holds no policy of its own. Every list it returns comes from
 * local_mohhierarchy's permission service, so the form and the external functions cannot disagree
 * about what an actor may see. It exists so the two callers share one implementation.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hierarchy_options {
    /** @var permission_service Decides what the actor may see. */
    protected permission_service $permissions;

    /** @var assignment_service Reads the canonical assignment. */
    protected assignment_service $assignments;

    /** @var facility_repository Facility lookups for the display fallback. */
    protected facility_repository $facilities;

    /** @var selector_options Shared scope-filtered selector options. */
    protected selector_options $selectors;

    /**
     * Constructor.
     *
     * @param permission_service|null $permissions Injectable for tests.
     * @param assignment_service|null $assignments Injectable for tests.
     * @param facility_repository|null $facilities Injectable for tests.
     */
    public function __construct(
        ?permission_service $permissions = null,
        ?assignment_service $assignments = null,
        ?facility_repository $facilities = null,
    ) {
        $this->permissions = $permissions ?? new permission_service();
        $this->assignments = $assignments ?? new assignment_service();
        $this->facilities = $facilities ?? new facility_repository();
        $this->selectors = new selector_options($this->permissions);
    }

    /**
     * The zones the actor may choose from.
     *
     * @param int $actorid The acting user.
     * @return string[] Local zone id => formatted name.
     */
    public function zones(int $actorid): array {
        return $this->selectors->zones($actorid);
    }

    /**
     * The districts of one zone that the actor may choose from.
     *
     * @param int $actorid The acting user.
     * @param int $zoneid The zone being browsed.
     * @return string[] Local district id => formatted name.
     */
    public function districts(int $actorid, int $zoneid): array {
        return $this->selectors->districts($actorid, $zoneid);
    }

    /**
     * The facilities of one district that the actor may choose from.
     *
     * @param int $actorid The acting user.
     * @param int $districtid The district being browsed.
     * @return string[] Local facility id => formatted name.
     */
    public function facilities(int $actorid, int $districtid): array {
        return $this->selectors->facilities($actorid, $districtid);
    }

    /**
     * All allowed facilities with the District and Zone each one derives.
     *
     * @param int $actorid The acting user.
     * @return array{
     *     facilities: string[],
     *     districts: string[],
     *     paths: array<int, array{zoneid: int, districtid: int}>
     * }
     */
    public function facility_picker(int $actorid): array {
        return $this->selectors->facility_picker($actorid);
    }

    /**
     * The actor's resolved scope.
     *
     * @param int $actorid The acting user.
     * @return hierarchy_scope
     */
    public function scope(int $actorid): hierarchy_scope {
        return $this->permissions->get_scope($actorid);
    }

    /**
     * Which levels are fixed for this actor rather than selectable.
     *
     * A site administrator chooses all three. Everyone else is anchored on their own zone, and
     * narrower scopes fix more levels.
     *
     * @param int $actorid The acting user.
     * @return array{zone: bool, district: bool, facility: bool}
     */
    public function fixed_levels(int $actorid): array {
        return $this->selectors->fixed_levels($actorid);
    }

    /**
     * Work out which zone, district and facility a form should start on.
     *
     * The canonical assignment wins. When there is no assignment but the stored profile field value
     * names a real facility, the zone and district are derived from that facility so the form still
     * shows the truth; reconciling the two is the repair service's job, not the form's, because
     * rendering a form must not write.
     *
     * @param int $userid The user being edited, zero or negative when creating.
     * @param mixed $fielddata The raw stored profile field value, if any.
     * @return \stdClass|null Null when there is nothing to preselect.
     */
    public function initial_selection(int $userid, mixed $fielddata = null): ?\stdClass {
        if ($userid > 0) {
            $assignment = $this->assignments->get_assignment_with_names($userid);
            if ($assignment !== null) {
                return (object) [
                    'facilityid' => (int) $assignment->facilityid,
                    'districtid' => (int) $assignment->districtid,
                    'zoneid' => (int) $assignment->zoneid,
                    'facilityname' => $assignment->facilityname,
                    'districtname' => $assignment->districtname,
                    'zonename' => $assignment->zonename,
                    'facilityactive' => (int) $assignment->facilityactive === 1,
                    'fromassignment' => true,
                ];
            }
        }

        return $this->describe_facility($this->clean_facility_id($fielddata));
    }

    /**
     * Resolve one facility into its full hierarchy, for display or preselection.
     *
     * @param int $facilityid Local facility id.
     * @return \stdClass|null Null when the facility does not exist.
     */
    public function describe_facility(int $facilityid): ?\stdClass {
        if ($facilityid <= 0) {
            return null;
        }
        $ancestors = $this->facilities->get_with_ancestors($facilityid);
        if ($ancestors === null) {
            return null;
        }

        return (object) [
            'facilityid' => (int) $ancestors->facilityid,
            'districtid' => (int) $ancestors->districtid,
            'zoneid' => (int) $ancestors->zoneid,
            'facilityname' => $ancestors->facilityname,
            'districtname' => $ancestors->districtname,
            'zonename' => $ancestors->zonename,
            'facilityactive' => (int) $ancestors->facilityactive === 1,
            'fromassignment' => false,
        ];
    }

    /**
     * Render one selection as "Zone / District / Facility".
     *
     * @param \stdClass|null $selection The output of initial_selection() or describe_facility().
     * @return string Empty string when there is nothing to show.
     */
    public function format_path(?\stdClass $selection): string {
        if ($selection === null) {
            return '';
        }
        $context = \context_system::instance();
        $parts = array_map(
            static fn(string $name): string => format_string($name, true, ['context' => $context]),
            [$selection->zonename, $selection->districtname, $selection->facilityname],
        );
        $path = implode(' / ', $parts);
        if (!$selection->facilityactive) {
            $path = get_string('inactivesuffix', 'profilefield_mohhierarchy', $path);
        }

        return $path;
    }

    /**
     * Clean a value that may have come from a browser into a facility id.
     *
     * @param mixed $value The submitted or stored value.
     * @return int Zero when the value is not a positive integer.
     */
    public function clean_facility_id(mixed $value): int {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (!is_string($value) || trim($value) === '' || !ctype_digit(trim($value))) {
            return 0;
        }

        return (int) clean_param(trim($value), PARAM_INT);
    }
}
