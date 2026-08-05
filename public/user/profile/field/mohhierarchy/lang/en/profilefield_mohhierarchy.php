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
 * Strings for profilefield_mohhierarchy.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['categoryname'] = 'Organisation hierarchy';
$string['definenote'] = 'This field stores the local facility ID from the MoH hierarchy plugin. Zone and district are derived from the facility, and the canonical assignment is kept in step automatically. The field is deliberately not available on the signup page.';
$string['district'] = 'District';
$string['error:facilityinactive'] = 'That facility is no longer active and cannot be used for a new or changed assignment.';
$string['error:facilitynotallowed'] = 'You are not allowed to place a user at that facility.';
$string['error:facilityrequired'] = 'Choose a facility.';
$string['error:invalidfacility'] = 'That is not a valid facility.';
$string['error:shortnameconflict'] = 'A user profile field with the short name "{$a->shortname}" already exists with the data type "{$a->datatype}". Rename or remove that field, then install the MoH hierarchy profile field again.';
$string['facility'] = 'Facility';
$string['fielddescription'] = 'The facility this user belongs to. The zone and district are derived from it.';
$string['fieldname'] = 'Facility hierarchy';
$string['inactivesuffix'] = '{$a} (inactive)';
$string['loadfailed'] = 'The list could not be loaded. Reload the page and try again.';
$string['loading'] = 'Loading...';
$string['nodistricts'] = 'No districts available';
$string['nofacilities'] = 'No facilities available';
$string['notset'] = 'Not set';
$string['nozones'] = 'No zones available';
$string['pluginname'] = 'MoH hierarchy';
$string['privacy:metadata:data'] = 'The local facility identifier mirrored from the canonical hierarchy assignment.';
$string['privacy:metadata:dataformat'] = 'The storage format of the field value.';
$string['privacy:metadata:fieldid'] = 'The hierarchy profile-field definition.';
$string['privacy:metadata:table'] = 'The profile-field mirror of a user\'s hierarchy facility.';
$string['privacy:metadata:userid'] = 'The user who owns the profile-field value.';
$string['selectdistrict'] = 'Choose a district';
$string['selectfacility'] = 'Choose a facility';
$string['selectzone'] = 'Choose a zone';
$string['zone'] = 'Zone';
