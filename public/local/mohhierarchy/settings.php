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
 * Administration settings for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mohhierarchy\local\config;
use local_mohhierarchy\local\hierarchy\permission_service;

defined('MOODLE_INTERNAL') || die();

global $USER;

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_mohhierarchy',
        new lang_string('pluginname', 'local_mohhierarchy'),
    );

    $settings->add(new admin_setting_configtext(
        'local_mohhierarchy/baseurl',
        new lang_string('baseurl', 'local_mohhierarchy'),
        new lang_string('baseurl_desc', 'local_mohhierarchy'),
        config::DEFAULT_BASE_URL,
        PARAM_URL,
    ));

    $settings->add(new admin_setting_configtext(
        'local_mohhierarchy/zonesendpoint',
        new lang_string('zonesendpoint', 'local_mohhierarchy'),
        new lang_string('zonesendpoint_desc', 'local_mohhierarchy'),
        config::DEFAULT_ZONES_ENDPOINT,
        PARAM_RAW_TRIMMED,
    ));

    $settings->add(new admin_setting_configtext(
        'local_mohhierarchy/districtsendpoint',
        new lang_string('districtsendpoint', 'local_mohhierarchy'),
        new lang_string('districtsendpoint_desc', 'local_mohhierarchy'),
        config::DEFAULT_DISTRICTS_ENDPOINT,
        PARAM_RAW_TRIMMED,
    ));

    $settings->add(new admin_setting_configtext(
        'local_mohhierarchy/facilitiesendpoint',
        new lang_string('facilitiesendpoint', 'local_mohhierarchy'),
        new lang_string('facilitiesendpoint_desc', 'local_mohhierarchy'),
        config::DEFAULT_FACILITIES_ENDPOINT,
        PARAM_RAW_TRIMMED,
    ));

    // Password style setting, so the token is masked in the UI and excluded from config exports.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_mohhierarchy/token',
        new lang_string('token', 'local_mohhierarchy'),
        new lang_string('token_desc', 'local_mohhierarchy'),
        '',
    ));

    $connecttimeout = new admin_setting_configduration(
        'local_mohhierarchy/connecttimeout',
        new lang_string('connecttimeout', 'local_mohhierarchy'),
        new lang_string('connecttimeout_desc', 'local_mohhierarchy'),
        config::DEFAULT_CONNECT_TIMEOUT,
        1,
    );
    $connecttimeout->set_min_duration(1);
    $settings->add($connecttimeout);

    $requesttimeout = new admin_setting_configduration(
        'local_mohhierarchy/requesttimeout',
        new lang_string('requesttimeout', 'local_mohhierarchy'),
        new lang_string('requesttimeout_desc', 'local_mohhierarchy'),
        config::DEFAULT_REQUEST_TIMEOUT,
        1,
    );
    $requesttimeout->set_min_duration(1);
    $settings->add($requesttimeout);

    $settings->add(new admin_setting_configcheckbox(
        'local_mohhierarchy/scheduledsyncenabled',
        new lang_string('scheduledsyncenabled', 'local_mohhierarchy'),
        new lang_string('scheduledsyncenabled_desc', 'local_mohhierarchy'),
        1,
    ));

    $ADMIN->add('localplugins', $settings);
}

$ADMIN->add('localplugins', new admin_externalpage(
    'local_mohhierarchy_sync',
    new lang_string('syncadministration', 'local_mohhierarchy'),
    new moodle_url('/local/mohhierarchy/sync.php'),
    permission_service::CAP_MANAGE_SYNC,
));
$ADMIN->add('localplugins', new admin_externalpage(
    'local_mohhierarchy_hierarchy',
    new lang_string('hierarchybrowser', 'local_mohhierarchy'),
    new moodle_url('/local/mohhierarchy/hierarchy.php'),
    permission_service::CAP_VIEW_HIERARCHY,
));
$ADMIN->add('localplugins', new admin_externalpage(
    'local_mohhierarchy_createuser',
    new lang_string('createhierarchyuser', 'local_mohhierarchy'),
    new moodle_url('/local/mohhierarchy/createuser.php'),
    permission_service::CAP_CREATE_USER,
));
$ADMIN->add('localplugins', new admin_externalpage(
    'local_mohhierarchy_assignments',
    new lang_string('assignmentadministration', 'local_mohhierarchy'),
    new moodle_url('/local/mohhierarchy/assignments.php'),
    permission_service::CAP_MANAGE_ASSIGNMENTS,
));

// Give every authorised hierarchy assignment manager, including site administrators, the familiar
// Accounts > Browse list of users entry, but point it at the plugin-owned hierarchy-aware list.
// Moodle's complete core report remains available when /admin/user.php is opened directly.
$systemcontext = context_system::instance();
$canmanageassignments = isloggedin()
    && !isguestuser()
    && has_capability(permission_service::CAP_MANAGE_ASSIGNMENTS, $systemcontext);
if ($canmanageassignments) {
    $scopedusersurl = (new moodle_url('/local/mohhierarchy/assignments.php'))->out(false);
    $edituserspage = $ADMIN->locate('editusers');
    if ($edituserspage instanceof admin_externalpage) {
        $edituserspage->url = $scopedusersurl;
        $edituserspage->req_capability = [permission_service::CAP_MANAGE_ASSIGNMENTS];
    } else if ($ADMIN->locate('accounts') instanceof admin_category) {
        $ADMIN->add('accounts', new admin_externalpage(
            'editusers',
            new lang_string('userlist', 'admin'),
            $scopedusersurl,
            permission_service::CAP_MANAGE_ASSIGNMENTS,
        ));
    }
}
