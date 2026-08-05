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
 * Lists the districts of one zone that the calling user may choose from.
 *
 * Reads the local copy of the hierarchy only; the remote service is never contacted here. The
 * result is whatever local_mohhierarchy's permission service allows, which is empty for a zone the
 * caller may not use, so a district from another zone can never be returned.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_districts extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'zoneid' => new external_value(PARAM_INT, 'Local zone id'),
        ]);
    }

    /**
     * List the allowed districts of a zone.
     *
     * @param int $zoneid Local zone id.
     * @return array[] One entry per district, each with a local id and a formatted name.
     */
    public static function execute(int $zoneid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['zoneid' => $zoneid]);
        // The hierarchy is site wide. validate_context() also performs the login check.
        self::validate_context(\context_system::instance());

        $districts = (new hierarchy_options())->districts((int) $USER->id, $params['zoneid']);

        $result = [];
        foreach ($districts as $id => $name) {
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
                'id' => new external_value(PARAM_INT, 'Local district id'),
                'name' => new external_value(PARAM_TEXT, 'District name, already formatted'),
            ]),
            'Districts the caller may choose from, active only',
        );
    }
}
