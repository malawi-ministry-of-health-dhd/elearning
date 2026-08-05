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

namespace local_mohhierarchy\output;

/**
 * Presentation model for the consistency report.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repair_report implements \renderable, \templatable {
    /** @var array Repair service result. */
    protected array $report;

    /**
     * Constructor.
     *
     * @param array $report Repair service result.
     */
    public function __construct(array $report) {
        $this->report = $report;
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $rows = [];
        foreach ($this->report['issues'] as $issue) {
            $rows[] = [
                'fullname' => $issue['fullname'],
                'username' => $issue['username'],
                'code' => get_string('repaircode:' . $issue['code'], 'local_mohhierarchy'),
                'detail' => $issue['detail'],
                'repairable' => $issue['repairable'],
                'status' => $issue['repairable']
                    ? get_string('repairsafe', 'local_mohhierarchy')
                    : get_string('repairreview', 'local_mohhierarchy'),
            ];
        }

        return [
            'hasissues' => $rows !== [],
            'rows' => $rows,
            'userschecked' => $this->report['userschecked'],
            'repairable' => $this->report['repairable'],
            'conflicts' => $this->report['conflicts'],
            'fieldcount' => $this->report['fieldcount'],
            'consistent' => $rows === [],
        ];
    }
}
