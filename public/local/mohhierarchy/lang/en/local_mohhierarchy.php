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
 * Strings for local_mohhierarchy.
 *
 * @package    local_mohhierarchy
 * @copyright  2026 Ministry of Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accountdetails'] = 'Account details';
$string['activedistricts'] = 'Active districts';
$string['activefacilities'] = 'Active facilities';
$string['activezones'] = 'Active zones';
$string['assignmentadministration'] = 'User hierarchy assignments';
$string['assignmentsaved'] = 'The hierarchy assignment was saved.';
$string['assignmentwithdrawn'] = 'Withdrawn';
$string['baseurl'] = 'API base URL';
$string['baseurl_desc'] = 'Base URL of the Zipatala API. The endpoint paths below are appended to it.';
$string['cachedef_scope'] = 'Resolved hierarchy scope per user';
$string['cli:manualsyncpending'] = 'A manual synchronisation is queued or running. Use --force to ignore a queued task; the live synchronisation lock is never bypassed.';
$string['col:deactivated'] = 'Deactivated';
$string['col:districts'] = 'Districts (new / changed)';
$string['col:error'] = 'Error';
$string['col:facilities'] = 'Facilities (new / changed)';
$string['col:started'] = 'Started';
$string['col:status'] = 'Status';
$string['col:trigger'] = 'Trigger';
$string['col:zones'] = 'Zones (new / changed)';
$string['connecttimeout'] = 'Connection timeout';
$string['connecttimeout_desc'] = 'How long to wait for the connection to the API to be established.';
$string['consistencyrepair'] = 'Hierarchy consistency repair';
$string['createanotheruser'] = 'Create another hierarchy user';
$string['createdupdatedhint'] = 'Counts are shown as newly created / changed. Records that were unchanged are not counted.';
$string['createhierarchyuser'] = 'Create hierarchy user';
$string['currentcounts'] = 'Current local counts';
$string['delegatedauth'] = 'Authentication method';
$string['delegatedauth_help'] = 'Locally managed authentication methods allow a password or generated-password email. External authentication accounts are created only in Moodle; this page does not call the external identity system or set an external password.';
$string['delegatedcreationaudit'] = 'Created through the strict delegated hierarchy user page.';
$string['delegatedcreationnotice'] = 'This restricted page creates an account only at an active facility within your hierarchy scope. New users receive no delegated management scope.';
$string['district'] = 'District';
$string['districtsendpoint'] = 'Districts endpoint';
$string['districtsendpoint_desc'] = 'Districts path, relative to the base URL, or a complete URL.';
$string['editassignment'] = 'Edit hierarchy assignment';
$string['endpoints'] = 'Configured endpoints';
$string['error:assignmentlocked'] = 'This hierarchy assignment is being changed by another request. Try again.';
$string['error:authnotallowed'] = 'Select an enabled authentication method.';
$string['error:cannotmanageuser'] = 'You are not allowed to manage this user\'s hierarchy assignment.';
$string['error:choosepasswordmode'] = 'Choose either a supplied password or the generated-password option, not both.';
$string['error:createscopeforbidden'] = 'A user created through this page must start with no management scope.';
$string['error:createusernotallowed'] = 'You are not allowed to create hierarchy users.';
$string['error:duplicateexternalid'] = 'The {$a->dataset} response repeats remote ID {$a->id}. No local data was changed.';
$string['error:emptydataset'] = 'The {$a} response contained no records. This would deactivate the whole level, so the run was stopped and no local data was changed.';
$string['error:emptyresponse'] = 'The API returned an empty response body for {$a}.';
$string['error:externalauthpassword'] = 'Passwords cannot be set here for this external authentication method.';
$string['error:facilitynotallowed'] = 'The selected facility is outside your hierarchy scope or is inactive.';
$string['error:facilityrequired'] = 'Select a facility.';
$string['error:hierarchymismatch'] = 'The selected zone, district and facility do not belong to the same hierarchy path.';
$string['error:hierarchywritebusy'] = 'The hierarchy is currently synchronising. Try the assignment again when synchronisation finishes.';
$string['error:httpstatus'] = 'The API returned HTTP status {$a->status} for {$a->url}.';
$string['error:inactivefacility'] = 'Facility "{$a->facility}" ({$a->id}) is no longer active and cannot take new assignments.';
$string['error:invalidassignaction'] = 'Unrecognised assignment history action: {$a}';
$string['error:invalidassignsource'] = 'Unrecognised assignment source: {$a}';
$string['error:invalidcodemapping'] = 'The facility code mapping could not be stored as JSON: {$a}';
$string['error:invalidendpoint'] = 'The configured endpoint does not resolve to an http or https URL: {$a}';
$string['error:invaliditem'] = 'Record {$a->index} of the {$a->dataset} response is not an object.';
$string['error:invalidjson'] = 'The {$a->dataset} response was not valid JSON: {$a->detail}';
$string['error:invalidscopelevel'] = 'Unrecognised hierarchy scope level: {$a}';
$string['error:invalidsyncstatus'] = 'Unrecognised synchronisation status: {$a}';
$string['error:invalidsynctrigger'] = 'Unrecognised synchronisation trigger: {$a}';
$string['error:jsonencodefailed'] = 'The assignment snapshot could not be stored as JSON: {$a}';
$string['error:missingfield'] = 'Record {$a->index} of the {$a->dataset} response is missing the required property "{$a->field}". No local data was changed.';
$string['error:repairinactivepath'] = 'This assignment cannot be repaired automatically because its hierarchy path is missing or inactive.';
$string['error:scopetoobroad'] = 'You cannot grant a scope broader than your own.';
$string['error:syncalreadyrunning'] = 'Another synchronisation is already running. This run was skipped.';
$string['error:syncfailedsanitised'] = 'Hierarchy synchronisation failed: {$a}';
$string['error:transportfailed'] = 'The API could not be reached at {$a->url}: {$a->detail}';
$string['error:unexpectedshape'] = 'The {$a->dataset} response was not a JSON array, and carried no recognised wrapper ({$a->wrappers}).';
$string['error:unknownassignment'] = 'No hierarchy assignment exists for user {$a}.';
$string['error:unknowndistrictreference'] = 'Facility {$a->facility} refers to district {$a->district}, which is in neither the response nor the local copy. No local data was changed.';
$string['error:unknownfacility'] = 'Unknown facility: {$a}';
$string['error:unknownuser'] = 'Unknown or deleted user: {$a}';
$string['error:unknownzonereference'] = 'District {$a->district} refers to zone {$a->zone}, which is in neither the response nor the local copy. No local data was changed.';
$string['error:usercreationinvalid'] = 'The hierarchy user could not be created: {$a}';
$string['error:usercreationlocked'] = 'Another request is creating this username. Try again.';
$string['externalid'] = 'Zipatala ID';
$string['facilitiesendpoint'] = 'Facilities endpoint';
$string['facilitiesendpoint_desc'] = 'Facilities path, relative to the base URL, or a complete URL.';
$string['facility'] = 'Facility';
$string['facility_help'] = 'The zone and district are derived from this facility and cannot be submitted separately.';
$string['facilitycode'] = 'Facility code';
$string['facilityinactivecurrent'] = '{$a} (inactive current assignment)';
$string['generatedpasswordmailfailed'] = 'The account was created and its password was generated, but Moodle could not deliver the password email. Use Moodle\'s standard password reset process.';
$string['hierarchybrowser'] = 'Hierarchy browser';
$string['hierarchyownednotice'] = 'These hierarchy records are read-only and owned by the Zipatala synchronisation. Make corrections in Zipatala, then synchronise again.';
$string['hierarchypath'] = '{$a->zone} / {$a->district} / {$a->facility}';
$string['hierarchypathinactive'] = '{$a->zone} / {$a->district} / {$a->facility} (inactive current assignment)';
$string['hierarchyplacement'] = 'Hierarchy placement';
$string['hierarchyusercreated'] = 'Hierarchy user created';
$string['lastfailure'] = 'Last failed synchronisation';
$string['lastoutcomes'] = 'Latest outcomes';
$string['lastsuccess'] = 'Last successful synchronisation';
$string['localid'] = 'Moodle local ID';
$string['mohhierarchy:createuser'] = 'Create users placed at a facility within their own hierarchy scope';
$string['mohhierarchy:manageassignments'] = 'Change hierarchy assignments within their own hierarchy scope';
$string['mohhierarchy:managesync'] = 'Configure the hierarchy endpoints and run a synchronisation';
$string['mohhierarchy:repairconsistency'] = 'Review and safely repair hierarchy consistency';
$string['mohhierarchy:viewassignments'] = 'View hierarchy assignments within their own hierarchy scope';
$string['mohhierarchy:viewhierarchy'] = 'Browse zones, districts and facilities within their own hierarchy scope';
$string['noassignmentsfound'] = 'No matching users or assignments were found.';
$string['nohierarchyfound'] = 'No matching facilities were found.';
$string['notset'] = 'Not assigned';
$string['pluginname'] = 'MoH hierarchy';
$string['privacy:erasure'] = 'Assignment deactivated by an approved privacy erasure request.';
$string['privacy:metadata:assign'] = 'The current canonical hierarchy placement and delegated management scope.';
$string['privacy:metadata:assign:active'] = 'Whether the assignment is operational.';
$string['privacy:metadata:assign:assignedby'] = 'The user who made the assignment, when applicable.';
$string['privacy:metadata:assign:assignsource'] = 'The workflow that created or changed the assignment.';
$string['privacy:metadata:assign:districtid'] = 'The local district identifier derived from the facility.';
$string['privacy:metadata:assign:facilityid'] = 'The local facility identifier assigned to the user.';
$string['privacy:metadata:assign:scopelevel'] = 'The delegated hierarchy management scope.';
$string['privacy:metadata:assign:timecreated'] = 'When the assignment was first created.';
$string['privacy:metadata:assign:timemodified'] = 'When the assignment was last changed.';
$string['privacy:metadata:assign:userid'] = 'The user who owns the assignment.';
$string['privacy:metadata:assign:zoneid'] = 'The local zone identifier derived from the facility.';
$string['privacy:metadata:assignlog'] = 'Retained audit history of hierarchy assignment changes.';
$string['privacy:metadata:assignlog:action'] = 'The type of assignment change.';
$string['privacy:metadata:assignlog:assignmentid'] = 'The related canonical assignment identifier.';
$string['privacy:metadata:assignlog:changedby'] = 'The user who performed the change, when applicable.';
$string['privacy:metadata:assignlog:newdata'] = 'A JSON snapshot after the change.';
$string['privacy:metadata:assignlog:olddata'] = 'A JSON snapshot before the change.';
$string['privacy:metadata:assignlog:reason'] = 'The recorded reason for the change.';
$string['privacy:metadata:assignlog:timecreated'] = 'When the history entry was created.';
$string['privacy:metadata:assignlog:userid'] = 'The user whose assignment changed.';
$string['privacy:metadata:synclog'] = 'Synchronisation audit records can identify the administrator who queued a manual run.';
$string['privacy:metadata:synclog:triggeredby'] = 'The user who initiated a manual synchronisation.';
$string['reason'] = 'Reason for change';
$string['recentruns'] = 'Recent synchronisation runs';
$string['repairapplied'] = '{$a} safe consistency repair(s) were applied.';
$string['repaircanonicalnotice'] = 'local_mohh_assign is canonical. Safe repair can restore missing mirrors or derived ancestry, but facility conflicts are always left for administrator review.';
$string['repaircode:assignment_profile_empty'] = 'Assignment exists but profile field is empty';
$string['repaircode:custom_only'] = 'Profile field exists without assignment';
$string['repaircode:district_facility_mismatch'] = 'District does not match facility';
$string['repaircode:facility_conflict'] = 'Assignment and profile facilities differ';
$string['repaircode:inactive_reference'] = 'Inactive hierarchy reference';
$string['repaircode:invalid_custom'] = 'Invalid profile facility';
$string['repaircode:missing_field'] = 'Hierarchy profile field is missing';
$string['repaircode:missing_reference'] = 'Missing hierarchy reference';
$string['repaircode:multiple_fields'] = 'Multiple hierarchy profile fields';
$string['repaircode:withdrawn_with_custom'] = 'Withdrawn assignment has a profile value';
$string['repaircode:zone_district_mismatch'] = 'Zone does not match district';
$string['repairconfiguration'] = 'Configuration';
$string['repairconsistent'] = 'No hierarchy consistency issues were found.';
$string['repairdryrun'] = 'Dry run';
$string['repairdryrunresult'] = 'Dry run complete. {$a} safe repair(s) would be applied; no data was changed.';
$string['repairfieldinstances'] = 'Profile field instances';
$string['repairissue'] = 'Consistency issue';
$string['repairissue:assignment_profile_empty'] = 'The canonical assignment has a facility, but its profile-field mirror is empty.';
$string['repairissue:custom_only'] = 'A valid active facility is stored only in the older profile field. A no-scope repair assignment can be created.';
$string['repairissue:district_facility_mismatch'] = 'The assignment district is not the district that owns its canonical facility.';
$string['repairissue:facility_conflict'] = 'Canonical assignment facility {$a->assignment} differs from profile-field value {$a->profile}. This conflict will not be changed automatically.';
$string['repairissue:inactive_reference'] = 'The assignment references a hierarchy path containing an inactive record.';
$string['repairissue:invalid_custom'] = 'The profile field does not contain a valid active facility.';
$string['repairissue:missing_field'] = 'No MoH hierarchy profile-field instance is configured. Automatic mirror repair is disabled.';
$string['repairissue:missing_reference'] = 'The assignment references a hierarchy record that no longer exists.';
$string['repairissue:multiple_fields'] = '{$a} MoH hierarchy profile-field instances exist; exactly one is expected. Automatic repair is disabled.';
$string['repairissue:withdrawn_with_custom'] = 'The assignment is withdrawn but a profile facility remains. Administrator review is required.';
$string['repairissue:zone_district_mismatch'] = 'The assignment zone is not the zone that owns its stored district.';
$string['repairpolicy'] = 'Repair policy';
$string['repairpolicy_desc'] = 'Safe actions only: create a no-scope legacy assignment, restore an empty profile mirror, or re-derive ancestry from the existing canonical facility. Facility conflicts are never moved automatically.';
$string['repairreason:ancestry'] = 'Consistency repair re-derived zone and district from the canonical facility.';
$string['repairreason:custom_only'] = 'Consistency repair migrated a valid legacy profile facility into the canonical assignment.';
$string['repairreason:profile_empty'] = 'Consistency repair restored the empty profile-field mirror from the canonical assignment.';
$string['repairreason:user_deleted'] = 'Assignment withdrawn because the Moodle user was deleted.';
$string['repairreview'] = 'Review required';
$string['repairreviewcount'] = 'Review required';
$string['repairsafe'] = 'Safe repair';
$string['repairsafeactions'] = 'Apply safe repairs';
$string['repairsafecount'] = 'Safe repairs';
$string['repairuserschecked'] = 'Users checked';
$string['requesttimeout'] = 'Request timeout';
$string['requesttimeout_desc'] = 'Total time allowed for one API request, including transfer of the response.';
$string['saveassignment'] = 'Save assignment';
$string['scheduledsyncenabled'] = 'Enable scheduled synchronisation';
$string['scheduledsyncenabled_desc'] = 'When disabled, the scheduled task does nothing. Manual and command line synchronisation still work.';
$string['scopelevel'] = 'Management scope';
$string['scopelevel:district'] = 'District';
$string['scopelevel:facility'] = 'Facility';
$string['scopelevel:none'] = 'None';
$string['scopelevel:zone'] = 'Zone';
$string['searchhierarchy'] = 'Search zone, district, facility name or facility code';
$string['searchusers'] = 'Search username, first name, surname or email';
$string['stateactive'] = 'Active';
$string['stateinactive'] = 'Inactive';
$string['syncadministration'] = 'Hierarchy synchronisation';
$string['synccompleted'] = 'Synchronisation finished. Zones {$a->zonescreated} new / {$a->zonesupdated} changed, districts {$a->districtscreated} new / {$a->districtsupdated} changed, facilities {$a->facilitiescreated} new / {$a->facilitiesupdated} changed, {$a->itemsdeactivated} deactivated.';
$string['syncdisabled'] = 'Scheduled synchronisation is disabled in the plugin settings. Nothing to do.';
$string['synclogempty'] = 'No synchronisation has run yet.';
$string['syncnever'] = 'Never';
$string['syncnow'] = 'Synchronise hierarchy now';
$string['syncpending'] = 'A manual hierarchy synchronisation is already queued or running.';
$string['syncprogress'] = 'Synchronising the Ministry of Health hierarchy';
$string['syncprogressfailed'] = 'The hierarchy synchronisation failed. See the synchronisation history for the sanitised error.';
$string['syncprogressheading'] = 'Hierarchy synchronisation progress';
$string['syncprogressqueued'] = 'The task is waiting for Moodle cron. This status updates automatically after the task starts. Site administrators can use Run now below.';
$string['syncqueued'] = 'The hierarchy synchronisation was queued. It will run in the background.';
$string['syncstage:deactivate'] = 'Deactivating hierarchy records no longer published by Zipatala';
$string['syncstage:fetchdistricts'] = 'Fetching districts from Zipatala';
$string['syncstage:fetchfacilities'] = 'Fetching facilities from Zipatala';
$string['syncstage:fetchzones'] = 'Fetching zones from Zipatala';
$string['syncstage:finalise'] = 'Finalising the synchronisation';
$string['syncstage:savedistricts'] = 'Saving districts';
$string['syncstage:savefacilities'] = 'Saving facilities';
$string['syncstage:savezones'] = 'Saving zones';
$string['syncstage:validate'] = 'Validating hierarchy relationships';
$string['syncstarting'] = 'Starting hierarchy synchronisation from {$a}...';
$string['syncstartingcli'] = 'Starting command-line hierarchy synchronisation...';
$string['syncstartingmanual'] = 'Starting queued manual hierarchy synchronisation...';
$string['syncstartingscheduled'] = 'Starting scheduled hierarchy synchronisation...';
$string['syncstatus:failed'] = 'Failed';
$string['syncstatus:running'] = 'Running';
$string['syncstatus:success'] = 'Success';
$string['synctrigger:cli'] = 'Command line';
$string['synctrigger:manual'] = 'Manual';
$string['synctrigger:scheduled'] = 'Scheduled';
$string['task:synchierarchy'] = 'Synchronise MoH hierarchy';
$string['task:syncnow'] = 'Run queued MoH hierarchy synchronisation';
$string['token'] = 'Bearer token';
$string['token_desc'] = 'Optional. Sent as an Authorization header when set. The token is never written to logs, error messages or the synchronisation log.';
$string['tokennotset'] = 'Not configured';
$string['tokenset'] = 'Configured (hidden)';
$string['userassignments'] = 'Active user assignments';
$string['usercreatedsuccess'] = '{$a->fullname} ({$a->username}) was created and assigned with no management scope.';
$string['zone'] = 'Zone';
$string['zonesendpoint'] = 'Zones endpoint';
$string['zonesendpoint_desc'] = 'Zones path, relative to the base URL, or a complete URL.';
