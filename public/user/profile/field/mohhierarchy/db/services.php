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
 * External function definitions for profilefield_mohhierarchy.
 *
 * Both functions read the local copy of the hierarchy only. Neither one ever contacts the remote
 * service, and neither is a security boundary on its own: the same permission service decides what
 * they return and what the form accepts on save.
 *
 * @package    profilefield_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'profilefield_mohhierarchy_get_districts' => [
        'classname' => 'profilefield_mohhierarchy\external\get_districts',
        'description' => 'List the districts of one zone that the current user may choose from.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'profilefield_mohhierarchy_get_facilities' => [
        'classname' => 'profilefield_mohhierarchy\external\get_facilities',
        'description' => 'List the facilities of one district that the current user may choose from.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
