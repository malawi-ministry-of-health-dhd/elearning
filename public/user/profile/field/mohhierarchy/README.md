# MoH hierarchy profile field

This Moodle 5.2 custom profile field presents the local Ministry of Health hierarchy as dependent
Zone, District and Facility selectors on Moodle's standard advanced user form.

Install `local_mohhierarchy` first at `local/mohhierarchy`, then install this plugin at
`user/profile/field/mohhierarchy` and run `php admin/cli/upgrade.php --non-interactive`.

The field stores the local facility ID. `local_mohh_assign` in the required local plugin is
canonical, and zone and district are derived from the facility. JavaScript filtering is not a
security boundary; every submitted facility is revalidated on the server. The installer creates
exactly one `mohfacility` field and does not change existing Moodle custom fields.

Configuration, role setup, security, operations, testing and uninstallation are documented in the
`local_mohhierarchy` README shipped with the companion package.
