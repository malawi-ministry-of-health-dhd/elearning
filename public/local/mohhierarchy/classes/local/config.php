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
 * The plugin's synchronisation settings, as an injectable value object.
 *
 * Reading settings happens in exactly one place, create(), so tests build a config directly and
 * never touch the site configuration. This class also owns the bearer token, which means it also
 * owns redact(): the token must never reach a log, an exception message or the sync log table.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Plugin name used for get_config(). */
    public const COMPONENT = 'local_mohhierarchy';

    /** @var string Default Zipatala API base URL. */
    public const DEFAULT_BASE_URL = 'https://zipatala.health.gov.mw/api';

    /** @var string Default zones endpoint, relative to the base URL. */
    public const DEFAULT_ZONES_ENDPOINT = '/zones';

    /** @var string Default districts endpoint, relative to the base URL. */
    public const DEFAULT_DISTRICTS_ENDPOINT = '/districts';

    /** @var string Default facilities endpoint, relative to the base URL. */
    public const DEFAULT_FACILITIES_ENDPOINT = '/facilities';

    /** @var int Default connection timeout in seconds. */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    /** @var int Default total request timeout in seconds. */
    public const DEFAULT_REQUEST_TIMEOUT = 120;

    /** @var string Placeholder substituted for the token in any human readable text. */
    public const REDACTED = '[token redacted]';

    /** @var string Zipatala API base URL. */
    protected string $baseurl;

    /** @var string Zones endpoint path or URL. */
    protected string $zonesendpoint;

    /** @var string Districts endpoint path or URL. */
    protected string $districtsendpoint;

    /** @var string Facilities endpoint path or URL. */
    protected string $facilitiesendpoint;

    /** @var string Optional bearer token. */
    protected string $token;

    /** @var int Connection timeout in seconds. */
    protected int $connecttimeout;

    /** @var int Total request timeout in seconds. */
    protected int $requesttimeout;

    /** @var bool Whether scheduled synchronisation is enabled. */
    protected bool $scheduledsyncenabled;

    /**
     * Constructor.
     *
     * @param string $baseurl Base URL of the Zipatala API.
     * @param string $zonesendpoint Zones endpoint, absolute or relative to the base URL.
     * @param string $districtsendpoint Districts endpoint.
     * @param string $facilitiesendpoint Facilities endpoint.
     * @param string $token Optional bearer token. Empty means the API is called unauthenticated.
     * @param int $connecttimeout Connection timeout in seconds.
     * @param int $requesttimeout Total request timeout in seconds.
     * @param bool $scheduledsyncenabled Whether the scheduled task should do any work.
     */
    public function __construct(
        string $baseurl = self::DEFAULT_BASE_URL,
        string $zonesendpoint = self::DEFAULT_ZONES_ENDPOINT,
        string $districtsendpoint = self::DEFAULT_DISTRICTS_ENDPOINT,
        string $facilitiesendpoint = self::DEFAULT_FACILITIES_ENDPOINT,
        string $token = '',
        int $connecttimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $requesttimeout = self::DEFAULT_REQUEST_TIMEOUT,
        bool $scheduledsyncenabled = true,
    ) {
        $this->baseurl = $baseurl;
        $this->zonesendpoint = $zonesendpoint;
        $this->districtsendpoint = $districtsendpoint;
        $this->facilitiesendpoint = $facilitiesendpoint;
        $this->token = $token;
        $this->connecttimeout = $connecttimeout;
        $this->requesttimeout = $requesttimeout;
        $this->scheduledsyncenabled = $scheduledsyncenabled;
    }

    /**
     * Build the configuration from the site settings, falling back to the documented defaults.
     *
     * @return self
     */
    public static function create(): self {
        $get = static function (string $name, string|int $default): string|int {
            $value = get_config(self::COMPONENT, $name);
            if ($value === false || $value === null || $value === '') {
                return $default;
            }
            return is_int($default) ? (int) $value : (string) $value;
        };

        return new self(
            (string) $get('baseurl', self::DEFAULT_BASE_URL),
            (string) $get('zonesendpoint', self::DEFAULT_ZONES_ENDPOINT),
            (string) $get('districtsendpoint', self::DEFAULT_DISTRICTS_ENDPOINT),
            (string) $get('facilitiesendpoint', self::DEFAULT_FACILITIES_ENDPOINT),
            (string) $get('token', ''),
            (int) $get('connecttimeout', self::DEFAULT_CONNECT_TIMEOUT),
            (int) $get('requesttimeout', self::DEFAULT_REQUEST_TIMEOUT),
            (bool) $get('scheduledsyncenabled', 1),
        );
    }

    /**
     * The zones endpoint URL.
     *
     * @return string
     */
    public function zones_url(): string {
        return $this->url_for($this->zonesendpoint);
    }

    /**
     * The districts endpoint URL.
     *
     * @return string
     */
    public function districts_url(): string {
        return $this->url_for($this->districtsendpoint);
    }

    /**
     * The facilities endpoint URL.
     *
     * @return string
     */
    public function facilities_url(): string {
        return $this->url_for($this->facilitiesendpoint);
    }

    /**
     * The optional bearer token.
     *
     * @return string Empty when no token is configured.
     */
    public function token(): string {
        return $this->token;
    }

    /**
     * Whether a bearer token is configured.
     *
     * @return bool
     */
    public function has_token(): bool {
        return trim($this->token) !== '';
    }

    /**
     * Connection timeout in seconds.
     *
     * @return int
     */
    public function connect_timeout(): int {
        return max(1, $this->connecttimeout);
    }

    /**
     * Total request timeout in seconds.
     *
     * @return int
     */
    public function request_timeout(): int {
        return max(1, $this->requesttimeout);
    }

    /**
     * Whether the scheduled task should synchronise.
     *
     * @return bool
     */
    public function scheduled_sync_enabled(): bool {
        return $this->scheduledsyncenabled;
    }

    /**
     * Remove the bearer token from any text that is about to be shown, logged or stored.
     *
     * Nothing in this plugin puts the token into a message in the first place. This is a second
     * line of defence for text that came from cURL, from the remote service or from a third party
     * library, where a token could appear inside a URL or a header dump.
     *
     * @param string $text The text to sanitise.
     * @return string
     */
    public function redact(string $text): string {
        if (!$this->has_token()) {
            return $text;
        }

        return str_replace(trim($this->token), self::REDACTED, $text);
    }

    /**
     * Turn a configured endpoint into an absolute, validated URL.
     *
     * An endpoint may be given as a path relative to the base URL, which is the normal case, or as
     * a complete URL, which keeps a mis-pasted full URL from producing a nonsense address.
     *
     * @param string $endpoint The configured endpoint.
     * @return string
     */
    protected function url_for(string $endpoint): string {
        $endpoint = trim($endpoint);
        if (preg_match('#^https?://#i', $endpoint)) {
            $url = $endpoint;
        } else {
            $url = rtrim(trim($this->baseurl), '/') . '/' . ltrim($endpoint, '/');
        }
        $url = clean_param($url, PARAM_URL);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \moodle_exception('error:invalidendpoint', 'local_mohhierarchy', '', s($endpoint));
        }

        return $url;
    }
}
