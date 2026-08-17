# Ministry of Health hierarchy for Moodle

`local_mohhierarchy` and `profilefield_mohhierarchy` integrate the Ministry of Health organisation
tree with Moodle:

```text
Zone -> District -> Facility -> User
```

Zipatala is the hierarchy source of truth. Moodle keeps a normalised local copy for reliability,
performance, reporting and server-side authorisation. Remote records which disappear are
deactivated rather than deleted.

## Supported Moodle version

This release supports Moodle 5.2 (`$version >= 2026042000`) on a supported PHP and database version
for Moodle 5.2. It was developed and tested on Moodle 5.2.1+ with PHP 8.3 and MySQL 8.

## Architecture

The solution consists of two plugins:

- `local_mohhierarchy` owns synchronisation, local hierarchy tables, canonical assignments,
  permissions, administration, privacy handling and audit history.
- `profilefield_mohhierarchy` integrates the hierarchy selectors with Moodle's custom-profile-field
  APIs and delegates all policy decisions to the local plugin.

Synchronisation fetches and validates all three datasets before starting a delegated database
transaction. A failed fetch, invalid payload or database write leaves the previous hierarchy copy
unchanged. The canonical user assignment is `local_mohh_assign`. The custom profile value stores
the local facility ID in `user_info_data`; district and zone are always derived from that facility.

JavaScript filtering is a user-interface convenience, not a security control. The server resolves
and validates every submitted facility, its active ancestry and the acting user's scope. Existing
Moodle custom fields are not modified.

More detail is available in `docs/architecture.md`, `docs/delegated-user-creation.md`, and
`docs/privacy-and-consistency.md`.

## Installation

Install in this order:

1. `local_mohhierarchy`
2. `profilefield_mohhierarchy`

For a traditional Moodle tree, extract the plugins to:

```text
<moodle-root>/local/mohhierarchy
<moodle-root>/user/profile/field/mohhierarchy
```

For Moodle's Composer/public-directory layout, these paths are beneath `$CFG->dirroot`, commonly:

```text
<repository>/public/local/mohhierarchy
<repository>/public/user/profile/field/mohhierarchy
```

Then run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

The packaged archives contain plugin files directly at their ZIP roots. Create the target
directory first, then extract the corresponding archive into it.

## Upgrades

Back up the database, dataroot and both plugin directories. Replace the plugin files without
removing configuration, install the local plugin before its profile-field dependency, and run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Do not copy a new version over a partially deleted directory. Review release notes and test the
upgrade on a copy of production first.

## Zipatala configuration

Configure the plugin at:

`Site administration > Plugins > Local plugins > MoH hierarchy`

Defaults:

| Setting | Default |
| --- | --- |
| Base URL | `https://zipatala.health.gov.mw/api` |
| Zones endpoint | `/zones` |
| Districts endpoint | `/districts` |
| Facilities endpoint | `/facilities` |
| Connection timeout | 10 seconds |
| Request timeout | 120 seconds |
| Scheduled sync | Enabled |

Endpoint values may be relative to the base URL or complete HTTP(S) URLs. Production systems should
use HTTPS. The bearer token is optional; when configured it is sent as an `Authorization: Bearer`
header. It is masked in administration, redacted from exceptions and logs, and must not be placed
in source control or command lines.

## Cron and synchronisation

Moodle cron must run at least once per minute:

```cron
* * * * * /usr/bin/php /path/to/moodle/admin/cli/cron.php >/dev/null 2>&1
```

The scheduled task `\local_mohhierarchy\task\sync_hierarchy` runs daily at approximately 02:00
server time with a random minute. Administrators can change its schedule under:

`Site administration > Server > Tasks > Scheduled tasks`

Disable only the plugin's scheduled-sync setting when manual or external orchestration is required.

Manual synchronisation is available at:

`Site administration > Plugins > Local plugins > Hierarchy synchronisation`

It requires `local/mohhierarchy:managesync`, a valid sesskey and queues one adhoc task. Duplicate
queued or running synchronisations are refused. The dashboard uses Moodle's stored task progress
to show whether the task is waiting for cron and to report live fetch, validation, persistence,
deactivation and finalisation progress. Site administrators can use the displayed **Run now**
action when a queued task has not yet been picked up by cron.

Command-line synchronisation:

```bash
php local/mohhierarchy/cli/sync.php
php local/mohhierarchy/cli/sync.php --force
php local/mohhierarchy/cli/sync.php --help
```

`--force` ignores a queued manual task but never bypasses the live synchronisation lock. Failures
return a non-zero exit status and sanitised output.

## Roles and capabilities

All capabilities are system-context capabilities:

| Capability | Purpose |
| --- | --- |
| `local/mohhierarchy:managesync` | Configure and start hierarchy synchronisation |
| `local/mohhierarchy:viewhierarchy` | Browse permitted hierarchy records |
| `local/mohhierarchy:manageassignments` | Change permitted user assignments and scopes |
| `local/mohhierarchy:createuser` | Use strict delegated user creation |
| `local/mohhierarchy:viewassignments` | View permitted user assignments |
| `local/mohhierarchy:repairconsistency` | Inspect and apply safe consistency repairs |

Capabilities are necessary but not sufficient for delegated managers: an active canonical
assignment must also grant an appropriate hierarchy scope.

Scope definitions:

- `none`: no delegated hierarchy management.
- `facility`: the assigned facility only.
- `district`: every active facility in the assigned district.
- `zone`: every active district and facility in the assigned zone.

A manager cannot grant a broader scope than their own, modify their own scope, manage a user whose
scope is broader than theirs, manage a user outside their geographic scope, or target a site
administrator. Unassigned and withdrawn users are outside a delegated manager's jurisdiction. Site
administrators may manage the entire hierarchy.

Delegated managers should normally receive `local/mohhierarchy:createuser` and
`local/mohhierarchy:viewhierarchy`, but not `moodle/user:create`. Granting `moodle/user:create`
exposes Moodle's broader core account-creation routes.

Delegated hierarchy managers should also normally not receive `moodle/user:update` or
`moodle/user:delete`; those capabilities expose Moodle's unrestricted core user report and account
actions. With `local/mohhierarchy:manageassignments`, the standard **Browse list of users**
navigation entry instead opens the plugin's jurisdiction-filtered user list. Site administrators
continue to use `/admin/user.php`.

## User creation and assignment

Site administrators may continue using Moodle's unmodified advanced form:

`/user/editadvanced.php?id=-1`

The profile plugin adds Zone, District and Facility selectors alongside all existing custom profile
fields. They retain that hierarchy order, while Facility is a searchable autocomplete control that
displays only the facility name. A new user's hierarchy starts blank. Choosing a Facility populates
its District and Zone from the local hierarchy, and the account cannot be saved until a permitted
Facility has been selected. The controls have a stable responsive width, and the server
independently derives and validates the facility ancestry on every submission. Trusted roles with
`moodle/user:create` can also use this page after separate review.

Strict delegated managers use:

`/local/mohhierarchy/createuser.php`

This page requires `local/mohhierarchy:createuser`, uses the same searchable Facility control,
restricts facilities to the creator's active scope, uses Moodle's user API, and gives every new
user scope `none`.

Assignments are administered separately at `/local/mohhierarchy/assignments.php`. Its Facility
control is searchable and selecting a Facility automatically populates Zone and District. The
canonical assignment still stores and validates Facility, deriving both ancestors on the server.
For delegated managers, the list and its search are always filtered to active assignments inside
their own Zone, District or Facility. A transfer is allowed only when both the user's current
assignment and the destination Facility are inside that jurisdiction, and the requested management
scope is no broader than the acting manager's own scope.
The specialist consistency report remains available directly at
`/local/mohhierarchy/repair.php`; it is intentionally omitted from the main Site administration
menu to keep routine hierarchy administration focused. Dry-run is available and facility conflicts
are never automatically moved.

CSV upload, web services, authentication synchronisation and bespoke provisioning are separate
security pathways and must be reviewed independently.

## Security limitations and operational controls

- Zipatala payloads are trusted only after structural, duplicate-ID and parent-child validation.
- JavaScript and AJAX responses do not authorise writes; server-side services repeat all checks.
- The optional API token is stored in Moodle configuration. Protect database backups and restrict
  configuration access.
- Synchronisation uses the last complete validated payload; it does not provide real-time Zipatala
  changes between runs.
- Deactivated facilities remain visible for historical assignments but cannot receive new users.
- Management scope controls these plugins only. It does not replace Moodle roles, enrolments or
  course-context permissions.
- Lifecycle observers repair existing validated assignments; they are not primary permission
  controls and do not trust profile-only values.
- External identity-provider operations may not share Moodle's database transaction.

## Backup considerations

Course backups do not contain this site-wide hierarchy. Use a full database and dataroot backup.
Preserve all six `local_mohh_*` tables, Moodle's `user_info_field` and `user_info_data` tables,
plugin configuration, both plugin directories and the site secret/configuration controls protecting
the bearer token. Test restoration before relying on a backup.

Export assignment and synchronisation audit data before uninstalling if organisational retention
rules require it.

## Troubleshooting

- **No hierarchy choices:** run a successful sync, confirm the records and ancestors are active,
  verify the actor has `viewhierarchy`, and confirm the actor has an active assignment with a scope
  above `none`.
- **Scheduled sync does not run:** confirm Moodle cron, the plugin enabled setting and the task's
  schedule. Inspect scheduled-task output and the hierarchy sync-history page.
- **HTTP or JSON failure:** verify endpoint URLs, TLS, proxy/firewall policy, bearer token and the
  response wrapper. Failed runs leave the previous local copy unchanged.
- **Manual sync remains at 0% / waiting for cron:** verify that
  `php admin/cli/cron.php` runs every minute, or use the dashboard's **Run now** action as a site
  administrator. A task showing no start time has not contacted Zipatala yet.
- **Manual sync is running but does not advance:** check task logs and endpoint connectivity, then
  check for a live sync lock. Do not delete lock records while a worker may still be active.
- **Assignment conflict:** use the consistency report in dry-run mode. `local_mohh_assign` is
  canonical; review facility conflicts manually rather than editing reference rows.
- **District and Zone do not populate after choosing Facility:** confirm JavaScript is enabled,
  purge caches, rebuild the AMD module, and confirm the web server can serve the plugin's
  `amd/build` files. Server validation remains authoritative.
- **Duplicate profile field:** retain exactly one field with datatype `mohhierarchy`; do not rename
  an unrelated field to the reserved shortname `mohfacility`.

## Testing

From the repository root used by this Moodle distribution:

```bash
# Initialise when plugin versions change.
php public/admin/tool/phpunit/cli/init.php

# Plugin PHPUnit suites.
vendor/bin/phpunit \
  --testsuite local_mohhierarchy_testsuite,profilefield_mohhierarchy_testsuite

# Moodle PHP coding style.
vendor/bin/phpcs --standard=moodle \
  public/local/mohhierarchy public/user/profile/field/mohhierarchy

# PHP syntax.
find public/local/mohhierarchy public/user/profile/field/mohhierarchy \
  -name '*.php' -print0 | xargs -0 -n1 php -l

# Database schema comparison and XML syntax.
php admin/cli/check_database_schema.php
xmllint --noout public/local/mohhierarchy/db/install.xml

# AMD JavaScript.
npx grunt amd --root=public/user/profile/field/mohhierarchy

# Gherkin style and Behat, after configuring $CFG->behat_* and installing Behat.
npx grunt gherkinlint --root=public/local/mohhierarchy
php public/admin/tool/behat/cli/util.php --enable
vendor/bin/behat --config /path/to/behat.yml \
  --tags='@local_mohhierarchy'
```

## Uninstallation

Uninstall `profilefield_mohhierarchy` first. It removes only field definitions of datatype
`mohhierarchy` and their `user_info_data`; it does not delete users, the shared category or
canonical assignments.

Then uninstall `local_mohhierarchy`. Moodle drops its six plugin tables, settings, tasks and
capabilities. Moodle users and unrelated custom fields remain. The assignment and sync audit tables
are lost with explicit local-plugin uninstallation, so export retained records first.
