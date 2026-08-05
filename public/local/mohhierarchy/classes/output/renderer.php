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

namespace local_mohhierarchy\output;

/**
 * Renderer for the plugin's administration pages.
 *
 * Every method hands the renderable's exported data straight to a Mustache template, so markup lives
 * in templates rather than in PHP.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the synchronisation dashboard.
     *
     * @param sync_dashboard $dashboard The dashboard data.
     * @return string
     */
    public function render_sync_dashboard(sync_dashboard $dashboard): string {
        return $this->render_from_template(
            'local_mohhierarchy/sync_dashboard',
            $dashboard->export_for_template($this),
        );
    }

    /**
     * Render the read-only hierarchy browser.
     *
     * @param hierarchy_browser $browser The browser data.
     * @return string
     */
    public function render_hierarchy_browser(hierarchy_browser $browser): string {
        return $this->render_from_template(
            'local_mohhierarchy/hierarchy_browser',
            $browser->export_for_template($this),
        );
    }

    /**
     * Render the user assignment table.
     *
     * @param assignment_manager $manager The table data.
     * @return string
     */
    public function render_assignment_manager(assignment_manager $manager): string {
        return $this->render_from_template(
            'local_mohhierarchy/assignment_manager',
            $manager->export_for_template($this),
        );
    }

    /**
     * Render the consistency repair report.
     *
     * @param repair_report $report Report data.
     * @return string
     */
    public function render_repair_report(repair_report $report): string {
        return $this->render_from_template(
            'local_mohhierarchy/repair_report',
            $report->export_for_template($this),
        );
    }
}
