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

namespace profilefield_mohhierarchy;

use core_privacy\local\metadata\collection;
use core_privacy\tests\request\approved_contextlist;
use profilefield_mohhierarchy\privacy\provider;

/**
 * Privacy tests for the companion profile-field mirror.
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Metadata and context discovery cover the field's user_info_data row.
     */
    public function test_metadata_and_context_discovery(): void {
        global $DB;

        $this->resetAfterTest();
        $this->assertCount(1, provider::get_metadata(
            new collection('profilefield_mohhierarchy'),
        )->get_collection());
        $user = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['datatype' => 'mohhierarchy'], MUST_EXIST);
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => '123',
            'dataformat' => 0,
        ]);

        $contextid = \context_user::instance((int) $user->id)->id;
        $contextids = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
        $this->assertContains((string) $contextid, $contextids);
    }

    /**
     * Deletion removes only this datatype's value.
     */
    public function test_delete_removes_only_hierarchy_profile_data(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $hierarchyfield = $DB->get_record('user_info_field', ['datatype' => 'mohhierarchy'], '*', MUST_EXIST);
        $otherfieldid = $DB->insert_record('user_info_field', (object) [
            'shortname' => 'otherprivacyfield',
            'name' => 'Other field',
            'datatype' => 'text',
            'categoryid' => $hierarchyfield->categoryid,
        ]);
        foreach ([$hierarchyfield->id => '12', $otherfieldid => 'keep'] as $fieldid => $value) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => 0,
            ]);
        }
        $context = \context_user::instance((int) $user->id);
        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'profilefield_mohhierarchy',
            [$context->id],
        ));

        $this->assertFalse($DB->record_exists('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $hierarchyfield->id,
        ]));
        $this->assertTrue($DB->record_exists('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $otherfieldid,
        ]));
    }
}
