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

use local_mohhierarchy\local\hierarchy\selector_options;
use local_mohhierarchy\local\user_creation_service;

/**
 * Strict delegated user-creation form.
 *
 * The selectors share the same scope-filtered option provider and facility-first JavaScript module
 * as the hierarchy profile field. JavaScript is only a convenience: validation calls the
 * application service, which resolves and authorises the submitted local ids again.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_user_form extends \moodleform {
    #[\Override]
    public function definition() {
        global $CFG, $PAGE;

        $mform = $this->_form;
        $actorid = (int) $this->_customdata['actorid'];
        $service = $this->service();
        $selectors = $this->selectors();

        $mform->addElement('header', 'account', get_string('accountdetails', 'local_mohhierarchy'));

        $mform->addElement('text', 'username', get_string('username'), ['size' => 30]);
        $mform->setType('username', PARAM_RAW_TRIMMED);
        $mform->addRule('username', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'firstname', get_string('firstname'), ['size' => 30]);
        $mform->setType('firstname', PARAM_NOTAGS);
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'lastname', get_string('lastname'), ['size' => 30]);
        $mform->setType('lastname', PARAM_NOTAGS);
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'email', get_string('email'), ['size' => 40]);
        $mform->setType('email', PARAM_RAW_TRIMMED);
        $mform->addRule('email', get_string('required'), 'required', null, 'client');

        $authoptions = $service->authentication_options();
        $mform->addElement('select', 'auth', get_string('chooseauthmethod', 'auth'), $authoptions);
        $mform->setType('auth', PARAM_ALPHANUMEXT);
        $mform->setDefault('auth', array_key_exists('manual', $authoptions) ? 'manual' : array_key_first($authoptions));
        $mform->addHelpButton('auth', 'delegatedauth', 'local_mohhierarchy');

        $mform->addElement('advcheckbox', 'createpassword', get_string('createpassword', 'auth'));
        $mform->setType('createpassword', PARAM_BOOL);

        if (!empty($CFG->passwordpolicy)) {
            $mform->addElement('static', 'passwordpolicyinfo', '', print_password_policy());
        }
        $mform->addElement(
            'passwordunmask',
            'newpassword',
            get_string('newpassword'),
            ['maxlength' => MAX_PASSWORD_CHARACTERS, 'size' => 30],
        );
        $mform->setType('newpassword', PARAM_RAW);
        $mform->addRule(
            'newpassword',
            get_string('maximumchars', '', MAX_PASSWORD_CHARACTERS),
            'maxlength',
            MAX_PASSWORD_CHARACTERS,
            'client',
        );
        $mform->disabledIf('newpassword', 'createpassword', 'checked');

        $withoutlocalpassword = $service->authentication_methods_without_local_password();
        if ($withoutlocalpassword !== []) {
            $mform->disabledIf('createpassword', 'auth', 'in', $withoutlocalpassword);
            $mform->disabledIf('newpassword', 'auth', 'in', $withoutlocalpassword);
        }

        $mform->addElement('header', 'hierarchy', get_string('hierarchyplacement', 'local_mohhierarchy'));
        $fixed = $selectors->fixed_levels($actorid);
        $defaults = $selectors->defaults($actorid);
        $zones = $selectors->zones($actorid);
        $picker = $selectors->facility_picker($actorid);
        $districts = $picker['districts'];
        $facilities = $picker['facilities'];

        // Keep the familiar hierarchy order. Facility can still drive both ancestor selectors.
        $this->add_hierarchy_level('zoneid', 'zone', $zones, $defaults['zone'], $fixed['zone']);
        $this->add_hierarchy_level(
            'districtid',
            'district',
            $districts,
            $defaults['district'],
            $fixed['district'],
        );
        $this->add_hierarchy_level(
            'facilityid',
            'facility',
            $facilities,
            $defaults['facility'],
            $fixed['facility'],
            true,
        );
        $mform->addHelpButton('facilityid', 'facility', 'local_mohhierarchy');

        $PAGE->requires->js_call_amd('profilefield_mohhierarchy/hierarchy', 'init', [[
            'zoneElement' => 'zoneid',
            'districtElement' => 'districtid',
            'facilityElement' => 'facilityid',
            'zoneId' => $defaults['zone'],
            'districtId' => $defaults['district'],
            'facilityId' => $defaults['facility'],
            'zoneFixed' => $fixed['zone'],
            'districtFixed' => $fixed['district'],
            'facilityFixed' => $fixed['facility'],
            'facilityPaths' => $picker['paths'],
        ]]);

        $this->add_action_buttons(true, get_string('createhierarchyuser', 'local_mohhierarchy'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        return $errors + $this->service()->validate_account_data(
            $data,
            (int) $this->_customdata['actorid'],
        );
    }

    /**
     * Add one hierarchy level, freezing levels fixed by the actor's scope.
     *
     * @param string $elementname Form element name.
     * @param string $labelkey Language label key.
     * @param string[] $options Allowed local ids and names.
     * @param int $default Default local id.
     * @param bool $fixed Whether the actor may change the level.
     * @param bool $searchable Whether to use Moodle's searchable autocomplete control.
     * @return void
     */
    protected function add_hierarchy_level(
        string $elementname,
        string $labelkey,
        array $options,
        int $default,
        bool $fixed,
        bool $searchable = false,
    ): void {
        $mform = $this->_form;
        $label = get_string($labelkey, 'local_mohhierarchy');
        if ($fixed && $options === []) {
            $mform->addElement('static', $elementname . '_unavailable', $label, get_string('none'));
            $mform->addElement('hidden', $elementname, 0);
            $mform->setType($elementname, PARAM_INT);
            return;
        }

        $choices = ($fixed || $searchable) ? $options : ['' => get_string('choosedots')] + $options;
        $attributes = [
            'class' => 'mohhierarchy-selector',
        ];
        if ($searchable) {
            $attributes += [
                'placeholder' => get_string('search'),
                'noselectionstring' => get_string('choosedots'),
            ];
        }
        $mform->addElement($searchable ? 'autocomplete' : 'select', $elementname, $label, $choices, $attributes);
        $mform->setType($elementname, PARAM_INT);
        if ($default > 0 && array_key_exists($default, $options)) {
            $mform->setDefault($elementname, $default);
        }
        if ($fixed) {
            $value = $default > 0 ? $default : (int) array_key_first($options);
            $mform->hardFreeze($elementname);
            $mform->setConstant($elementname, $value);
        }
    }

    /**
     * Application service supplied by the controller.
     *
     * @return user_creation_service
     */
    protected function service(): user_creation_service {
        return $this->_customdata['service'];
    }

    /**
     * Shared hierarchy selector options.
     *
     * @return selector_options
     */
    protected function selectors(): selector_options {
        return $this->_customdata['selectors'];
    }
}
