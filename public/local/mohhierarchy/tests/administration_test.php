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

use local_mohhierarchy\form\assign_form;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\assignment_repository;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\facility_repository;
use local_mohhierarchy\local\hierarchy\hierarchy_scope;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\hierarchy\repair_service;
use local_mohhierarchy\local\hierarchy\sync_log_repository;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\local\sync_status;
use local_mohhierarchy\local\sync_trigger;
use local_mohhierarchy\output\assignment_manager;
use local_mohhierarchy\output\hierarchy_browser;
use local_mohhierarchy\output\repair_report;
use local_mohhierarchy\output\sync_dashboard;
use local_mohhierarchy\reportbuilder\local\systemreports\jurisdiction_users;
use local_mohhierarchy\task\sync_hierarchy;
use local_mohhierarchy\task\sync_hierarchy_adhoc;

/**
 * Tests for scheduled/manual administration support and scope-filtered administration queries.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sync_hierarchy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sync_hierarchy_adhoc::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(facility_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assignment_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(jurisdiction_users::class)]
final class administration_test extends \advanced_testcase {
    /**
     * The plugin test data generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }

    /**
     * The scheduled task defaults to enabled and can be disabled without contacting the API.
     */
    public function test_scheduled_task_respects_enabled_setting(): void {
        $this->resetAfterTest();
        unset_config('scheduledsyncenabled', 'local_mohhierarchy');
        $this->assertTrue(config::create()->scheduled_sync_enabled());

        set_config('scheduledsyncenabled', 0, 'local_mohhierarchy');
        $this->expectOutputString(get_string('syncdisabled', 'local_mohhierarchy') . PHP_EOL);
        (new sync_hierarchy())->execute();
    }

    /**
     * The manual action queues one background task and refuses a duplicate.
     */
    public function test_manual_sync_queue_is_deduplicated(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $this->assertTrue(sync_hierarchy_adhoc::queue($adminid));
        $this->assertTrue(sync_hierarchy_adhoc::is_pending());
        $this->assertFalse(sync_hierarchy_adhoc::queue($adminid));

        $tasks = \core\task\manager::get_adhoc_tasks(sync_hierarchy_adhoc::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame($adminid, (int) $task->get_custom_data()->triggeredby);
        $idnumber = \core\output\stored_progress_bar::convert_to_idnumber(
            $task::class,
            $task->get_id(),
        );
        $this->assertNotNull(
            \core\output\stored_progress_bar::get_by_idnumber($idnumber),
            'A progress record must exist while the task is still queued.',
        );
    }

    /**
     * Manual synchronisation access is controlled by the dedicated system capability.
     */
    public function test_manual_sync_capability_is_required(): void {
        $this->resetAfterTest();
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        $allowed = $this->getDataGenerator()->create_user();
        $denied = $this->getDataGenerator()->create_user();
        role_assign($roleid, $allowed->id, $systemcontext->id);
        assign_capability(permission_service::CAP_MANAGE_SYNC, CAP_ALLOW, $roleid, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability(
            permission_service::CAP_MANAGE_SYNC,
            $systemcontext,
            (int) $allowed->id,
        ));
        $this->assertFalse(has_capability(
            permission_service::CAP_MANAGE_SYNC,
            $systemcontext,
            (int) $denied->id,
        ));
    }

    /**
     * Assignment editing uses ordered hierarchy selectors and a searchable Facility control.
     */
    public function test_assignment_form_uses_searchable_facility_and_derived_parents(): void {
        global $CFG, $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/formslib.php');
        $PAGE->set_context(\context_system::instance());
        $url = new \moodle_url('/local/mohhierarchy/assignments.php', ['userid' => 123]);
        $PAGE->set_url($url);

        $target = (object) ['userid' => 123];
        $form = new assign_form($url, [
            'target' => $target,
            'targetname' => 'Assignment target',
            'zones' => [10 => 'Zone A'],
            'districts' => [20 => 'District A'],
            'facilities' => [30 => 'Facility A'],
            'facilitypaths' => [30 => ['zoneid' => 10, 'districtid' => 20]],
            'scopes' => scope_level::cases(),
            'permissions' => new permission_service(),
            'currentfacilityid' => 30,
            'search' => '',
        ]);
        $html = $form->render();

        $zoneposition = strpos($html, 'name="zoneid"');
        $districtposition = strpos($html, 'name="districtid"');
        $facilityposition = strpos($html, 'name="facilityid"');
        $this->assertNotFalse($zoneposition);
        $this->assertNotFalse($districtposition);
        $this->assertNotFalse($facilityposition);
        $this->assertLessThan($districtposition, $zoneposition);
        $this->assertLessThan($facilityposition, $districtposition);
        $this->assertStringContainsString('data-fieldtype="autocomplete"', $html);
        $this->assertStringContainsString('Facility A', $html);
    }

    /**
     * Every administration controller repeats capability checks and protects writes with sesskey.
     */
    public function test_administration_controllers_enforce_capabilities_and_csrf(): void {
        global $CFG;

        $controllers = [
            'sync.php' => [
                'require_capability(permission_service::CAP_MANAGE_SYNC',
                'require_sesskey();',
            ],
            'assignments.php' => [
                'require_capability(permission_service::CAP_MANAGE_ASSIGNMENTS',
                'require_sesskey();',
            ],
            'repair.php' => [
                'require_capability(permission_service::CAP_REPAIR_CONSISTENCY',
                'require_sesskey();',
            ],
        ];
        foreach ($controllers as $filename => $requiredfragments) {
            $source = file_get_contents($CFG->dirroot . '/local/mohhierarchy/' . $filename);
            foreach ($requiredfragments as $fragment) {
                $this->assertStringContainsString($fragment, $source, "{$filename} must contain {$fragment}");
            }
        }
    }

    /**
     * The specialist repair report is not exposed in the main Site administration menu.
     */
    public function test_consistency_repair_is_hidden_from_admin_navigation(): void {
        global $CFG;

        $settingssource = file_get_contents($CFG->dirroot . '/local/mohhierarchy/settings.php');
        $this->assertStringNotContainsString('local_mohhierarchy_repair', $settingssource);
        $this->assertStringNotContainsString('/local/mohhierarchy/repair.php', $settingssource);
    }

    /**
     * Browse users opens the scoped plugin list for delegated managers and core for site admins.
     */
    public function test_browse_users_navigation_uses_scoped_list_for_delegated_manager(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            CAP_ALLOW,
            $roleid,
            $systemcontext->id,
        );
        $manager = $this->getDataGenerator()->create_user();
        role_assign($roleid, $manager->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);

        $managerpage = admin_get_root(true, true)->locate('editusers');
        $this->assertInstanceOf(\admin_externalpage::class, $managerpage);
        $this->assertSame(
            (new \moodle_url('/local/mohhierarchy/assignments.php'))->get_path(),
            (new \moodle_url($managerpage->url))->get_path(),
        );
        $this->assertSame([permission_service::CAP_MANAGE_ASSIGNMENTS], $managerpage->req_capability);

        $this->setAdminUser();
        $adminpage = admin_get_root(true, true)->locate('editusers');
        $this->assertInstanceOf(\admin_externalpage::class, $adminpage);
        $this->assertSame(
            (new \moodle_url('/admin/user.php'))->get_path(),
            (new \moodle_url($adminpage->url))->get_path(),
        );
    }

    /**
     * The scoped user report keeps core's UI while enforcing jurisdiction in its base SQL.
     */
    public function test_jurisdiction_report_has_filters_actions_and_only_in_scope_rows(): void {
        global $PAGE;

        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/local/mohhierarchy/assignments.php');
        $generator = $this->generator();
        $service = new assignment_service();

        $zonea = $generator->create_zone(['name' => 'Report Zone A']);
        $zoneb = $generator->create_zone(['name' => 'Report Zone B']);
        $districta = $generator->create_district(['zoneid' => $zonea->id, 'name' => 'Report District A']);
        $districtb = $generator->create_district(['zoneid' => $zoneb->id, 'name' => 'Report District B']);
        $facilitya = $generator->create_facility(['districtid' => $districta->id, 'name' => 'Report Facility A']);
        $facilityb = $generator->create_facility(['districtid' => $districtb->id, 'name' => 'Report Facility B']);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
        );
        $manager = $this->getDataGenerator()->create_user();
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        $inside = $this->getDataGenerator()->create_user([
            'firstname' => 'Visible',
            'lastname' => 'Jurisdiction User',
        ]);
        $outside = $this->getDataGenerator()->create_user([
            'firstname' => 'Hidden',
            'lastname' => 'Outside User',
        ]);
        $service->assign_user((int) $manager->id, (int) $facilitya->id, scope_level::ZONE);
        $service->assign_user((int) $inside->id, (int) $facilitya->id, scope_level::NONE);
        $service->assign_user((int) $outside->id, (int) $facilityb->id, scope_level::NONE);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);

        $report = \core_reportbuilder\system_report_factory::create(
            jurisdiction_users::class,
            \context_system::instance(),
            parameters: ['withcheckboxes' => false],
        );
        [$basesql, $baseparams] = $report->get_base_condition();
        $this->assertStringContainsString('mohscope.zoneid', $basesql);
        $this->assertContains((int) $zonea->id, $baseparams);
        $this->assertNotNull($report->get_filter('user:hierarchyzone'));
        $this->assertNotNull($report->get_filter('user:hierarchydistrict'));
        $this->assertNotNull($report->get_filter('user:hierarchyfacility'));
        $this->assertNotNull($report->get_column('user:hierarchyzone'));
        $this->assertNotNull($report->get_column('user:hierarchydistrict'));
        $this->assertNotNull($report->get_column('user:hierarchyfacility'));

        $html = $report->output();
        $this->assertStringContainsString('Visible Jurisdiction User', $html);
        $this->assertStringNotContainsString('Hidden Outside User', $html);
        $this->assertStringContainsString(get_string('transferuser', 'local_mohhierarchy'), $html);
    }

    /**
     * Hierarchy administration searches are restricted in SQL to the actor's delegated scope.
     */
    public function test_hierarchy_search_is_scope_filtered(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $repository = new facility_repository();

        $zonea = $generator->create_zone(['name' => 'Zone A']);
        $zoneb = $generator->create_zone(['name' => 'Zone B']);
        $districta = $generator->create_district(['zoneid' => $zonea->id, 'name' => 'District A']);
        $districtb = $generator->create_district(['zoneid' => $zoneb->id, 'name' => 'District B']);
        $facilitya = $generator->create_facility(['districtid' => $districta->id, 'name' => 'Clinic A']);
        $facilityb = $generator->create_facility(['districtid' => $districtb->id, 'name' => 'Clinic B']);

        $scope = new hierarchy_scope(
            scope_level::ZONE,
            (int) $zonea->id,
            (int) $districta->id,
            (int) $facilitya->id,
        );
        $rows = $repository->search('', 0, 0, $scope);
        $this->assertArrayHasKey((int) $facilitya->id, $rows);
        $this->assertArrayNotHasKey((int) $facilityb->id, $rows);
        $this->assertSame(1, $repository->count_search('', $scope));

        $options = $repository->get_assignment_options($scope);
        $this->assertSame([(int) $facilitya->id], array_keys($options));
    }

    /**
     * Delegated assignment searches show only active in-scope assignments.
     */
    public function test_assignment_search_is_scope_filtered(): void {
        $this->resetAfterTest();
        $generator = $this->generator();
        $service = new assignment_service();
        $repository = new assignment_repository();

        $zonea = $generator->create_zone();
        $zoneb = $generator->create_zone();
        $districta = $generator->create_district(['zoneid' => $zonea->id]);
        $districtb = $generator->create_district(['zoneid' => $zoneb->id]);
        $facilitya = $generator->create_facility(['districtid' => $districta->id]);
        $facilityb = $generator->create_facility(['districtid' => $districtb->id]);
        $inside = $this->getDataGenerator()->create_user(['firstname' => 'Searchable', 'lastname' => 'Inside']);
        $outside = $this->getDataGenerator()->create_user(['firstname' => 'Searchable', 'lastname' => 'Outside']);
        $unassigned = $this->getDataGenerator()->create_user(['firstname' => 'Searchable', 'lastname' => 'Unassigned']);
        $service->assign_user(
            (int) $inside->id,
            (int) $facilitya->id,
            scope_level::NONE,
            null,
            assign_source::MIGRATION,
        );
        $service->assign_user(
            (int) $outside->id,
            (int) $facilityb->id,
            scope_level::NONE,
            null,
            assign_source::MIGRATION,
        );

        $scope = new hierarchy_scope(
            scope_level::ZONE,
            (int) $zonea->id,
            (int) $districta->id,
            (int) $facilitya->id,
        );
        $matches = $repository->search_users('Searchable', 0, 0, $scope);
        $this->assertArrayHasKey((int) $inside->id, $matches);
        $this->assertArrayNotHasKey((int) $unassigned->id, $matches);
        $this->assertArrayNotHasKey((int) $outside->id, $matches);

        $service->withdraw_assignment((int) $inside->id);
        $this->assertArrayNotHasKey(
            (int) $inside->id,
            $repository->search_users('Searchable', 0, 0, $scope),
        );

        // A site administrator's unrestricted search may still find an unassigned account.
        $this->assertArrayHasKey(
            (int) $unassigned->id,
            $repository->search_users('Searchable'),
        );

        $assignedonly = $repository->search_users('', 0, 0, $scope);
        $this->assertArrayNotHasKey((int) $inside->id, $assignedonly);
        $this->assertArrayNotHasKey((int) $unassigned->id, $assignedonly);
        $this->assertArrayNotHasKey((int) $outside->id, $assignedonly);
    }

    /**
     * Administration renderables compile their templates and never expose a configured token.
     */
    public function test_administration_templates_render(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $pageurl = new \moodle_url('/local/mohhierarchy/sync.php');
        $PAGE->set_url($pageurl);

        $facility = $this->generator()->create_facility(['name' => 'Rendered facility']);
        $user = $this->getDataGenerator()->create_user(['username' => 'rendereduser']);
        (new assignment_service())->assign_user(
            (int) $user->id,
            (int) $facility->id,
            scope_level::NONE,
            null,
            assign_source::MIGRATION,
        );

        /** @var \local_mohhierarchy\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('local_mohhierarchy');
        $hierarchyhtml = $renderer->render_hierarchy_browser(new hierarchy_browser(
            (int) get_admin()->id,
            'Rendered',
            new \moodle_url('/local/mohhierarchy/hierarchy.php'),
        ));
        $this->assertStringContainsString('Rendered facility', $hierarchyhtml);

        $assignmenthtml = $renderer->render_assignment_manager(new assignment_manager(
            (int) get_admin()->id,
            'rendereduser',
            new \moodle_url('/local/mohhierarchy/assignments.php'),
        ));
        $this->assertStringContainsString('rendereduser', $assignmenthtml);

        $secret = 'never-render-this-token';
        $synclog = new sync_log_repository();
        $runid = $synclog->start(sync_trigger::CLI);
        $synclog->finish(
            $runid,
            sync_status::FAILED,
            [],
            'A legacy error accidentally contained ' . $secret,
        );
        $this->assertTrue(sync_hierarchy_adhoc::queue((int) get_admin()->id));
        $dashboardhtml = $renderer->render_sync_dashboard(new sync_dashboard(
            new config(token: $secret),
            $pageurl,
        ));
        $this->assertStringContainsString(get_string('tokenset', 'local_mohhierarchy'), $dashboardhtml);
        $this->assertStringNotContainsString($secret, $dashboardhtml);
        $this->assertStringContainsString(
            get_string('syncprogressheading', 'local_mohhierarchy'),
            $dashboardhtml,
        );
        $this->assertStringContainsString('stored-progress-bar', $dashboardhtml);

        $repairhtml = $renderer->render_repair_report(new repair_report((new repair_service())->scan()));
        $this->assertStringContainsString(get_string('repairuserschecked', 'local_mohhierarchy'), $repairhtml);
    }
}
