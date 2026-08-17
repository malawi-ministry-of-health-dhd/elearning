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

namespace local_mohhierarchy\reportbuilder\local\systemreports;

use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;

/**
 * Moodle's Browse users report with a jurisdiction default and hierarchy-wide filters.
 *
 * Core supplies the familiar filters, columns, paging and action menu. This subclass adds the
 * hierarchy columns and filters, a Transfer user action and a jurisdiction base condition. The
 * default view is scoped to the actor; deliberately applying a hierarchy filter searches the full
 * active hierarchy. Transfer actions remain restricted to targets the actor may manage.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jurisdiction_users extends \core_admin\reportbuilder\local\systemreports\users {
    /** @var string Assignment table alias shared by hierarchy columns and filters. */
    protected string $assignmentalias = 'mohassign';

    /** @var string Zone table alias. */
    protected string $zonealias = 'mohzone';

    /** @var string District table alias. */
    protected string $districtalias = 'mohdistrict';

    /** @var string Facility table alias. */
    protected string $facilityalias = 'mohfacility';

    #[\Override]
    protected function initialise(): void {
        global $USER;

        parent::initialise();

        if (is_siteadmin($USER)) {
            return;
        }

        $scope = (new permission_service())->get_scope((int) $USER->id);
        if (!$scope->grants_management()) {
            $this->add_base_condition_sql('1 = 0');
            return;
        }

        // Applying a valid Zone, District or Facility filter is the explicit request to search
        // outside the default jurisdiction. Other core filters (for example name or email) never
        // widen the report by themselves.
        if ($this->hierarchy_filter_applied()) {
            return;
        }

        $useralias = $this->get_entity('user')->get_table_alias('user');
        $scopeparam = database::generate_param_name();
        [$scopefield, $scopevalue] = match ($scope->level) {
            scope_level::ZONE => ['zoneid', $scope->zoneid],
            scope_level::DISTRICT => ['districtid', $scope->districtid],
            scope_level::FACILITY => ['facilityid', $scope->facilityid],
            scope_level::NONE => ['', 0],
        };
        if ($scopefield === '') {
            $this->add_base_condition_sql('1 = 0');
            return;
        }

        $this->add_base_condition_sql(
            "EXISTS (
                SELECT 1
                  FROM {local_mohh_assign} mohscope
                 WHERE mohscope.userid = {$useralias}.id
                   AND mohscope.active = 1
                   AND mohscope.{$scopefield} = :{$scopeparam}
            )",
            [$scopeparam => $scopevalue],
        );
    }

    #[\Override]
    protected function can_view(): bool {
        return has_capability(
            permission_service::CAP_MANAGE_ASSIGNMENTS,
            \context_system::instance(),
        ) || parent::can_view();
    }

    #[\Override]
    public function add_columns(): void {
        parent::add_columns();

        $entityname = $this->get_entity('user')->get_entity_name();
        $notset = get_string('notset', 'local_mohhierarchy');
        $formatter = static fn(?string $value): string => $value === null || $value === ''
            ? get_string('notset', 'local_mohhierarchy')
            : format_string($value);

        $this->add_column((new column(
            'hierarchyzone',
            new \lang_string('zone', 'local_mohhierarchy'),
            $entityname,
        ))
            ->add_joins($this->hierarchy_joins())
            ->add_field("{$this->zonealias}.name")
            ->set_is_sortable(true)
            ->add_callback($formatter));

        $this->add_column((new column(
            'hierarchydistrict',
            new \lang_string('district', 'local_mohhierarchy'),
            $entityname,
        ))
            ->add_joins($this->hierarchy_joins())
            ->add_field("{$this->districtalias}.name")
            ->set_is_sortable(true)
            ->add_callback($formatter));

        $this->add_column((new column(
            'hierarchyfacility',
            new \lang_string('facility', 'local_mohhierarchy'),
            $entityname,
        ))
            ->add_joins($this->hierarchy_joins())
            ->add_field("{$this->facilityalias}.name")
            ->set_is_sortable(true)
            ->add_callback($formatter));

        $this->add_column((new column(
            'hierarchyscope',
            new \lang_string('scopelevel', 'local_mohhierarchy'),
            $entityname,
        ))
            ->add_joins($this->hierarchy_joins())
            ->add_field("{$this->assignmentalias}.scopelevel")
            ->set_is_sortable(true)
            ->add_callback(static function(?string $value) use ($notset): string {
                $scope = $value === null ? null : scope_level::tryFrom($value);
                return $scope === null
                    ? $notset
                    : get_string('scopelevel:' . $scope->value, 'local_mohhierarchy');
            }));
    }

    #[\Override]
    protected function add_filters(): void {
        global $USER;

        parent::add_filters();

        $entityname = $this->get_entity('user')->get_entity_name();
        $zones = [];
        $districts = [];
        $facilities = [];
        // Filter forms are also constructed by a dynamic AJAX endpoint before Moodle assigns
        // $PAGE->context. Always provide the report context explicitly instead of letting
        // format_string() consult the global page object.
        $formatname = fn(string $name): string => format_string($name, true, [
            'context' => $this->get_context(),
        ]);
        foreach ((new permission_service())->get_filterable_facilities((int) $USER->id) as $facility) {
            $zones[(int) $facility->zoneid] = $formatname($facility->zonename);
            $districts[(int) $facility->districtid] = $formatname($facility->districtname);
            $facilities[(int) $facility->facilityid] = $formatname($facility->facilityname);
        }
        \core_collator::asort($facilities, \core_collator::SORT_NATURAL);

        $this->add_filter((new filter(
            select::class,
            'hierarchyzone',
            new \lang_string('zone', 'local_mohhierarchy'),
            $entityname,
            "{$this->assignmentalias}.zoneid",
        ))
            ->add_joins($this->hierarchy_joins())
            ->set_options($zones));

        $this->add_filter((new filter(
            select::class,
            'hierarchydistrict',
            new \lang_string('district', 'local_mohhierarchy'),
            $entityname,
            "{$this->assignmentalias}.districtid",
        ))
            ->add_joins($this->hierarchy_joins())
            ->set_options($districts));

        $this->add_filter((new filter(
            select::class,
            'hierarchyfacility',
            new \lang_string('facility', 'local_mohhierarchy'),
            $entityname,
            "{$this->assignmentalias}.facilityid",
        ))
            ->add_joins($this->hierarchy_joins())
            ->set_options($facilities));
    }

    /**
     * Whether at least one valid hierarchy filter is currently applied to this report.
     *
     * Report Builder stores filter values per user and report. Asking each filter implementation
     * whether it applies also validates the selected value against the server-built option list,
     * so forged filter ids cannot remove the default jurisdiction condition.
     *
     * @return bool
     */
    protected function hierarchy_filter_applied(): bool {
        $values = $this->get_filter_values();
        foreach (['hierarchyzone', 'hierarchydistrict', 'hierarchyfacility'] as $name) {
            $filter = $this->get_filter('user:' . $name);
            if ($filter !== null && select::create($filter)->applies_to_values($values)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    protected function add_actions(): void {
        global $USER;

        parent::add_actions();

        $permissions = new permission_service();
        $this->add_action_divider();
        $this->add_action((new action(
            new \moodle_url('/local/mohhierarchy/assignments.php', ['userid' => ':id']),
            new \pix_icon('t/move', ''),
            [],
            false,
            new \lang_string('transferuser', 'local_mohhierarchy'),
        ))->add_callback(static fn(\stdClass $row): bool => $permissions->can_manage_assignment(
            (int) $USER->id,
            (int) $row->id,
        )));
    }

    /**
     * Joins required by the hierarchy columns and filters.
     *
     * @return string[]
     */
    protected function hierarchy_joins(): array {
        $useralias = $this->get_entity('user')->get_table_alias('user');

        return [
            "LEFT JOIN {local_mohh_assign} {$this->assignmentalias}
                    ON {$this->assignmentalias}.userid = {$useralias}.id
                   AND {$this->assignmentalias}.active = 1",
            "LEFT JOIN {local_mohh_zone} {$this->zonealias}
                    ON {$this->zonealias}.id = {$this->assignmentalias}.zoneid",
            "LEFT JOIN {local_mohh_district} {$this->districtalias}
                    ON {$this->districtalias}.id = {$this->assignmentalias}.districtid",
            "LEFT JOIN {local_mohh_facility} {$this->facilityalias}
                    ON {$this->facilityalias}.id = {$this->assignmentalias}.facilityid",
        ];
    }
}
