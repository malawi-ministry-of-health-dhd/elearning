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

use local_mohhierarchy\local\hierarchy\assignment_repository;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;

/**
 * Table of users with their current hierarchy assignment.
 *
 * Each row says whether this administrator may change that user, so the edit link only appears where
 * the write would actually be allowed. The decision is made by the permission service, and repeated
 * when the form is submitted.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_manager implements \renderable, \templatable {
    /** @var int User viewing the assignment list. */
    protected int $actorid;

    /** @var string Free-text user search. */
    protected string $search;

    /** @var \moodle_url This page, used for paging and edit links. */
    protected \moodle_url $pageurl;

    /** @var int Zero-based page number. */
    protected int $page;

    /** @var int Users per page. */
    protected int $perpage;

    /** @var assignment_repository Assignment search repository. */
    protected assignment_repository $assignments;

    /** @var permission_service Hierarchy permission service. */
    protected permission_service $permissions;

    /**
     * Constructor.
     *
     * @param int $actorid The administrator viewing the page.
     * @param string $search Free text search term.
     * @param \moodle_url $pageurl This page, used for paging and edit links.
     * @param int $page Zero based page number.
     * @param int $perpage Users per page.
     * @param assignment_repository|null $assignments Injectable for tests.
     * @param permission_service|null $permissions Injectable for tests.
     */
    public function __construct(
        int $actorid,
        string $search,
        \moodle_url $pageurl,
        int $page = 0,
        int $perpage = 25,
        ?assignment_repository $assignments = null,
        ?permission_service $permissions = null,
    ) {
        $this->actorid = $actorid;
        $this->search = $search;
        $this->pageurl = $pageurl;
        $this->page = $page;
        $this->perpage = $perpage;
        $this->assignments = $assignments ?? new assignment_repository();
        $this->permissions = $permissions ?? new permission_service();
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $scope = is_siteadmin($this->actorid) ? null : $this->permissions->get_scope($this->actorid);
        $total = $this->assignments->count_search_users($this->search, $scope);
        $users = $this->assignments->search_users(
            $this->search,
            $this->page * $this->perpage,
            $this->perpage,
            $scope,
        );
        $notset = get_string('notset', 'local_mohhierarchy');

        $rows = [];
        foreach ($users as $user) {
            $canmanage = $this->permissions->can_manage_assignment($this->actorid, (int) $user->userid);
            $rows[] = [
                'userid' => (int) $user->userid,
                'fullname' => fullname($user),
                'username' => $user->username,
                'email' => $user->email,
                'zonename' => $user->zonename ?? $notset,
                'districtname' => $user->districtname ?? $notset,
                'facilityname' => $user->facilityname ?? $notset,
                'facilityinactive' => $user->facilityid !== null && (int) $user->facilityactive !== 1,
                'scope' => $user->scopelevel === null
                    ? $notset
                    : get_string(
                        'scopelevel:' . scope_level::from_value($user->scopelevel)->value,
                        'local_mohhierarchy',
                    ),
                'withdrawn' => $user->assignmentid !== null && (int) $user->assignmentactive !== 1,
                'canmanage' => $canmanage,
                'editurl' => $canmanage
                    ? (new \moodle_url($this->pageurl, [
                        'userid' => (int) $user->userid,
                        'search' => $this->search,
                    ]))->out(false)
                    : '',
            ];
        }

        return [
            'search' => $this->search,
            'hasrows' => $rows !== [],
            'emptymessage' => get_string('noassignmentsfound', 'local_mohhierarchy'),
            'total' => $total,
            'rows' => $rows,
            'paging' => $output->render(new \paging_bar(
                $total,
                $this->page,
                $this->perpage,
                new \moodle_url($this->pageurl, ['search' => $this->search]),
            )),
        ];
    }
}
