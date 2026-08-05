# Delegated hierarchy user creation

The strict delegated creation page is:

`/local/mohhierarchy/createuser.php`

It is deliberately separate from Moodle's core administrator page. A hierarchy manager can use it
only when the manager has `local/mohhierarchy:createuser` and an active hierarchy assignment. Every
new account is placed at an active facility inside the manager's current scope and starts with the
management scope `none`.

## Recommended role configuration

Delegated zone, district and facility managers should normally receive:

- `local/mohhierarchy:createuser`
- `local/mohhierarchy:viewhierarchy`, so the dependent selectors and read-only browser are usable

They should **not** receive `moodle/user:create` unless the organisation intentionally wants them to
use Moodle's unrestricted core account-creation page and has reviewed every other pathway enabled
by that capability.

Site administrators may continue to use:

`/user/editadvanced.php?id=-1`

The hierarchy profile field and its dependent selectors continue to appear on that unmodified core
page. Trusted roles that intentionally hold `moodle/user:create` can also continue to use the core
page, subject to the profile field's hierarchy checks. Strict delegated managers should use the
plugin-owned page instead.

## Provisioning boundaries

This page does not replace or disable Moodle's other provisioning mechanisms. CSV upload, web
services, authentication-plugin synchronisation, command-line scripts and any bespoke integrations
must be reviewed separately. Granting the plugin capability does not grant access to those paths.

Only enabled authentication methods are offered. For a locally managed authentication method the
creator supplies a password or asks Moodle to generate and email one. For external authentication,
the page creates the Moodle-side account marker but never calls the external identity provider or
sets an external password. External identity creation is not database-transactional and remains the
responsibility of the organisation's provisioning process.

## Security model

- The form carries Moodle's standard sesskey and every POST is checked again by the controller.
- Submitted local IDs are parameter-cleaned, then re-resolved from the database.
- Zone and district are consistency assertions only; the stored values are derived from facility.
- Permission and active-state checks run again inside the application service after locking.
- Core account insertion and the plugin-owned assignment/history writes share one delegated
  database transaction.
- Username creation and assignment updates are lock-protected against concurrent requests.
- The standard `core\event\user_created` event fires after the account and assignment commit.
- New users always receive scope `none`; no request value can override it.
