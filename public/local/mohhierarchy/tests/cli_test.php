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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_mohhierarchy;

/**
 * Process-level tests for the hierarchy CLI command.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class cli_test extends \advanced_testcase {
    /**
     * Help exits successfully and does not disclose configuration.
     */
    public function test_help_returns_zero(): void {
        [$exitcode, $output] = $this->run_cli(['--help']);

        $this->assertSame(0, $exitcode);
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('Synchronise zones, districts and facilities', $output);
    }

    /**
     * Invalid options return a non-zero process status.
     */
    public function test_invalid_option_returns_nonzero(): void {
        [$exitcode, $output] = $this->run_cli(['--definitely-invalid']);

        $this->assertNotSame(0, $exitcode);
        $this->assertStringContainsString('definitely-invalid', $output);
    }

    /**
     * Execute the real CLI entry point in an isolated process.
     *
     * @param string[] $arguments CLI arguments.
     * @return array{0: int, 1: string} Exit code and combined output.
     */
    protected function run_cli(array $arguments): array {
        global $CFG;

        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($CFG->dirroot . '/local/mohhierarchy/cli/sync.php');
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }
}
