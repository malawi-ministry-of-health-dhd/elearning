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
 * One actor's resolved hierarchy scope.
 *
 * This is the whole input to a scope decision: the level, plus the zone, district and facility the
 * level is measured against. It is deliberately small and flat so it can live in a simpledata
 * cache, and immutable so a cached instance cannot be edited by a caller.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hierarchy_scope {
    /** @var scope_level Delegated management level. */
    public readonly scope_level $level;

    /** @var int|null Zone anchoring the scope. */
    public readonly ?int $zoneid;

    /** @var int|null District anchoring the scope. */
    public readonly ?int $districtid;

    /** @var int|null Facility anchoring the scope. */
    public readonly ?int $facilityid;

    /**
     * Constructor.
     *
     * @param scope_level $level The delegated management level.
     * @param int|null $zoneid The actor's zone, null when there is no assignment.
     * @param int|null $districtid The actor's district, null when there is no assignment.
     * @param int|null $facilityid The actor's facility, null when there is no assignment.
     */
    public function __construct(
        scope_level $level,
        ?int $zoneid = null,
        ?int $districtid = null,
        ?int $facilityid = null,
    ) {
        $this->level = $level;
        $this->zoneid = $zoneid;
        $this->districtid = $districtid;
        $this->facilityid = $facilityid;
    }

    /**
     * The scope of a user with no active assignment: no hierarchy management at all.
     *
     * @return self
     */
    public static function none(): self {
        return new self(scope_level::NONE);
    }

    /**
     * Build a scope from an assignment row.
     *
     * @param \stdClass $assignment A local_mohh_assign row.
     * @return self
     */
    public static function from_assignment(\stdClass $assignment): self {
        return new self(
            scope_level::from_value($assignment->scopelevel),
            (int) $assignment->zoneid,
            (int) $assignment->districtid,
            (int) $assignment->facilityid,
        );
    }

    /**
     * Whether this scope delegates any hierarchy management.
     *
     * A scope with no assignment behind it never does, whatever the level says.
     *
     * @return bool
     */
    public function grants_management(): bool {
        return $this->level->grants_management() && $this->zoneid !== null;
    }

    /**
     * Reduce to a plain array for the cache.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'level' => $this->level->value,
            'zoneid' => $this->zoneid,
            'districtid' => $this->districtid,
            'facilityid' => $this->facilityid,
        ];
    }

    /**
     * Rebuild from a cached array.
     *
     * @param array $data The output of to_array().
     * @return self
     */
    public static function from_array(array $data): self {
        return new self(
            scope_level::from_value((string) $data['level']),
            $data['zoneid'] === null ? null : (int) $data['zoneid'],
            $data['districtid'] === null ? null : (int) $data['districtid'],
            $data['facilityid'] === null ? null : (int) $data['facilityid'],
        );
    }
}
