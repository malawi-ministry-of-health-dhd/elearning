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
 * Bare form used to exercise the profile field's rendering.
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Bare form used to exercise the profile field's rendering.
 *
 * It defines no elements of its own: the field under test adds them, exactly as
 * profile_definition() does on the real user edit form.
 *
 * @package    profilefield_mohhierarchy
 * @category   test
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefield_mohhierarchy_test_form extends moodleform {
    /**
     * No elements of its own.
     *
     * @return void
     */
    public function definition() {
    }

    /**
     * The underlying QuickForm, so a test can add elements to it and inspect them.
     *
     * @return MoodleQuickForm
     */
    public function get_mform(): MoodleQuickForm {
        return $this->_form;
    }
}
