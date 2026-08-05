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
 * The outcome of a synchronisation run.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
enum sync_status: string {
    // The run has started and has not reported an outcome yet.
    case RUNNING = 'running';

    // The run completed and the local copy was updated.
    case SUCCESS = 'success';

    // The run aborted. The previous local copy is left in place.
    case FAILED = 'failed';

    /**
     * Convert a stored database value into a status, rejecting anything unrecognised.
     *
     * @param string $value The raw value.
     * @return self
     */
    public static function from_value(string $value): self {
        return self::tryFrom($value)
            ?? throw new \moodle_exception('error:invalidsyncstatus', 'local_mohhierarchy', '', s($value));
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
