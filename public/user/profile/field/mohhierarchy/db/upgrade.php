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
 * Upgrade steps for profilefield_mohhierarchy.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade profilefield_mohhierarchy.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
function xmldb_profilefield_mohhierarchy_upgrade(int $oldversion): bool {
    if ($oldversion < 2026073001) {
        // Some development installations registered the plugin before its install callback existed.
        // Reusing the idempotent installer creates the missing field without changing an existing
        // hierarchy field, category, or any unrelated custom profile fields.
        require_once(__DIR__ . '/install.php');
        xmldb_profilefield_mohhierarchy_install();

        upgrade_plugin_savepoint(true, 2026073001, 'profilefield', 'mohhierarchy');
    }

    if ($oldversion < 2026073002) {
        // Facility-first placement changes presentation only; no profile values need migration.
        upgrade_plugin_savepoint(true, 2026073002, 'profilefield', 'mohhierarchy');
    }

    if ($oldversion < 2026073003) {
        // Searchable bidirectional placement changes presentation only; no values need migration.
        upgrade_plugin_savepoint(true, 2026073003, 'profilefield', 'mohhierarchy');
    }

    if ($oldversion < 2026080500) {
        // Facility-only autocomplete labels change presentation only; no values need migration.
        upgrade_plugin_savepoint(true, 2026080500, 'profilefield', 'mohhierarchy');
    }

    if ($oldversion < 2026080501) {
        // Searchable assignment placement changes presentation only; no values need migration.
        upgrade_plugin_savepoint(true, 2026080501, 'profilefield', 'mohhierarchy');
    }

    if ($oldversion < 2026080502) {
        // Blank required placement on core user creation changes validation only; no values need migration.
        upgrade_plugin_savepoint(true, 2026080502, 'profilefield', 'mohhierarchy');
    }

    return true;
}
