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

namespace local_mohhierarchy\form;

use local_mohhierarchy\local\hierarchy\permission_service;
use local_mohhierarchy\local\scope_level;

/**
 * Assign one user to a facility, with a scope level.
 *
 * The facility list and the scope menu are both built from what the acting administrator is allowed
 * to do, and both are re-checked in validation, so a tampered submission cannot widen either. The
 * zone and district are not offered at all: they are derived from the facility on save.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_form extends \moodleform {
    #[\Override]
    public function definition() {
        $mform = $this->_form;
        // The user being assigned.
        $target = $this->_customdata['target'];
        // Local facility id => name, already scope filtered.
        $facilities = $this->_customdata['facilities'];
        // The scope levels this actor may grant.
        $scopes = $this->_customdata['scopes'];

        $mform->addElement('hidden', 'userid', $target->userid);
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'search', $this->_customdata['search'] ?? '');
        $mform->setType('search', PARAM_TEXT);

        $mform->addElement('static', 'targetname', get_string('user'), $this->_customdata['targetname']);

        $mform->addElement(
            'select',
            'facilityid',
            get_string('facility', 'local_mohhierarchy'),
            ['' => get_string('choosedots')] + $facilities,
        );
        $mform->setType('facilityid', PARAM_INT);
        $mform->addRule('facilityid', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('facilityid', 'facility', 'local_mohhierarchy');

        $scopemenu = [];
        foreach ($scopes as $scope) {
            $scopemenu[$scope->value] = get_string('scopelevel:' . $scope->value, 'local_mohhierarchy');
        }
        $mform->addElement('select', 'scopelevel', get_string('scopelevel', 'local_mohhierarchy'), $scopemenu);
        $mform->setType('scopelevel', PARAM_ALPHA);
        $mform->setDefault('scopelevel', scope_level::NONE->value);

        $mform->addElement('text', 'reason', get_string('reason', 'local_mohhierarchy'), ['size' => 60]);
        $mform->setType('reason', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('saveassignment', 'local_mohhierarchy'));
    }

    #[\Override]
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        $permissions = $this->_customdata['permissions'] ?? new permission_service();
        $actorid = (int) $USER->id;
        if ((int) ($data['userid'] ?? 0) !== (int) $this->_customdata['target']->userid) {
            $errors['facilityid'] = get_string('error:cannotmanageuser', 'local_mohhierarchy');
        }

        $facilityid = (int) ($data['facilityid'] ?? 0);
        if ($facilityid <= 0) {
            $errors['facilityid'] = get_string('error:facilityrequired', 'local_mohhierarchy');
        } else if (
            !$permissions->can_assign_user_to_facility($actorid, $facilityid)
            && $facilityid !== (int) ($this->_customdata['currentfacilityid'] ?? 0)
        ) {
            $errors['facilityid'] = get_string('error:facilitynotallowed', 'local_mohhierarchy');
        }

        $scope = scope_level::tryFrom((string) ($data['scopelevel'] ?? ''));
        if ($scope === null) {
            $errors['scopelevel'] = get_string('error:invalidscopelevel', 'local_mohhierarchy', '');
        } else if (!$permissions->can_grant_scope($actorid, $scope)) {
            $errors['scopelevel'] = get_string('error:scopetoobroad', 'local_mohhierarchy');
        }

        if (!$permissions->can_manage_assignment($actorid, (int) ($data['userid'] ?? 0))) {
            $errors['facilityid'] = get_string('error:cannotmanageuser', 'local_mohhierarchy');
        }

        return $errors;
    }
}
