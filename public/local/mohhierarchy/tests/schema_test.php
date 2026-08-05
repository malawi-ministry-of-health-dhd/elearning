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

/**
 * Tests that the installed schema matches what the plugin expects.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(
    \local_mohhierarchy\local\hierarchy\reference_repository::class,
)]
final class schema_test extends \advanced_testcase {
    /**
     * The tables and fields db/install.xml is expected to create.
     *
     * @return array[]
     */
    public static function table_provider(): array {
        return [
            'zone' => ['local_mohh_zone', [
                'id', 'externalid', 'name', 'description', 'active', 'lastseen', 'timecreated', 'timemodified',
            ]],
            'district' => ['local_mohh_district', [
                'id', 'externalid', 'zoneid', 'name', 'code', 'active', 'lastseen', 'timecreated', 'timemodified',
            ]],
            'facility' => ['local_mohh_facility', [
                'id', 'externalid', 'districtid', 'code', 'codedhis2', 'codeopenlmis', 'name', 'commonname',
                'registrationnumber', 'facilitytypeid', 'ownerid', 'operationalstatusid', 'regulatorystatusid',
                'dateopened', 'publishedat', 'remotecreatedat', 'remoteupdatedat', 'codemappingjson', 'active',
                'lastseen', 'timecreated', 'timemodified',
            ]],
            'assign' => ['local_mohh_assign', [
                'id', 'userid', 'zoneid', 'districtid', 'facilityid', 'scopelevel', 'assignedby', 'assignsource',
                'active', 'timecreated', 'timemodified',
            ]],
            'assignlog' => ['local_mohh_assignlog', [
                'id', 'userid', 'assignmentid', 'action', 'olddata', 'newdata', 'changedby', 'reason', 'timecreated',
            ]],
            'synclog' => ['local_mohh_synclog', [
                'id', 'status', 'triggerkind', 'triggeredby', 'startedat', 'finishedat', 'zonescreated',
                'zonesupdated', 'districtscreated', 'districtsupdated', 'facilitiescreated', 'facilitiesupdated',
                'itemsdeactivated', 'errormessage', 'timecreated',
            ]],
        ];
    }

    /**
     * Every table and field the plugin relies on is installed.
     *
     * @param string $tablename The table.
     * @param string[] $fields The fields expected in it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('table_provider')]
    public function test_table_and_fields_exist(string $tablename, array $fields): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table($tablename);
        $this->assertTrue($dbman->table_exists($table), "Table {$tablename} is missing");
        foreach ($fields as $fieldname) {
            $this->assertTrue(
                $dbman->field_exists($table, new \xmldb_field($fieldname)),
                "Field {$tablename}.{$fieldname} is missing",
            );
        }
    }

    /**
     * The tables carry no unexpected extra fields, which would mean install.xml has drifted.
     *
     * @param string $tablename The table.
     * @param string[] $fields The fields expected in it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('table_provider')]
    public function test_no_unexpected_fields(string $tablename, array $fields): void {
        global $DB;

        $actual = array_keys($DB->get_columns($tablename));
        sort($actual);
        $expected = $fields;
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * A brand new site with no hierarchy data is a valid state.
     */
    public function test_tables_start_empty(): void {
        global $DB;

        $this->resetAfterTest();

        foreach (array_column(self::table_provider(), 0) as $tablename) {
            $this->assertSame(0, $DB->count_records($tablename));
        }
    }
}
