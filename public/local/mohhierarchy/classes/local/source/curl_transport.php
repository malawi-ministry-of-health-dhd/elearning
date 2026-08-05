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

use local_mohhierarchy\local\config;

/**
 * HTTP transport built on Moodle's cURL wrapper.
 *
 * Moodle's \curl is used rather than file_get_contents() so that the site's proxy settings, the
 * curlsecurityblockedhosts allow list and the shared CA bundle all apply.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_transport implements transport {
    /** @var config Supplies timeouts, credentials and redaction. */
    protected config $config;

    /**
     * Constructor.
     *
     * @param config $config Supplies the timeouts and the optional bearer token.
     */
    public function __construct(config $config) {
        $this->config = $config;
    }

    #[\Override]
    public function get(string $url): string {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $headers = ['Accept: application/json'];
        if ($this->config->has_token()) {
            // The header is set here and nowhere else, and is never echoed, logged or stored.
            $headers[] = 'Authorization: Bearer ' . $this->config->token();
        }
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_CONNECTTIMEOUT' => $this->config->connect_timeout(),
            'CURLOPT_TIMEOUT' => $this->config->request_timeout(),
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_RETURNTRANSFER' => 1,
            // Moodle's \curl::resetopt() defaults CURLOPT_SSL_VERIFYPEER to 0, so both verification
            // options are set explicitly here. There is deliberately no setting to turn them off.
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ];

        $body = $curl->get($url, [], $options);

        if ($curl->get_errno()) {
            throw new \moodle_exception('error:transportfailed', 'local_mohhierarchy', '', (object) [
                'url' => $this->safe_url($url),
                'detail' => $this->config->redact(clean_param((string) $curl->error, PARAM_NOTAGS)),
            ]);
        }

        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($status < 200 || $status > 299) {
            throw new \moodle_exception('error:httpstatus', 'local_mohhierarchy', '', (object) [
                'url' => $this->safe_url($url),
                'status' => $status,
            ]);
        }

        if (!is_string($body) || trim($body) === '') {
            throw new \moodle_exception('error:emptyresponse', 'local_mohhierarchy', '', $this->safe_url($url));
        }

        return $body;
    }

    /**
     * A URL that is safe to put in a message: query string dropped, token redacted.
     *
     * @param string $url The requested URL.
     * @return string
     */
    protected function safe_url(string $url): string {
        $stripped = strtok($url, '?');

        return s($this->config->redact($stripped === false ? '' : $stripped));
    }
}
