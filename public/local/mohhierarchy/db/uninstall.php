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
 * Uninstall handling for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run before Moodle drops this plugin's tables.
 *
 * Deliberately does nothing to Moodle's own data:
 *
 * - Moodle users are never touched. Uninstalling removes hierarchy assignments, not accounts, and
 *   every user keeps working afterwards exactly as a user with no assignment does.
 * - Custom profile field definitions and their values belong to profilefield_mohhierarchy and to
 *   core, and are left alone here.
 * - Assignment history is never pruned by the plugin itself. Core drops local_mohh_assignlog as
 *   part of the explicit uninstall the administrator asked for; nothing else deletes it, so
 *   administrators who need to keep the audit trail should export it before uninstalling.
 *
 * Core removes the plugin's tables from db/install.xml, its settings, its scheduled tasks and its
 * capabilities, so there is nothing left for this function to clean up.
 *
 * @return bool
 */
function xmldb_local_mohhierarchy_uninstall(): bool {
    return true;
}
