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

use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\scope_level;

/**
 * User lifecycle observer tests.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a Moodle user withdraws the assignment and retains its audit history.
     */
    public function test_user_deleted_withdraws_assignment_and_preserves_history(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/lib.php');
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);

        $this->assertTrue(delete_user($user));

        $assignment = $DB->get_record('local_mohh_assign', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $assignment->active);
        $this->assertSame(2, $DB->count_records('local_mohh_assignlog', ['userid' => $user->id]));
    }

    /**
     * A created-event repair can only migrate a valid legacy facility with no management scope.
     */
    public function test_user_created_does_not_trust_profile_only_data(): void {
        global $DB;

        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['datatype' => 'mohhierarchy'], MUST_EXIST);
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => (string) $facility->id,
            'dataformat' => 0,
        ]);

        observer::user_created(\core\event\user_created::create_from_userid((int) $user->id));

        $this->assertFalse($DB->record_exists('local_mohh_assign', ['userid' => $user->id]));
    }

    /**
     * A user update may restore an empty mirror from an already-authorised assignment.
     */
    public function test_user_updated_repairs_existing_assignment_mirror(): void {
        global $DB;

        $this->resetAfterTest();
        $facility = $this->generator()->create_facility();
        $user = $this->getDataGenerator()->create_user();
        (new assignment_service())->assign_user((int) $user->id, (int) $facility->id, scope_level::NONE);

        observer::user_updated(\core\event\user_updated::create_from_userid((int) $user->id));

        $fieldid = $DB->get_field('user_info_field', 'id', ['datatype' => 'mohhierarchy'], MUST_EXIST);
        $this->assertSame((string) $facility->id, $DB->get_field('user_info_data', 'data', [
            'userid' => $user->id,
            'fieldid' => $fieldid,
        ]));
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
