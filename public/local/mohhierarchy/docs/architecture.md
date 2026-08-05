# MoH hierarchy architecture

## Ownership

```text
Zipatala
   |
   v
Zone -> District -> Facility -> User
```

Zipatala owns zone, district and facility reference data. `local_mohhierarchy` stores a local copy
for resilient forms, reporting and permission checks. Missing remote rows are deactivated, never
hard-deleted by synchronisation.

`local_mohh_assign` is the canonical user placement and management scope. The
`profilefield_mohhierarchy` value in `user_info_data` mirrors the local facility ID. Zone and
district are derived from that facility; browser-submitted ancestors never determine storage.

## Synchronisation boundary

The Zipatala client fetches all three datasets on the server. Mapping rejects malformed wrappers,
invalid JSON, repeated external IDs and invalid parent relationships. Only a fully validated
payload enters one delegated database transaction. A site-wide lock serialises synchronisation,
while per-user locks serialise assignment writes.

No browser request contacts Zipatala. AJAX external functions query only the local tables and filter
results through the same permission service used during form validation.

## User workflows

Moodle's unmodified `/user/editadvanced.php?id=-1` discovers the custom profile field through core's
profile API. Site administrators and deliberately trusted core creators use this page. Delegated
hierarchy managers use `/local/mohhierarchy/createuser.php`, which does not require
`moodle/user:create` and always creates scope-none users.

JavaScript implements dependent selectors for usability only. Every POST is protected by sesskey,
and service-layer validation re-resolves the facility, active ancestry, target and actor scope.

## Consistency and lifecycle

Safe consistency repair can:

- migrate a valid profile-only legacy placement to a scope-none repair assignment;
- restore an empty profile mirror from the canonical assignment;
- re-derive assignment ancestry from its existing canonical facility.

Facility conflicts are report-only and never moved automatically. User deletion withdraws the
operational assignment while retaining documented audit history. Creation and update observers may
repair an existing canonical assignment, but do not trust profile-only values or grant permission.

See `privacy-and-consistency.md` for export, erasure, anonymisation and retention policy.
