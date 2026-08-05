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

/**
 * Test data generator for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\local\hierarchy\district_repository;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\zone_repository;

/**
 * Test data generator for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_mohhierarchy_generator extends \component_generator_base {
    /** @var int Counter used to keep generated remote identifiers unique. */
    protected int $counter = 0;

    #[\Override]
    public function reset() {
        $this->counter = 0;
    }

    /**
     * Create a zone.
     *
     * @param array $record Optional overrides: externalid, name, description.
     * @return stdClass The stored zone.
     */
    public function create_zone(array $record = []): stdClass {
        $this->counter++;
        $repository = new zone_repository();
        $data = (object) ($record + [
            'externalid' => 9000 + $this->counter,
            'name' => 'Zone ' . $this->counter,
            'description' => null,
        ]);
        $result = $repository->upsert($data);

        return $repository->get_by_id($result['id']);
    }

    /**
     * Create a district, creating its zone when none is given.
     *
     * @param array $record Optional overrides: zoneid, externalid, name, code.
     * @return stdClass The stored district.
     */
    public function create_district(array $record = []): stdClass {
        $this->counter++;
        $repository = new district_repository();
        $record += [
            'externalid' => 8000 + $this->counter,
            'name' => 'District ' . $this->counter,
            'code' => 'D' . $this->counter,
        ];
        // Only create a parent zone when the caller did not supply one.
        $record['zoneid'] ??= (int) $this->create_zone()->id;
        $result = $repository->upsert((object) $record);

        return $repository->get_by_id($result['id']);
    }

    /**
     * Create a facility, creating its district and zone when none is given.
     *
     * @param array $record Optional overrides for any syncable facility field.
     * @return stdClass The stored facility.
     */
    public function create_facility(array $record = []): stdClass {
        $this->counter++;
        $repository = new facility_repository();
        $record += [
            'externalid' => 7000 + $this->counter,
            'name' => 'Facility ' . $this->counter,
            'code' => 'F' . $this->counter,
            'codedhis2' => null,
            'codeopenlmis' => null,
            'commonname' => null,
            'registrationnumber' => null,
            'facilitytypeid' => null,
            'ownerid' => null,
            'operationalstatusid' => null,
            'regulatorystatusid' => null,
            'dateopened' => null,
            'publishedat' => null,
            'remotecreatedat' => null,
            'remoteupdatedat' => null,
            'codemappingjson' => null,
        ];
        // Only create a parent district when the caller did not supply one.
        $record['districtid'] ??= (int) $this->create_district()->id;
        $result = $repository->upsert((object) $record);

        return $repository->get_by_id($result['id']);
    }
}
