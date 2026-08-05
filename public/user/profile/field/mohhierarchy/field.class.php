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

/**
 * MoH hierarchy profile field.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\local\assign_source;
use local_mohhierarchy\local\hierarchy\assignment_service;
use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;
use profilefield_mohhierarchy\local\hierarchy_options;

/**
 * MoH hierarchy profile field.
 *
 * Renders zone, district and facility selectors inside Moodle's standard custom profile field
 * section, so /user/editadvanced.php and /user/edit.php need no modification. The stored value is
 * always the local local_mohh_facility.id; the zone and district are derived from it and are never
 * read back from the submission. This class contains no hierarchy policy and writes no hierarchy
 * table: it asks local_mohhierarchy for the options, the decisions and the writes.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_field_mohhierarchy extends profile_field_base {
    /** @var hierarchy_options|null Lazily built option provider. */
    protected ?hierarchy_options $options = null;

    /** @var assignment_service|null Lazily built assignment service. */
    protected ?assignment_service $assignments = null;

    /** @var permission_service|null Lazily built permission service. */
    protected ?permission_service $permissions = null;

    /** @var \stdClass|null Resolved starting selection, computed once per request. */
    protected ?\stdClass $selection = null;

    /** @var bool Whether $this->selection has been computed. */
    protected bool $selectionresolved = false;

    /**
     * Add the three selectors to the form.
     *
     * @param MoodleQuickForm $mform The user edit form.
     * @return void
     */
    public function edit_field_add($mform) {
        global $USER, $PAGE;

        $actorid = (int) $USER->id;
        $options = $this->options();
        $selection = $this->selection();
        $fixed = $options->fixed_levels($actorid);

        // Element names are all derived from the real profile field input name, so two instances of
        // the field, or another plugin's selectors, can never collide.
        $zoneelement = $this->inputname . '_zone';
        $districtelement = $this->inputname . '_district';

        $zones = $options->zones($actorid);
        $picker = $options->facility_picker($actorid);
        $districts = $picker['districts'];
        $facilities = $picker['facilities'];
        $facilities = $this->keep_current_facility($facilities, $selection);
        if ($selection !== null) {
            $context = context_system::instance();
            $zones[$selection->zoneid] ??= format_string($selection->zonename, true, ['context' => $context]);
            $districts[$selection->districtid] ??= format_string(
                $selection->districtname,
                true,
                ['context' => $context],
            );
            $picker['paths'][$selection->facilityid] = [
                'zoneid' => (int) $selection->zoneid,
                'districtid' => (int) $selection->districtid,
            ];
        }

        $zoneid = $this->preferred_id($selection?->zoneid, $zones, $fixed['zone']);
        $districtid = $this->preferred_id($selection?->districtid, $districts, $fixed['district']);
        $facilityid = $this->preferred_id($selection?->facilityid, $facilities, $fixed['facility']);

        // Keep the familiar hierarchy order. Facility can still drive both ancestor selectors.
        $this->add_level($mform, $zoneelement, 'zone', $zones, $zoneid, $fixed['zone'], 'nozones');
        $this->add_level(
            $mform,
            $districtelement,
            'district',
            $districts,
            $districtid,
            $fixed['district'],
            'nodistricts',
        );
        $this->add_level(
            $mform,
            $this->inputname,
            'facility',
            $facilities,
            $facilityid,
            $fixed['facility'],
            'nofacilities',
            true,
        );
        if ((int) $this->userid <= 0) {
            // Facility is the canonical placement value. Requiring it also guarantees that Zone
            // and District can be derived, while edit_validate_field() repeats the check on the
            // server in case client-side validation is bypassed.
            $mform->addRule(
                $this->inputname,
                get_string('error:facilityrequired', 'profilefield_mohhierarchy'),
                'required',
                null,
                'client',
            );
        }

        $PAGE->requires->js_call_amd('profilefield_mohhierarchy/hierarchy', 'init', [[
            'zoneElement' => $zoneelement,
            'districtElement' => $districtelement,
            'facilityElement' => $this->inputname,
            'zoneId' => $zoneid,
            'districtId' => $districtid,
            'facilityId' => $facilityid,
            'zoneFixed' => $fixed['zone'],
            'districtFixed' => $fixed['district'],
            'facilityFixed' => $fixed['facility'],
            'facilityPaths' => $picker['paths'],
        ]]);
    }

    /**
     * Add one level, as a select when it is selectable and as a frozen value when it is fixed.
     *
     * A fixed level still submits its value: Moodle's own edit_field_set_locked() uses
     * hardFreeze() together with setConstant(), and the same pair is used here.
     *
     * @param MoodleQuickForm $mform The form.
     * @param string $elementname Element name.
     * @param string $labelkey Language string key for the label.
     * @param string[] $choices Available options, id => name.
     * @param int $selected The option to start on.
     * @param bool $isfixed Whether the actor may change this level.
     * @param string $emptykey Language string key used when there are no options at all.
     * @param bool $searchable Whether to use Moodle's searchable autocomplete control.
     * @return void
     */
    protected function add_level(
        MoodleQuickForm $mform,
        string $elementname,
        string $labelkey,
        array $choices,
        int $selected,
        bool $isfixed,
        string $emptykey,
        bool $searchable = false,
    ): void {
        $label = get_string($labelkey, 'profilefield_mohhierarchy');

        if (!$choices && $isfixed) {
            // Nothing to offer. Show why, and still submit an empty value so validation can speak.
            $mform->addElement(
                'static',
                $elementname . '_empty',
                $label,
                get_string($emptykey, 'profilefield_mohhierarchy')
            );
            $mform->addElement('hidden', $elementname, 0);
            $mform->setType($elementname, PARAM_INT);
            return;
        }

        // Select and autocomplete controls both need a real empty option. Without it, the browser
        // selects the first Facility and the hierarchy JavaScript consequently fills its parents.
        $withplaceholder = $isfixed
            ? $choices
            : ['' => get_string('select' . $labelkey, 'profilefield_mohhierarchy')] + $choices;

        $attributes = [
            'class' => 'mohhierarchy-selector',
        ];
        if ($searchable) {
            $attributes += [
                'placeholder' => get_string('search'),
                'noselectionstring' => get_string('selectfacility', 'profilefield_mohhierarchy'),
            ];
        }
        $mform->addElement($searchable ? 'autocomplete' : 'select', $elementname, $label, $withplaceholder, $attributes);
        $mform->setType($elementname, PARAM_INT);
        if ($selected > 0 && array_key_exists($selected, $choices)) {
            $mform->setDefault($elementname, $selected);
        }
        if ($isfixed) {
            $mform->hardFreeze($elementname);
            $mform->setConstant($elementname, $selected > 0 ? $selected : (int) array_key_first($choices));
        }
    }

    /**
     * Replace the default handling so the selectors keep the value resolved from the assignment.
     *
     * The base implementation would set the facility element back to the field's stored default,
     * which is empty, undoing the preselection done in edit_field_add().
     *
     * @param MoodleQuickForm $mform The form.
     * @return void
     */
    public function edit_field_set_default($mform) {
        $selection = $this->selection();
        if ($selection !== null && $mform->elementExists($this->inputname)) {
            $mform->setDefault($this->inputname, $selection->facilityid);
        }
    }

    /**
     * Load the resolved facility rather than the raw stored value.
     *
     * @param stdClass $user The user object the form is loaded from.
     * @return void
     */
    public function edit_load_user_data($user) {
        $selection = $this->selection();
        $user->{$this->inputname} = $selection === null ? '' : (string) $selection->facilityid;
    }

    /**
     * Decide who may edit the hierarchy of this user.
     *
     * This deliberately replaces the base implementation rather than extending it. The base method
     * would allow anyone with moodle/user:editownprofile to change their own field, which is exactly
     * what must not happen here. The rules are:
     *
     * - Creating a user: moodle/user:create, plus either site administrator, or
     *   local/mohhierarchy:createuser together with a usable hierarchy scope.
     * - Editing a user: the standard Moodle update permission, plus the local permission service
     *   agreeing that this actor may manage this target's assignment.
     * - Nobody but a site administrator may edit their own hierarchy.
     *
     * @return bool
     */
    public function is_editable() {
        global $USER;

        $system = context_system::instance();
        $actorid = (int) $USER->id;
        if ($actorid <= 0) {
            return false;
        }

        if ((int) $this->userid <= 0) {
            if (!has_capability('moodle/user:create', $system)) {
                return false;
            }
            if (is_siteadmin($actorid)) {
                return true;
            }

            return has_capability(permission_service::CAP_CREATE_USER, $system)
                && $this->permissions()->get_scope($actorid)->grants_management();
        }

        if (is_siteadmin($actorid)) {
            return true;
        }
        // An ordinary user may see their hierarchy but never change it through their own profile.
        if ((int) $this->userid === $actorid) {
            return false;
        }

        $usercontext = context_user::instance((int) $this->userid, IGNORE_MISSING);
        $mayupdate = has_capability('moodle/user:update', $system)
            || ($usercontext && has_capability('moodle/user:editprofile', $usercontext, $actorid));
        if (!$mayupdate) {
            return false;
        }

        return $this->permissions()->can_manage_assignment($actorid, (int) $this->userid);
    }

    /**
     * Validate the submitted facility on the server, whatever the browser sent.
     *
     * @param stdClass $usernew The submitted user data.
     * @return array Error messages keyed by element name.
     */
    public function edit_validate_field($usernew) {
        global $USER;

        $errors = [];
        $targetuserid = (int) ($usernew->id ?? $this->userid);
        $iscreating = $targetuserid <= 0;
        if (!property_exists($usernew, $this->inputname)) {
            // An editable new-user form cannot bypass placement by omitting the input entirely.
            // Forms where this field is genuinely unavailable remain unaffected.
            if ($iscreating && $this->is_editable()) {
                $errors[$this->inputname] = get_string('error:facilityrequired', 'profilefield_mohhierarchy');
            }
            return $errors;
        }

        $options = $this->options();
        $submitted = $options->clean_facility_id($usernew->{$this->inputname});
        $existing = $iscreating ? null : $this->assignments()->get_assignment($targetuserid);

        if ($submitted <= 0) {
            if ($iscreating) {
                $errors[$this->inputname] = get_string('error:facilityrequired', 'profilefield_mohhierarchy');
            }
            // For an existing user an empty submission means "leave the assignment alone".
            return $errors;
        }

        // Resolve the facility, and with it the district and zone, from the database only.
        $facility = $options->describe_facility($submitted);
        if ($facility === null) {
            $errors[$this->inputname] = get_string('error:invalidfacility', 'profilefield_mohhierarchy');
            return $errors;
        }

        $unchanged = $existing !== null && (int) $existing->facilityid === $submitted;
        if ($unchanged) {
            // Re-saving a user who already sits at this facility is allowed even if the facility
            // has since been withdrawn upstream, so an unrelated edit does not force a move.
            return $errors;
        }

        if (!$facility->facilityactive) {
            $errors[$this->inputname] = get_string('error:facilityinactive', 'profilefield_mohhierarchy');
            return $errors;
        }

        $actorid = (int) $USER->id;
        $allowed = $iscreating
            ? $this->permissions()->can_create_user_in_facility($actorid, $submitted)
            : $this->permissions()->can_assign_user_to_facility($actorid, $submitted);
        if (!$allowed) {
            $errors[$this->inputname] = get_string('error:facilitynotallowed', 'profilefield_mohhierarchy');
        }

        return $errors;
    }

    /**
     * Store the facility and keep the canonical assignment in step, in one transaction.
     *
     * @param stdClass $usernew The submitted user data, with the user id already set.
     * @return void
     */
    public function edit_save_data($usernew) {
        global $DB, $USER;

        if (!isset($usernew->{$this->inputname})) {
            return;
        }
        $facilityid = $this->options()->clean_facility_id($usernew->{$this->inputname});
        $userid = (int) ($usernew->id ?? 0);
        if ($facilityid <= 0 || $userid <= 0) {
            // Nothing chosen: leave both the stored value and the assignment as they are. Removing
            // an assignment is an explicit administrative action, not a side effect of a form save.
            return;
        }

        $existing = $this->assignments()->get_assignment($userid);
        // A newly created user starts with no delegated management. An existing scope is preserved,
        // because only an administrator may grant facility, district or zone scope, and never here.
        $scope = $existing === null
            ? scope_level::NONE
            : scope_level::from_value($existing->scopelevel);

        $usernew->{$this->inputname} = (string) $facilityid;

        // Both writes share one transaction, so the mirror can never disagree with the canonical
        // assignment. Any failure here is a genuine race, for example a facility deactivated
        // between validation and save, and must not be swallowed.
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->assignments()->assign_user(
                $userid,
                $facilityid,
                $scope,
                (int) $USER->id,
                assign_source::USERFORM,
            );
            parent::edit_save_data($usernew);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Show the hierarchy as "Zone / District / Facility".
     *
     * @return string
     */
    public function display_data() {
        $selection = $this->selection();
        if ($selection === null) {
            return get_string('notset', 'profilefield_mohhierarchy');
        }

        return $this->options()->format_path($selection);
    }

    /**
     * Whether the field has no usable value.
     *
     * @return bool
     */
    public function is_empty() {
        return $this->selection() === null;
    }

    /**
     * The starting selection for this user, resolved once.
     *
     * @return \stdClass|null
     */
    protected function selection(): ?\stdClass {
        if (!$this->selectionresolved) {
            $this->selection = $this->options()->initial_selection((int) $this->userid, $this->data);
            $this->selectionresolved = true;
        }

        return $this->selection;
    }

    /**
     * Keep an existing, now inactive, facility in the list so re-saving does not force a move.
     *
     * @param string[] $facilities The selectable facilities.
     * @param \stdClass|null $selection The starting selection.
     * @return string[]
     */
    protected function keep_current_facility(array $facilities, ?\stdClass $selection): array {
        if ($selection === null || $selection->facilityactive) {
            return $facilities;
        }
        if (array_key_exists($selection->facilityid, $facilities)) {
            return $facilities;
        }
        $facilities[$selection->facilityid] = get_string(
            'inactivesuffix',
            'profilefield_mohhierarchy',
            format_string($selection->facilityname, true, ['context' => context_system::instance()]),
        );

        return $facilities;
    }

    /**
     * Pick the option a level should start on.
     *
     * @param int|null $preferred The value resolved from the assignment, if any.
     * @param string[] $choices The available options.
     * @param bool $isfixed Whether the level is fixed, in which case the only option wins.
     * @return int Zero when nothing can be preselected.
     */
    protected function preferred_id(?int $preferred, array $choices, bool $isfixed): int {
        if ($preferred !== null && $preferred > 0 && array_key_exists($preferred, $choices)) {
            return $preferred;
        }
        if ($isfixed && $choices) {
            return (int) array_key_first($choices);
        }

        return 0;
    }

    /**
     * The option provider.
     *
     * @return hierarchy_options
     */
    protected function options(): hierarchy_options {
        return $this->options ??= new hierarchy_options();
    }

    /**
     * The assignment service, the only path to the assignment table.
     *
     * @return assignment_service
     */
    protected function assignments(): assignment_service {
        return $this->assignments ??= new assignment_service();
    }

    /**
     * The permission service.
     *
     * @return permission_service
     */
    protected function permissions(): permission_service {
        return $this->permissions ??= new permission_service();
    }
}
