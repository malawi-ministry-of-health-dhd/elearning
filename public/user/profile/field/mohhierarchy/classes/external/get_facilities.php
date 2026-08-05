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

namespace profilefield_mohhierarchy\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use profilefield_mohhierarchy\local\hierarchy_options;

/**
 * Lists the facilities of one district that the calling user may choose from.
 *
 * Reads the local copy of the hierarchy only; the remote service is never contacted here. Inactive
 * facilities are never returned, and a district outside the caller's scope yields an empty list, so
 * a facility from another district can never be returned.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_facilities extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'districtid' => new external_value(PARAM_INT, 'Local district id'),
        ]);
    }

    /**
     * List the allowed facilities of a district.
     *
     * @param int $districtid Local district id.
     * @return array[] One entry per facility, each with a local id and a formatted name.
     */
    public static function execute(int $districtid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['districtid' => $districtid]);
        // The hierarchy is site wide. validate_context() also performs the login check.
        self::validate_context(\context_system::instance());

        $facilities = (new hierarchy_options())->facilities((int) $USER->id, $params['districtid']);

        $result = [];
        foreach ($facilities as $id => $name) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $result;
    }

    /**
     * Return description.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Local facility id'),
                'name' => new external_value(PARAM_TEXT, 'Facility name, already formatted'),
            ]),
            'Facilities the caller may choose from, active only',
        );
    }
}
