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
 * Definition form for the MoH hierarchy profile field.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Definition form for the MoH hierarchy profile field.
 *
 * The field has no configurable parameters: its options come from the synchronised hierarchy and
 * its value is always a local facility id, so there is nothing for an administrator to set beyond
 * the standard common settings.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_define_mohhierarchy extends profile_define_base {
    /**
     * Add the field specific part of the definition form.
     *
     * @param MoodleQuickForm $form The definition form.
     * @return void
     */
    public function define_form_specific($form) {
        $form->addElement(
            'static',
            'mohhierarchynote',
            get_string('pluginname', 'profilefield_mohhierarchy'),
            get_string('definenote', 'profilefield_mohhierarchy'),
        );
    }

    /**
     * Keep the field out of the signup form whatever the common settings say.
     *
     * The hierarchy of a self-registering visitor cannot be validated against a creator's scope,
     * because there is no creator, so the field must never appear on public signup.
     *
     * @param stdClass $data The submitted definition.
     * @return stdClass
     */
    public function define_save_preprocess($data) {
        $data->signup = 0;

        return $data;
    }
}
