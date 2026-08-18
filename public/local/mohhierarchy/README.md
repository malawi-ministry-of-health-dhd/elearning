# Ministry of Health hierarchy for Moodle

`local_mohhierarchy` and `profilefield_mohhierarchy` integrate the Ministry of Health organisation
tree with Moodle:

```text
Zone -> District -> Facility -> User
```

Zipatala is the hierarchy source of truth. Moodle keeps a normalised local copy for reliability,
performance, reporting and server-side authorisation. Remote records which disappear are
deactivated rather than deleted.

## What the plugins provide

Together, the two plugins let an organisation:

- synchronise Zones, Districts and Facilities from Zipatala;
- browse the synchronised hierarchy in Moodle;
- place each Moodle user at one Facility, with District and Zone derived automatically;
- let authorised Zone, District or Facility managers see and manage users in their jurisdiction;
- create users through either Moodle's administrator form or a restricted hierarchy-manager form;
- transfer an authorised user to another Facility, including one in another Zone;
- keep assignment and synchronisation audit history.

## Download

Download the latest packages from the project’s
[GitHub Releases page](https://github.com/malawi-ministry-of-health-dhd/elearning/releases/latest).

Every release contains these files:

| Release asset | Use it for |
| --- | --- |
| `local_mohhierarchy.zip` | Installing the local plugin through Moodle's web installer |
| `profilefield_mohhierarchy.zip` | Installing the profile-field plugin through Moodle's web installer |
| `local_mohhierarchy-plugin-files.zip` | Copying the local plugin directly to the server filesystem |
| `profilefield_mohhierarchy-plugin-files.zip` | Copying the profile-field plugin directly to the server filesystem |
| `SHA256SUMS.txt` | Verifying that downloaded ZIP files have not changed |

The source repository is
[malawi-ministry-of-health-dhd/elearning](https://github.com/malawi-ministry-of-health-dhd/elearning).

To verify downloads, place `SHA256SUMS.txt` beside the ZIP files and run one of:

```bash
# Linux.
sha256sum --check SHA256SUMS.txt

# macOS.
shasum -a 256 --check SHA256SUMS.txt
```

Always install the plugins in this order:

1. `local_mohhierarchy`
2. `profilefield_mohhierarchy`

The profile-field plugin depends on the local plugin and cannot operate by itself.

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

More detail is available in [Architecture](docs/architecture.md),
[Delegated user creation](docs/delegated-user-creation.md), and
[Privacy and consistency](docs/privacy-and-consistency.md).

## Installation method 1: Moodle web installer

Use this method when Moodle is allowed to write to its plugin directories.

1. Download `local_mohhierarchy.zip` from GitHub Releases.
2. Sign in to Moodle as a site administrator.
3. Open `Site administration > Plugins > Install plugins`.
4. Upload `local_mohhierarchy.zip`.
5. Confirm that the detected plugin type is **Local plugin** and the plugin directory is
   **mohhierarchy**.
6. Select **Install plugin from the ZIP file**, then complete Moodle's database upgrade pages.
7. Return to `Site administration > Plugins > Install plugins`.
8. Upload `profilefield_mohhierarchy.zip`.
9. Confirm that the detected plugin type is **Profile field type** and complete the installation.
10. Open `Site administration > Development > Purge caches` and select **Purge all caches**.

The two web-installer ZIPs each contain exactly one directory named `mohhierarchy`, as Moodle
requires. Do not place one ZIP inside another ZIP or add an extra parent directory.

If validation reports a write-access error for `local` or `user/profile/field`, do not make the
whole Moodle site world-writable. Ask the server administrator to use the file-installation method
below and apply ownership and permissions appropriate for that server.

## Installation method 2: server files or SSH

Use the `*-plugin-files.zip` assets for this method. Their plugin files are directly at the ZIP
root, ready to extract into a directory that you create.

For a traditional Moodle installation, the final directories are:

```text
<moodle-root>/local/mohhierarchy
<moodle-root>/user/profile/field/mohhierarchy
```

For Moodle's newer public-directory layout, they are commonly:

```text
<repository>/public/local/mohhierarchy
<repository>/public/user/profile/field/mohhierarchy
```

Example for a public-directory installation at `/var/www/html`:

```bash
cd /var/www/html

mkdir -p public/local/mohhierarchy
unzip /path/to/local_mohhierarchy-plugin-files.zip \
  -d public/local/mohhierarchy

mkdir -p public/user/profile/field/mohhierarchy
unzip /path/to/profilefield_mohhierarchy-plugin-files.zip \
  -d public/user/profile/field/mohhierarchy

php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Run the commands as the account that normally maintains Moodle and can write to Moodle's dataroot.
On a typical Debian or Ubuntu server this may be the web-service account:

```bash
sudo -u www-data php admin/cli/upgrade.php --non-interactive
sudo -u www-data php admin/cli/purge_caches.php
```

Do not run those exact `sudo` commands blindly: the correct account and paths depend on the server.
After extraction, confirm that the web server can read the files and that `config.php` and dataroot
retain their existing secure permissions.

## Installation method 3: existing Git deployment

If both plugin directories are already tracked in the deployed repository, pulling the branch is
enough to copy the new code. A pull does not run Moodle's upgrade automatically:

```bash
cd /var/www/html
git pull --ff-only
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Use the appropriate deployment account, review the incoming commit, and back up production before
upgrading.

## Upgrades

Back up the database, dataroot and both plugin directories. Replace the plugin files without
removing configuration, install the local plugin before its profile-field dependency, and run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Do not copy a new version over a partially deleted directory. Review release notes and test the
upgrade on a copy of production first.

## First-time setup checklist

After both plugins are installed:

1. Configure the Zipatala connection under
   `Site administration > Plugins > Local plugins > MoH hierarchy`.
2. Decide whether synchronisation should be scheduled or manual.
3. Run the first synchronisation and confirm that Zones, Districts and Facilities were imported.
4. Open the **Hierarchy browser** and check the imported names and Facility codes.
5. Assign each hierarchy manager to a Facility and choose their management scope.
6. Give hierarchy managers an appropriate Moodle system role containing the required plugin
   capabilities.
7. Create or assign ordinary users. Their scope should normally remain **None**.

Hierarchy placement and Moodle role permissions are separate. A manager needs both an active
hierarchy assignment with a suitable scope and the required Moodle capabilities.

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

For a manual-only installation, clear **Enable scheduled synchronisation** and save the settings.
Manual synchronisation and the CLI command remain available when this setting is disabled.

## How to use the plugins

### 1. Import the hierarchy

Open:

`Site administration > Plugins > Local plugins > Hierarchy synchronisation`

Select **Synchronise now**. Moodle queues the work as a background task and shows its progress.
Normal Moodle cron will start the task. A site administrator can use **Run now** when that action is
shown, or use the CLI command documented below.

After a successful first synchronisation, the page shows current counts and synchronisation
history. Failed synchronisations do not deactivate or replace the last valid local hierarchy.

### 2. Check the imported hierarchy

Open:

`Site administration > Plugins > Local plugins > Hierarchy browser`

Search by Zone, District, Facility name or Facility code. The browser is read-only because
Zipatala owns these records. Correct hierarchy data in Zipatala and synchronise again; do not edit
the local hierarchy tables manually.

### 3. Assign a hierarchy manager

Open:

`Site administration > Plugins > Local plugins > User hierarchy assignments`

Find the user, open their action menu, and choose **Transfer user** or the available hierarchy
assignment action. Select a Facility; Moodle fills its District and Zone automatically. Then choose
the manager's scope:

- **None:** the user has a placement but cannot manage hierarchy users;
- **Facility:** the user may manage permitted users at that Facility;
- **District:** the user may manage permitted users in that District;
- **Zone:** the user may manage permitted users in that Zone.

Next, assign the user an appropriate Moodle role at the **System** context. A typical delegated
manager role needs:

- `local/mohhierarchy:viewhierarchy`;
- `local/mohhierarchy:viewassignments`;
- `local/mohhierarchy:manageassignments`, when transfers are allowed;
- `local/mohhierarchy:createuser`, when account creation is allowed.

Do not normally grant delegated hierarchy managers `moodle/user:create`, `moodle/user:update` or
`moodle/user:delete`. Those capabilities expose broader Moodle account-management routes that are
not limited by this plugin's hierarchy page.

### 4. Create users

Site administrators can use Moodle's standard **Add a new user** page. Its **Organisation
hierarchy** section requires a Facility and automatically derives District and Zone.

Delegated hierarchy managers use:

`Site administration > Plugins > Local plugins > Create hierarchy user`

They can select only an active Facility allowed by their own assignment and scope. Every new user
starts with scope **None**, preventing the creator from granting management authority during
account creation.

### 5. Find and transfer users

The **User hierarchy assignments** page initially shows users in the current manager's
jurisdiction. Filters can search names, email addresses, usernames, assignment status, Zones,
Districts and Facilities. The action menu shows **Transfer user** only when the current manager is
authorised to manage that user.

Selecting a new Facility derives the destination District and Zone. A permitted user may be moved
to another Zone, but a transfer outside the actor's own jurisdiction must leave the transferred
user with scope **None**. A manager in the receiving jurisdiction or a site administrator can grant
an appropriate scope later.

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
# Traditional Moodle directory layout.
php local/mohhierarchy/cli/sync.php
php local/mohhierarchy/cli/sync.php --force
php local/mohhierarchy/cli/sync.php --help

# Public-directory layout, when run from the repository root.
php public/local/mohhierarchy/cli/sync.php
php public/local/mohhierarchy/cli/sync.php --force
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
navigation entry opens the plugin's hierarchy-aware user list for delegated managers and site
administrators. Moodle's complete core report remains available by opening `/admin/user.php`
directly.

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

When an authorised administrator edits an existing account, the same Organisation hierarchy
section shows Zone, District and Facility and includes a **Transfer or assign hierarchy** link to
the audited assignment workflow. The user navigation exposes the same action for manageable
targets.

Strict delegated managers use:

`/local/mohhierarchy/createuser.php`

This page requires `local/mohhierarchy:createuser`, uses the same searchable Facility control,
restricts facilities to the creator's active scope, uses Moodle's user API, and gives every new
user scope `none`.

Assignments are administered separately at `/local/mohhierarchy/assignments.php`. Its Facility
control is searchable and selecting a Facility automatically populates Zone and District. The
canonical assignment still stores and validates Facility, deriving both ancestors on the server.
For delegated managers, the default list shows active assignments inside their own Zone, District
or Facility. The Zone, District and Facility filters contain the full active hierarchy; explicitly
applying one of those filters searches matching assignments outside the default jurisdiction too.
Seeing an outside user does not grant authority over that user: Transfer actions remain available
only for users the actor may manage. A manager may transfer one of those authorised users to any
active Facility, including a Facility in another Zone. When the destination is outside the acting
manager's jurisdiction, the transferred user must receive scope `none`; a manager in the receiving
jurisdiction or a site administrator can grant management scope afterwards. Transfers within the
actor's jurisdiction may use any scope no broader than the actor's own scope.
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
