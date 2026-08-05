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
 * Synchronise the hierarchy from the command line.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\sync_service;
use local_mohhierarchy\local\sync_trigger;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'force' => false],
    ['h' => 'help', 'f' => 'force'],
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<EOT
    Synchronise zones, districts and facilities from the remote hierarchy service.

    Endpoints, timeouts and the optional bearer token are taken from the plugin settings at
    Site administration > Plugins > Local plugins > MoH hierarchy. The run refuses to start if
    another synchronisation already holds the lock, and nothing local is changed unless the
    complete payload validates.

    Options:
      -h, --help   Print this help.
      -f, --force  Run even if a manual adhoc sync is queued. This never bypasses the live sync lock.

    Example:
      # php local/mohhierarchy/cli/sync.php
    EOT);
    exit(0);
}

$config = config::create();

if (!$options['force'] && \local_mohhierarchy\task\sync_hierarchy_adhoc::is_pending()) {
    cli_error(get_string('cli:manualsyncpending', 'local_mohhierarchy'), 2);
}

cli_writeln(get_string('syncstartingcli', 'local_mohhierarchy'));

try {
    $run = sync_service::create(null, $config)->run(sync_trigger::CLI);
} catch (moodle_exception $e) {
    // Redacted on the way out, in case the failure text came from outside this plugin.
    cli_error($config->redact($e->getMessage()), 1);
}

cli_writeln(get_string('synccompleted', 'local_mohhierarchy', $run));
exit(0);
