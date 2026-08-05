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

namespace profilefield_mohhierarchy;

/**
 * Tests for the idempotent creation of the hierarchy category and field.
 *
 * The field already exists when these tests run, because installing the plugin created it while the
 * test database was built. That is exactly the situation the idempotence rules have to survive.
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class install_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/user/profile/field/mohhierarchy/db/install.php');
        require_once($CFG->dirroot . '/user/profile/field/mohhierarchy/db/upgrade.php');
        parent::setUpBeforeClass();
    }

    /**
     * Installing put exactly one category and one field in place, configured as required.
     */
    public function test_install_created_the_field(): void {
        global $DB;

        $categoryname = get_string('categoryname', 'profilefield_mohhierarchy');
        $this->assertSame(1, $DB->count_records('user_info_category', ['name' => $categoryname]));

        $field = $DB->get_record('user_info_field', ['shortname' => 'mohfacility'], '*', MUST_EXIST);
        $this->assertSame('mohhierarchy', $field->datatype);
        $this->assertSame(get_string('fieldname', 'profilefield_mohhierarchy'), $field->name);
        $this->assertEquals(0, $field->signup, 'The field must never appear on public signup');
        $this->assertEquals(PROFILE_VISIBLE_PRIVATE, $field->visible);
        $this->assertEquals(
            $DB->get_field('user_info_category', 'id', ['name' => $categoryname]),
            $field->categoryid,
        );
    }

    /**
     * Running the install step again creates no duplicates and changes nothing.
     */
    public function test_install_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();

        $categoryname = get_string('categoryname', 'profilefield_mohhierarchy');
        $before = $DB->get_record('user_info_field', ['shortname' => 'mohfacility'], '*', MUST_EXIST);
        $categories = $DB->count_records('user_info_category');
        $fields = $DB->count_records('user_info_field');

        $this->assertTrue(xmldb_profilefield_mohhierarchy_install());
        $this->assertTrue(xmldb_profilefield_mohhierarchy_install());

        $this->assertSame($categories, $DB->count_records('user_info_category'));
        $this->assertSame($fields, $DB->count_records('user_info_field'));
        $this->assertSame(1, $DB->count_records('user_info_category', ['name' => $categoryname]));
        $this->assertSame(1, $DB->count_records('user_info_field', ['shortname' => 'mohfacility']));
        $this->assertEquals($before, $DB->get_record('user_info_field', ['shortname' => 'mohfacility']));
    }

    /**
     * An administrator's own changes to the field survive a re-run.
     */
    public function test_install_does_not_overwrite_administrator_changes(): void {
        global $DB;

        $this->resetAfterTest();

        $DB->set_field('user_info_field', 'name', 'Renamed by an administrator', ['shortname' => 'mohfacility']);
        $this->assertTrue(xmldb_profilefield_mohhierarchy_install());

        $this->assertSame(
            'Renamed by an administrator',
            $DB->get_field('user_info_field', 'name', ['shortname' => 'mohfacility']),
        );
    }

    /**
     * Installing or re-running the plugin never changes unrelated custom profile fields.
     */
    public function test_install_leaves_unrelated_custom_fields_unchanged(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $DB->get_field('user_info_category', 'id', [], MUST_EXIST);
        $fieldid = $DB->insert_record('user_info_field', (object) [
            'shortname' => 'existingdepartment',
            'name' => 'Existing department',
            'datatype' => 'text',
            'description' => 'Must survive unchanged',
            'categoryid' => $categoryid,
            'visible' => PROFILE_VISIBLE_ALL,
            'required' => 1,
        ]);
        $before = $DB->get_record('user_info_field', ['id' => $fieldid], '*', MUST_EXIST);

        $this->assertTrue(xmldb_profilefield_mohhierarchy_install());

        $this->assertEquals($before, $DB->get_record('user_info_field', ['id' => $fieldid], '*', MUST_EXIST));
        $this->assertSame(1, $DB->count_records('user_info_field', ['shortname' => 'existingdepartment']));
        $this->assertSame(1, $DB->count_records('user_info_field', ['datatype' => 'mohhierarchy']));
    }

    /**
     * The short name held by a field of another data type stops the install with a clear message.
     */
    public function test_install_refuses_a_short_name_conflict(): void {
        global $DB;

        $this->resetAfterTest();

        // Simulate a site that already had a text field with this short name.
        $DB->set_field('user_info_field', 'datatype', 'text', ['shortname' => 'mohfacility']);

        try {
            xmldb_profilefield_mohhierarchy_install();
            $this->fail('Expected the short name conflict to stop the install');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:shortnameconflict', $e->errorcode);
            $this->assertStringContainsString('mohfacility', $e->getMessage());
            $this->assertStringContainsString('text', $e->getMessage());
        }

        // Nothing was created while refusing.
        $this->assertSame(1, $DB->count_records('user_info_field', ['shortname' => 'mohfacility']));
        $this->assertSame(0, $DB->count_records('user_info_field', ['datatype' => 'mohhierarchy']));
    }

    /**
     * The existing category is reused rather than duplicated when the field has to be created.
     */
    public function test_install_reuses_the_category(): void {
        global $DB;

        $this->resetAfterTest();

        $categoryname = get_string('categoryname', 'profilefield_mohhierarchy');
        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => $categoryname]);
        $DB->delete_records('user_info_field', ['shortname' => 'mohfacility']);

        $this->assertTrue(xmldb_profilefield_mohhierarchy_install());

        $this->assertSame(1, $DB->count_records('user_info_category', ['name' => $categoryname]));
        $this->assertEquals(
            $categoryid,
            $DB->get_field('user_info_field', 'categoryid', ['shortname' => 'mohfacility']),
        );
    }

    /**
     * Upgrading an earlier development installation repairs a missing hierarchy field.
     */
    public function test_upgrade_repairs_a_missing_field(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryname = get_string('categoryname', 'profilefield_mohhierarchy');
        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => $categoryname], MUST_EXIST);
        $DB->delete_records('user_info_field', ['shortname' => 'mohfacility']);
        set_config('version', 2026073000, 'profilefield_mohhierarchy');

        $this->assertTrue(xmldb_profilefield_mohhierarchy_upgrade(2026073000));

        $field = $DB->get_record('user_info_field', ['shortname' => 'mohfacility'], '*', MUST_EXIST);
        $this->assertSame('mohhierarchy', $field->datatype);
        $this->assertEquals($categoryid, $field->categoryid);
        $this->assertSame(1, $DB->count_records('user_info_field', ['datatype' => 'mohhierarchy']));
    }

    /**
     * Uninstalling removes this plugin's fields and their data, and no users.
     */
    public function test_uninstall_removes_fields_but_not_users(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/field/mohhierarchy/db/uninstall.php');

        $user = $this->getDataGenerator()->create_user();
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'mohfacility']);
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $fieldid,
            'data' => '1',
            'dataformat' => 0,
        ]);
        $usercount = $DB->count_records('user');

        $this->assertTrue(xmldb_profilefield_mohhierarchy_uninstall());

        $this->assertSame(0, $DB->count_records('user_info_field', ['datatype' => 'mohhierarchy']));
        $this->assertSame(0, $DB->count_records('user_info_data', ['fieldid' => $fieldid]));
        $this->assertSame($usercount, $DB->count_records('user'), 'Uninstalling must never delete users');
        $this->assertTrue($DB->record_exists('user', ['id' => $user->id, 'deleted' => 0]));
    }
}
