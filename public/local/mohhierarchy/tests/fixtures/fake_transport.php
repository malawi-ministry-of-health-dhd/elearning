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

use local_mohhierarchy\local\source\transport;

/**
 * Replays canned responses instead of calling the live hierarchy service.
 *
 * A response value is either a string body, or a Throwable to be thrown, which is how the HTTP
 * error and transport failure cases are exercised without a network.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_mohhierarchy_fake_transport implements transport {
    /** @var string[] Every URL requested, in order, so tests can assert the synchronisation order. */
    public array $requested = [];

    /** @var array URL => response body or throwable. */
    protected array $responses;

    /**
     * Constructor.
     *
     * @param array $responses URL => response body string, or URL => Throwable to throw.
     */
    public function __construct(array $responses = []) {
        $this->responses = $responses;
    }

    #[\Override]
    public function get(string $url): string {
        $this->requested[] = $url;

        if (!array_key_exists($url, $this->responses)) {
            throw new \coding_exception('local_mohhierarchy_fake_transport has no response for ' . $url);
        }

        $response = $this->responses[$url];
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return (string) $response;
    }

    /**
     * Replace the response for one URL, used to run a second sync with a different payload.
     *
     * @param string $url The URL.
     * @param string|\Throwable $response The new response.
     * @return void
     */
    public function set_response(string $url, string|\Throwable $response): void {
        $this->responses[$url] = $response;
    }
}
