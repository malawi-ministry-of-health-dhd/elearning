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

namespace local_mohhierarchy\local\source;

/**
 * The HTTP transport used to reach the remote hierarchy service.
 *
 * This seam exists so PHPUnit never calls the live service: tests inject a transport that replays
 * fixture files. Implementations are responsible for enforcing timeouts and TLS verification and
 * for rejecting unsuccessful HTTP status codes.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface transport {
    /**
     * Perform a GET request and return the response body.
     *
     * @param string $url Absolute http or https URL.
     * @return string The raw response body.
     */
    public function get(string $url): string;
}
