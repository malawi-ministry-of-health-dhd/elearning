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
 * Install steps for profilefield_mohhierarchy.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the hierarchy category and field, idempotently.
 *
 * Safe to call more than once: it never creates a second category or field, and it never rewrites a
 * field that already exists with this data type. If the short name is taken by a field of another
 * data type the install stops with a message an administrator can act on, rather than silently
 * adopting or overwriting somebody else's field.
 *
 * @return bool
 */
function xmldb_profilefield_mohhierarchy_install(): bool {
    global $CFG, $DB;

    // Visibility constants live in the profile library, which is not loaded automatically while
    // plugin installation callbacks run from CLI.
    require_once($CFG->dirroot . '/user/profile/lib.php');

    $shortname = 'mohfacility';
    $datatype = 'mohhierarchy';

    $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
    if ($existing !== false && $existing->datatype !== $datatype) {
        throw new moodle_exception('error:shortnameconflict', 'profilefield_mohhierarchy', '', (object) [
            'shortname' => s($shortname),
            'datatype' => s($existing->datatype),
        ]);
    }

    $categoryname = get_string('categoryname', 'profilefield_mohhierarchy');
    $categoryid = $DB->get_field('user_info_category', 'id', ['name' => $categoryname]);
    if ($categoryid === false) {
        $sortorder = (int) $DB->get_field_sql('SELECT MAX(sortorder) FROM {user_info_category}') + 1;
        $categoryid = $DB->insert_record('user_info_category', (object) [
            'name' => $categoryname,
            'sortorder' => $sortorder,
        ]);
    }

    if ($existing !== false) {
        // Already ours. Left exactly as the administrator has configured it.
        return true;
    }

    $sortorder = (int) $DB->get_field_sql(
        'SELECT MAX(sortorder) FROM {user_info_field} WHERE categoryid = :categoryid',
        ['categoryid' => $categoryid],
    ) + 1;

    $DB->insert_record('user_info_field', (object) [
        'shortname' => $shortname,
        'name' => get_string('fieldname', 'profilefield_mohhierarchy'),
        'datatype' => $datatype,
        'description' => get_string('fielddescription', 'profilefield_mohhierarchy'),
        'descriptionformat' => FORMAT_HTML,
        'categoryid' => $categoryid,
        'sortorder' => $sortorder,
        // Requiredness is enforced in edit_validate_field(), and only when creating a user, so the
        // core required rule is left off.
        'required' => 0,
        // Editability is decided by profile_field_mohhierarchy::is_editable(), which is stricter
        // than the locked flag: locked only asks for moodle/user:update, while the field class also
        // requires the local capability and a matching hierarchy scope, and refuses self editing.
        'locked' => 0,
        'visible' => PROFILE_VISIBLE_PRIVATE,
        'forceunique' => 0,
        // Never on public signup: a self registering visitor has no creator whose scope could be
        // checked.
        'signup' => 0,
        'defaultdata' => '',
        'defaultdataformat' => FORMAT_MOODLE,
        'param1' => null,
        'param2' => null,
        'param3' => null,
        'param4' => null,
        'param5' => null,
    ]);

    return true;
}
