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

use core_privacy\local\metadata\collection;
use core_privacy\tests\request\approved_contextlist;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\scope_level;
use local_mohhierarchy\privacy\provider;

/**
 * Privacy-provider retention and anonymisation tests.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * All three personal-data tables are declared.
     */
    public function test_metadata_declares_all_personal_data(): void {
        $collection = provider::get_metadata(new collection('local_mohhierarchy'));
        $this->assertCount(3, $collection->get_collection());
    }

    /**
     * Erasure withdraws operational access, retains audit, anonymises actors, and keeps references.
     */
    public function test_erasure_applies_documented_retention_policy(): void {
        global $DB;

        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $subject = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user(
            (int) $subject->id,
            (int) $facility->id,
            scope_level::NONE,
            (int) $actor->id,
        );
        $DB->insert_record('local_mohh_synclog', (object) [
            'status' => 'success',
            'triggerkind' => 'manual',
            'triggeredby' => $actor->id,
            'startedat' => 100,
            'finishedat' => 101,
            'timecreated' => 100,
        ]);

        $contexts = new approved_contextlist(
            $actor,
            'local_mohhierarchy',
            [\context_system::instance()->id],
        );
        provider::delete_data_for_user($contexts);

        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $subject->id], '*', MUST_EXIST);
        $this->assertNull($assignment->assignedby);
        $this->assertSame(1, (int) $assignment->active, 'Erasing the actor must not withdraw another user');
        $this->assertFalse($DB->record_exists('local_mohh_assignlog', ['changedby' => $actor->id]));
        $this->assertFalse($DB->record_exists('local_mohh_synclog', ['triggeredby' => $actor->id]));
        $this->assertTrue($DB->record_exists('local_mohh_facility', ['id' => $facility->id]));

        $subjectcontexts = new approved_contextlist(
            $subject,
            'local_mohhierarchy',
            [\context_system::instance()->id],
        );
        provider::delete_data_for_user($subjectcontexts);
        $this->assertSame(
            0,
            (int) $DB->get_field('local_mohh_assign', 'active', ['userid' => $subject->id]),
        );
        $this->assertGreaterThanOrEqual(
            2,
            $DB->count_records('local_mohh_assignlog', ['userid' => $subject->id]),
        );
        $this->assertTrue($DB->record_exists('local_mohh_facility', ['id' => $facility->id]));
    }

    /**
     * Subject and actor data are discoverable in the system context.
     */
    public function test_context_discovery_includes_subject_and_actor(): void {
        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $subject = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user(
            (int) $subject->id,
            (int) $facility->id,
            scope_level::NONE,
            (int) $actor->id,
        );

        $systemid = \context_system::instance()->id;
        $subjectcontextids = provider::get_contexts_for_userid((int) $subject->id)->get_contextids();
        $actorcontextids = provider::get_contexts_for_userid((int) $actor->id)->get_contextids();
        $this->assertContains((string) $systemid, $subjectcontextids);
        $this->assertContains((string) $systemid, $actorcontextids);
    }

    /**
     * Assignment and history data are included in a user export.
     */
    public function test_export_contains_assignment_and_history(): void {
        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);

        $this->export_all_data_for_user((int) $user->id, 'local_mohhierarchy');
        $data = \core_privacy\local\request\writer::with_context(\context_system::instance())->get_data(
            [get_string('pluginname', 'local_mohhierarchy')],
        );

        $this->assertEquals($facility->id, $data->assignment->facilityid);
        $this->assertCount(1, $data->assignmenthistory);
    }

    /**
     * Plugin generator.
     *
     * @return \local_mohhierarchy_generator
     */
    protected function generator(): \local_mohhierarchy_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_mohhierarchy');
    }
}
