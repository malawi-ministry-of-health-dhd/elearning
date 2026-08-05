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

use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\permission_service;

/**
 * Read-only browser over the synchronised hierarchy.
 *
 * The rows are grouped zone, then district, then facility, and every level shows its local Moodle id
 * and its remote identifier separately, so the two can be compared without being confused. Nothing
 * here offers to edit a record: these tables are owned by the synchronisation.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hierarchy_browser implements \renderable, \templatable {
    /** @var int User viewing the hierarchy. */
    protected int $actorid;

    /** @var string Free-text hierarchy search. */
    protected string $search;

    /** @var \moodle_url This page, used for paging links. */
    protected \moodle_url $pageurl;

    /** @var int Zero-based page number. */
    protected int $page;

    /** @var int Facilities per page. */
    protected int $perpage;

    /** @var facility_repository Facility search repository. */
    protected facility_repository $facilities;

    /** @var permission_service Hierarchy permission service. */
    protected permission_service $permissions;

    /**
     * Constructor.
     *
     * @param int $actorid The user viewing the hierarchy.
     * @param string $search Free text search term.
     * @param \moodle_url $pageurl This page, used for paging links.
     * @param int $page Zero based page number.
     * @param int $perpage Facilities per page.
     * @param facility_repository|null $facilities Injectable for tests.
     * @param permission_service|null $permissions Injectable for tests.
     */
    public function __construct(
        int $actorid,
        string $search,
        \moodle_url $pageurl,
        int $page = 0,
        int $perpage = 50,
        ?facility_repository $facilities = null,
        ?permission_service $permissions = null,
    ) {
        $this->actorid = $actorid;
        $this->search = $search;
        $this->pageurl = $pageurl;
        $this->page = $page;
        $this->perpage = $perpage;
        $this->facilities = $facilities ?? new facility_repository();
        $this->permissions = $permissions ?? new permission_service();
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $scope = is_siteadmin($this->actorid) ? null : $this->permissions->get_scope($this->actorid);
        $total = $this->facilities->count_search($this->search, $scope);
        $rows = $this->facilities->search(
            $this->search,
            $this->page * $this->perpage,
            $this->perpage,
            $scope,
        );

        $active = get_string('stateactive', 'local_mohhierarchy');
        $inactive = get_string('stateinactive', 'local_mohhierarchy');

        return [
            'ownershipnotice' => get_string('hierarchyownednotice', 'local_mohhierarchy'),
            'search' => $this->search,
            'hasrows' => $rows !== [],
            'total' => $total,
            'rows' => array_values(array_map(static fn(\stdClass $row): array => [
                'zonename' => $row->zonename,
                'zonelocalid' => (int) $row->zoneid,
                'zoneexternalid' => (int) $row->zoneexternalid,
                'zoneactive' => (int) $row->zoneactive === 1,
                'zonestate' => (int) $row->zoneactive === 1 ? $active : $inactive,
                'districtname' => $row->districtname,
                'districtlocalid' => (int) $row->districtid,
                'districtexternalid' => (int) $row->districtexternalid,
                'districtactive' => (int) $row->districtactive === 1,
                'districtstate' => (int) $row->districtactive === 1 ? $active : $inactive,
                'facilityname' => $row->facilityname,
                'facilitycode' => (string) ($row->facilitycode ?? ''),
                'facilitylocalid' => (int) $row->facilityid,
                'facilityexternalid' => (int) $row->facilityexternalid,
                'facilityactive' => (int) $row->facilityactive === 1,
                'facilitystate' => (int) $row->facilityactive === 1 ? $active : $inactive,
            ], $rows)),
            'paging' => $output->render(new \paging_bar(
                $total,
                $this->page,
                $this->perpage,
                new \moodle_url($this->pageurl, ['search' => $this->search]),
            )),
        ];
    }
}
