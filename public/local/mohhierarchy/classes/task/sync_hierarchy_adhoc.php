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

namespace local_mohhierarchy\task;

use local_mohhierarchy\local\hierarchy\sync_log_repository;
use local_mohhierarchy\local\hierarchy\sync_service;
use local_mohhierarchy\local\sync_trigger;

/**
 * Runs a synchronisation an administrator asked for, out of the web request.
 *
 * Three upstream requests can take minutes, so "Synchronise now" queues this task rather than
 * blocking the browser. The scheduled synchronisation setting deliberately does not apply: an
 * administrator pressing the button is an explicit instruction.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_hierarchy_adhoc extends \core\task\adhoc_task {
    use \core\task\stored_progress_task_trait;

    /** @var string Lock used to make the pending-check and queue insert atomic. */
    protected const QUEUE_LOCK = 'manual-sync-queue';

    /**
     * Whether a manual synchronisation is already queued or still running.
     *
     * @return bool
     */
    public static function is_pending(): bool {
        return self::get_pending_task() !== null || (new sync_log_repository())->has_running();
    }

    /**
     * Return the queued or executing manual task, if one exists.
     *
     * @return self|null
     */
    public static function get_pending_task(): ?self {
        $queued = \core\task\manager::get_adhoc_tasks(self::class);
        foreach ($queued as $task) {
            if ($task instanceof self && $task->get_attempts_available() > 0) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Queue a manual synchronisation, unless one is already pending.
     *
     * @param int|null $triggeredby The administrator who asked for it.
     * @return bool False when one was already queued or running.
     */
    public static function queue(?int $triggeredby = null): bool {
        $factory = \core\lock\lock_config::get_lock_factory(sync_service::LOCK_TYPE);
        $lock = $factory->get_lock(self::QUEUE_LOCK, 5);
        if (!$lock) {
            return false;
        }

        try {
            if (self::is_pending()) {
                return false;
            }

            $task = new self();
            $task->set_custom_data(['triggeredby' => $triggeredby]);
            $task->set_component('local_mohhierarchy');

            $taskid = \core\task\manager::queue_adhoc_task($task, true);
            if ($taskid === false) {
                return false;
            }

            $task->set_id((int) $taskid);
            $task->initialise_stored_progress();
            return true;
        } finally {
            $lock->release();
        }
    }

    #[\Override]
    public function get_name(): string {
        return get_string('task:syncnow', 'local_mohhierarchy');
    }

    #[\Override]
    public function execute() {
        $data = $this->get_custom_data();
        $triggeredby = isset($data->triggeredby) ? (int) $data->triggeredby : null;
        $config = \local_mohhierarchy\local\config::create();
        $this->start_stored_progress();

        mtrace(get_string('syncstartingmanual', 'local_mohhierarchy'));

        try {
            $run = sync_service::create(null, $config)->run(
                sync_trigger::MANUAL,
                $triggeredby ?: null,
                null,
                $this->get_progress(),
            );
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'error:syncalreadyrunning') {
                // Another run holds the lock. Not a failure, and not worth retrying.
                mtrace(get_string('error:syncalreadyrunning', 'local_mohhierarchy'));
                return;
            }
            $this->progress->error(get_string('syncprogressfailed', 'local_mohhierarchy'));
            throw new \moodle_exception(
                'error:syncfailedsanitised',
                'local_mohhierarchy',
                '',
                $config->redact($e->getMessage()),
            );
        } catch (\Throwable $e) {
            $this->progress->error(get_string('syncprogressfailed', 'local_mohhierarchy'));
            throw new \moodle_exception(
                'error:syncfailedsanitised',
                'local_mohhierarchy',
                '',
                $config->redact($e->getMessage()),
            );
        }

        mtrace(get_string('synccompleted', 'local_mohhierarchy', $run));
    }
}
