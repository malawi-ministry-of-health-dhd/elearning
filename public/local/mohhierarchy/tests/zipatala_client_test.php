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

namespace local_mohhierarchy;

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\source\zipatala_client;

/**
 * Tests for the endpoint configuration and the response shape rules.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(zipatala_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
final class zipatala_client_test extends \advanced_testcase {
    /** @var string Base URL used by every test here. Never resolved: the transport is faked. */
    protected const BASE = 'https://zipatala.example.invalid/api';

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/local/mohhierarchy/tests/fixtures/fake_transport.php');
        parent::setUpBeforeClass();
    }

    /**
     * Read a fixture file.
     *
     * @param string $name File name inside tests/fixtures.
     * @return string
     */
    protected function fixture(string $name): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/local/mohhierarchy/tests/fixtures/' . $name);
    }

    /**
     * Build a client whose zones endpoint returns the given body or throws the given exception.
     *
     * @param string|\Throwable $zonesresponse The canned zones response.
     * @param config|null $config Optional configuration override.
     * @return array{0: zipatala_client, 1: \local_mohhierarchy_fake_transport}
     */
    protected function client(string|\Throwable $zonesresponse, ?config $config = null): array {
        $config = $config ?? new config(self::BASE);
        $transport = new \local_mohhierarchy_fake_transport([
            $config->zones_url() => $zonesresponse,
        ]);

        return [new zipatala_client($transport, $config), $transport];
    }

    /**
     * Endpoint paths are appended to the base URL, however the two are punctuated.
     */
    public function test_endpoint_urls_are_built_from_the_base_url(): void {
        $config = new config(self::BASE);
        $this->assertSame(self::BASE . '/zones', $config->zones_url());
        $this->assertSame(self::BASE . '/districts', $config->districts_url());
        $this->assertSame(self::BASE . '/facilities', $config->facilities_url());

        $messy = new config(self::BASE . '/', 'zones', '/districts/', 'facilities');
        $this->assertSame(self::BASE . '/zones', $messy->zones_url());
        $this->assertSame(self::BASE . '/districts/', $messy->districts_url());
        $this->assertSame(self::BASE . '/facilities', $messy->facilities_url());
    }

    /**
     * An endpoint given as a complete URL is used as it stands.
     */
    public function test_absolute_endpoint_overrides_the_base_url(): void {
        $config = new config(self::BASE, 'https://other.example.invalid/v2/zones');
        $this->assertSame('https://other.example.invalid/v2/zones', $config->zones_url());
    }

    /**
     * An endpoint that cannot form an http URL is rejected rather than requested.
     */
    public function test_unusable_endpoint_is_rejected(): void {
        $config = new config('not a url', '/zones');

        $this->expectException(\moodle_exception::class);
        $config->zones_url();
    }

    /**
     * The documented defaults are what an unconfigured site uses.
     */
    public function test_defaults_match_the_documented_values(): void {
        $this->resetAfterTest();
        $config = config::create();

        $this->assertSame('https://zipatala.health.gov.mw/api/zones', $config->zones_url());
        $this->assertSame('https://zipatala.health.gov.mw/api/districts', $config->districts_url());
        $this->assertSame('https://zipatala.health.gov.mw/api/facilities', $config->facilities_url());
        $this->assertSame(10, $config->connect_timeout());
        $this->assertSame(120, $config->request_timeout());
        $this->assertFalse($config->has_token());
    }

    /**
     * A direct top level JSON array is the expected shape.
     */
    public function test_top_level_array_is_accepted(): void {
        [$client, $transport] = $this->client($this->fixture('zones.json'));

        $items = $client->fetch_zones();
        $this->assertCount(2, $items);
        $this->assertSame('Central East Zone', $items[0]['zone_name']);
        $this->assertSame([self::BASE . '/zones'], $transport->requested);
    }

    /**
     * A data wrapper is unwrapped.
     */
    public function test_data_wrapper_is_accepted(): void {
        [$client] = $this->client($this->fixture('zones_wrapped_data.json'));

        $items = $client->fetch_zones();
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['id']);
    }

    /**
     * A results wrapper is unwrapped.
     */
    public function test_results_wrapper_is_accepted(): void {
        [$client] = $this->client($this->fixture('zones_wrapped_results.json'));

        $items = $client->fetch_zones();
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['id']);
    }

    /**
     * Any other wrapper key is refused rather than guessed at.
     */
    public function test_unrecognised_shape_is_rejected(): void {
        [$client] = $this->client($this->fixture('zones_unexpected_shape.json'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/not a JSON array/');
        $client->fetch_zones();
    }

    /**
     * A body that is not JSON is refused, and the body itself is not echoed back.
     */
    public function test_invalid_json_is_rejected(): void {
        [$client] = $this->client($this->fixture('zones_invalid.json'));

        try {
            $client->fetch_zones();
            $this->fail('Expected invalid JSON to be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidjson', $e->errorcode);
            $this->assertStringNotContainsString('Gateway timeout', $e->getMessage());
        }
    }

    /**
     * A list of scalars is not a list of records.
     */
    public function test_non_object_items_are_rejected(): void {
        [$client] = $this->client('["Central East Zone", 42]');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/is not an object/');
        $client->fetch_zones();
    }

    /**
     * An empty dataset stops the run instead of silently deactivating a whole level.
     */
    public function test_empty_dataset_is_rejected(): void {
        [$client] = $this->client('[]');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/contained no records/');
        $client->fetch_zones();
    }

    /**
     * A transport level failure, such as an HTTP error, propagates unchanged.
     */
    public function test_transport_failure_propagates(): void {
        $failure = new \moodle_exception('error:httpstatus', 'local_mohhierarchy', '', (object) [
            'url' => self::BASE . '/zones',
            'status' => 503,
        ]);
        [$client] = $this->client($failure);

        try {
            $client->fetch_zones();
            $this->fail('Expected the HTTP failure to propagate');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:httpstatus', $e->errorcode);
            $this->assertStringContainsString('503', $e->getMessage());
        }
    }

    /**
     * The token never reaches an exception message, whichever failure occurred.
     */
    public function test_token_is_never_exposed_in_client_errors(): void {
        $token = 'sup3r-s3cret-zipatala-token';
        $config = new config(self::BASE, token: $token);

        $cases = [
            'invalid json' => $this->fixture('zones_invalid.json'),
            'unexpected shape' => $this->fixture('zones_unexpected_shape.json'),
            'empty dataset' => '[]',
            'scalar items' => '["x"]',
        ];
        foreach ($cases as $label => $body) {
            [$client] = $this->client($body, $config);
            try {
                $client->fetch_zones();
                $this->fail("Expected {$label} to fail");
            } catch (\moodle_exception $e) {
                $this->assertStringNotContainsString($token, $e->getMessage(), $label);
                $this->assertStringNotContainsString($token, $e->debuginfo ?? '', $label);
            }
        }
    }

    /**
     * Redaction replaces the token wherever it turns up in third party text.
     */
    public function test_redaction_removes_the_token(): void {
        $config = new config(self::BASE, token: 'abc123');
        $this->assertSame(
            'GET https://x.invalid?token=' . config::REDACTED,
            $config->redact('GET https://x.invalid?token=abc123'),
        );

        $notoken = new config(self::BASE);
        $this->assertSame('nothing to redact', $notoken->redact('nothing to redact'));
    }
}
