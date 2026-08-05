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
 * Reads the three Zipatala datasets and hands back plain decoded items.
 *
 * The client's only job is to obtain a list of item arrays and to refuse anything it does not
 * recognise. Field mapping is the mapper's job; persistence is the sync service's job.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class zipatala_client {
    /** @var string[] The only object wrappers accepted around the item list. */
    public const WRAPPER_KEYS = ['data', 'results'];

    /** @var int Maximum JSON nesting depth accepted. */
    protected const JSON_DEPTH = 32;

    /** @var transport Injectable HTTP transport. */
    protected transport $transport;

    /** @var config Supplies endpoint URLs and redaction. */
    protected config $config;

    /**
     * Constructor.
     *
     * @param transport $transport The injectable HTTP transport.
     * @param config $config Supplies the endpoint URLs.
     */
    public function __construct(transport $transport, config $config) {
        $this->transport = $transport;
        $this->config = $config;
    }

    /**
     * Fetch the zones dataset.
     *
     * @return array[] Decoded item arrays, in the order the service returned them.
     */
    public function fetch_zones(): array {
        return $this->fetch_items($this->config->zones_url(), 'zones');
    }

    /**
     * Fetch the districts dataset.
     *
     * @return array[] Decoded item arrays.
     */
    public function fetch_districts(): array {
        return $this->fetch_items($this->config->districts_url(), 'districts');
    }

    /**
     * Fetch the facilities dataset.
     *
     * @return array[] Decoded item arrays.
     */
    public function fetch_facilities(): array {
        return $this->fetch_items($this->config->facilities_url(), 'facilities');
    }

    /**
     * Fetch one dataset and reduce it to a list of item arrays.
     *
     * @param string $url The endpoint URL.
     * @param string $dataset Dataset name, used only in messages.
     * @return array[]
     */
    protected function fetch_items(string $url, string $dataset): array {
        $body = $this->transport->get($url);

        $decoded = json_decode($body, true, self::JSON_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // The body itself is never included: it may be large and may echo request headers.
            throw new \moodle_exception('error:invalidjson', 'local_mohhierarchy', '', (object) [
                'dataset' => $dataset,
                'detail' => $this->config->redact(json_last_error_msg()),
            ]);
        }

        return $this->extract_items($decoded, $dataset);
    }

    /**
     * Reduce a decoded response to its item list, rejecting any shape that is not understood.
     *
     * Accepted shapes are a top level JSON array, or an object carrying the list under exactly one
     * of the keys in self::WRAPPER_KEYS. Everything else is a hard failure: guessing at an unknown
     * shape risks emptying the local copy.
     *
     * @param mixed $decoded The decoded response.
     * @param string $dataset Dataset name, used only in messages.
     * @return array[]
     */
    protected function extract_items(mixed $decoded, string $dataset): array {
        $items = null;
        if (is_array($decoded) && array_is_list($decoded)) {
            $items = $decoded;
        } else if (is_array($decoded)) {
            foreach (self::WRAPPER_KEYS as $key) {
                if (array_key_exists($key, $decoded) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                    $items = $decoded[$key];
                    break;
                }
            }
        }
        if ($items === null) {
            throw new \moodle_exception('error:unexpectedshape', 'local_mohhierarchy', '', (object) [
                'dataset' => $dataset,
                'wrappers' => implode(', ', self::WRAPPER_KEYS),
            ]);
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \moodle_exception('error:invaliditem', 'local_mohhierarchy', '', (object) [
                    'dataset' => $dataset,
                    'index' => $index,
                ]);
            }
        }

        if ($items === []) {
            // An empty dataset would deactivate the whole level. That is far more likely to be an
            // upstream fault than a real change, so it is treated as a failed run instead.
            throw new \moodle_exception('error:emptydataset', 'local_mohhierarchy', '', $dataset);
        }

        return $items;
    }
}
