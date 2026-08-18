# MoH hierarchy profile field

This Moodle 5.2 custom profile field presents the local Ministry of Health hierarchy as dependent
Zone, District and Facility selectors on Moodle's standard advanced user form.

Download both plugins from the
[GitHub Releases page](https://github.com/malawi-ministry-of-health-dhd/elearning/releases/latest).
Install `local_mohhierarchy` first, then install this plugin. For Moodle's web installer use
`local_mohhierarchy.zip` followed by `profilefield_mohhierarchy.zip`.

For a file installation, extract `local_mohhierarchy-plugin-files.zip` into
`local/mohhierarchy`, then extract `profilefield_mohhierarchy-plugin-files.zip` into
`user/profile/field/mohhierarchy`. Run:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

The field stores the local facility ID. `local_mohh_assign` in the required local plugin is
canonical, and zone and district are derived from the facility. JavaScript filtering is not a
security boundary; every submitted facility is revalidated on the server. The installer creates
exactly one `mohfacility` field and does not change existing Moodle custom fields.

Configuration, role setup, security, operations, testing and uninstallation are documented in the
`local_mohhierarchy` README shipped with the companion package.
