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

namespace local_mohhierarchy\local;

/**
 * Where a hierarchy assignment came from.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum assign_source: string {
    // Saved through the custom profile field on a user edit form.
    case USERFORM = 'userform';

    // Saved through a plugin administration page.
    case ADMIN = 'admin';

    // Created when importing pre-existing data.
    case MIGRATION = 'migration';

    // Created by automated reconciliation of the profile field and this table.
    case REPAIR = 'repair';

    // Created by a CLI script.
    case CLI = 'cli';

    /**
     * Convert a stored database value into a source, rejecting anything unrecognised.
     *
     * @param string $value The raw value.
     * @return self
     */
    public static function from_value(string $value): self {
        return self::tryFrom($value)
            ?? throw new \moodle_exception('error:invalidassignsource', 'local_mohhierarchy', '', s($value));
    }

    /**
     * All valid database values.
     *
     * @return string[]
     */
    public static function values(): array {
        return array_column(self::cases(), 'value');
    }
}
