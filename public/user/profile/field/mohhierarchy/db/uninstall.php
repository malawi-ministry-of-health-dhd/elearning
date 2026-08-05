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
 * Uninstall handling for profilefield_mohhierarchy.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove this plugin's field definitions, and nothing else.
 *
 * Deliberately does not touch:
 *
 * - Moodle users. Removing a profile field removes a placement record, not an account, and every
 *   user keeps working afterwards exactly as a user with no hierarchy does.
 * - The canonical assignment table, which belongs to local_mohhierarchy and survives this plugin.
 * - The hierarchy category, which an administrator may have put other fields into.
 * - The assignment history, which is never pruned by either plugin.
 *
 * The field rows and their user data are removed because a profile field whose data type no longer
 * has an implementing plugin cannot be rendered, edited or deleted through the interface.
 *
 * @return bool
 */
function xmldb_profilefield_mohhierarchy_uninstall(): bool {
    global $DB;

    $fieldids = $DB->get_fieldset_select('user_info_field', 'id', 'datatype = :datatype', [
        'datatype' => 'mohhierarchy',
    ]);
    if ($fieldids) {
        [$insql, $params] = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED, 'field');
        $DB->delete_records_select('user_info_data', "fieldid {$insql}", $params);
        $DB->delete_records_select('user_info_field', "id {$insql}", $params);
    }

    return true;
}
