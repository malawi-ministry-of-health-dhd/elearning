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
 * Library callbacks for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add an audited hierarchy assignment action to another user's navigation.
 *
 * @param navigation_node $navigation The user navigation node to extend.
 * @param stdClass $user The user being viewed or edited.
 * @param context_user $usercontext The target user's context.
 * @param stdClass $course The current course.
 * @param context $coursecontext The current course or system context.
 * @return void
 */
function local_mohhierarchy_extend_navigation_user(
    navigation_node $navigation,
    stdClass $user,
    context_user $usercontext,
    stdClass $course,
    context $coursecontext,
): void {
    global $USER;

    $permissions = new \local_mohhierarchy\local\hierarchy\permission_service();
    if (!$permissions->can_manage_assignment((int) $USER->id, (int) $user->id)) {
        return;
    }

    $navigation->add(
        get_string('hierarchyassignmentaction', 'local_mohhierarchy'),
        new moodle_url('/local/mohhierarchy/assignments.php', ['userid' => (int) $user->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_mohhierarchy_transfer',
        new pix_icon('t/move', ''),
    );
}
