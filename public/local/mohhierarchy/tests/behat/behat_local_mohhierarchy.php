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

// NOTE: MOODLE_INTERNAL is not checked because Behat loads contexts before config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;

/**
 * Behat setup and security-manipulation steps for the MoH hierarchy.
 *
 * @package    local_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_mohhierarchy extends behat_base {
    /**
     * Create a named hierarchy in parent-before-child order.
     *
     * @Given /^the following MoH hierarchy exists:$/
     * @param TableNode $table Hierarchy rows.
     * @return void
     */
    public function the_following_moh_hierarchy_exists(TableNode $table): void {
        $generator = behat_util::get_data_generator()->get_plugin_generator('local_mohhierarchy');
        $nodes = [];
        foreach ($table->getHash() as $row) {
            $key = trim($row['key']);
            $parent = trim($row['parent'] ?? '');
            switch (trim($row['type'])) {
                case 'zone':
                    $nodes[$key] = $generator->create_zone(['name' => $row['name']]);
                    break;
                case 'district':
                    if (!isset($nodes[$parent])) {
                        throw new coding_exception("Unknown MoH hierarchy parent: {$parent}");
                    }
                    $nodes[$key] = $generator->create_district([
                        'zoneid' => $nodes[$parent]->id,
                        'name' => $row['name'],
                    ]);
                    break;
                case 'facility':
                    if (!isset($nodes[$parent])) {
                        throw new coding_exception("Unknown MoH hierarchy parent: {$parent}");
                    }
                    $nodes[$key] = $generator->create_facility([
                        'districtid' => $nodes[$parent]->id,
                        'name' => $row['name'],
                        'code' => $row['code'] ?: null,
                    ]);
                    break;
                default:
                    throw new coding_exception('Unknown MoH hierarchy type: ' . $row['type']);
            }
        }
    }

    /**
     * Give a test user strict-creation capabilities and a hierarchy scope.
     *
     * @Given /^user "([^"]*)" manages facility "([^"]*)" with "([^"]*)" scope$/
     * @param string $username Username.
     * @param string $facilityname Facility name.
     * @param string $scopevalue Scope value.
     * @return void
     */
    public function user_manages_facility_with_scope(
        string $username,
        string $facilityname,
        string $scopevalue,
    ): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $facility = $DB->get_record('local_mohh_facility', ['name' => $facilityname], '*', MUST_EXIST);
        $scope = scope_level::tryFrom($scopevalue);
        if ($scope === null) {
            throw new coding_exception("Unknown MoH hierarchy scope: {$scopevalue}");
        }
        $roleshortname = clean_param('moh_' . $username, PARAM_ALPHANUMEXT);
        $roleid = create_role('MoH ' . $username, $roleshortname, 'Behat MoH hierarchy manager');
        foreach ([permission_service::CAP_CREATE_USER, permission_service::CAP_VIEW_HIERARCHY] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, context_system::instance()->id);
        }
        role_assign($roleid, $user->id, context_system::instance()->id);
        (new assignment_service())->assign_user(
            (int) $user->id,
            (int) $facility->id,
            $scope,
            null,
            assign_source::MIGRATION,
        );
        accesslib_clear_all_caches(true);
    }

    /**
     * Inject an option into the facility selector to simulate browser manipulation.
     *
     * @When /^I force the submitted MoH facility to "(?P<facility_string>(?:[^"]|\\")*)"$/
     * @param string $facilityname Facility name.
     * @return void
     */
    public function i_force_the_submitted_moh_facility_to(string $facilityname): void {
        global $DB;

        if (!$this->running_javascript()) {
            throw new coding_exception('The forced facility step requires a JavaScript scenario.');
        }
        $facilityid = (int) $DB->get_field(
            'local_mohh_facility',
            'id',
            ['name' => $facilityname],
            MUST_EXIST,
        );
        $script = <<<JS
            const select = document.querySelector('[name="facilityid"]');
            if (!select) {
                throw new Error('The hierarchy facility selector was not found.');
            }
            const option = document.createElement('option');
            option.value = '{$facilityid}';
            option.textContent = 'Forced facility';
            option.selected = true;
            select.appendChild(option);
            select.dispatchEvent(new Event('change', {bubbles: true}));
        JS;
        $this->getSession()->executeScript($script);
    }
}
