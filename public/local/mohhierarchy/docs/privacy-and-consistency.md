# Privacy, lifecycle and consistency policy

## Data ownership and retention

`local_mohh_assign` is the canonical operational assignment. The companion custom profile field is
only a Moodle-profile mirror. Zones, districts and facilities are shared reference data owned by
Zipatala synchronisation and are never deleted for an individual privacy request.

An account deletion deactivates its current assignment and appends a deactivation history entry.
The assignment row and `local_mohh_assignlog` history are retained for Ministry of Health
accountability and access-control audit. An approved Privacy API erasure request applies the same
deactivation and removes the erased person from actor fields (`assignedby`, `changedby`, and
`triggeredby`), including actor identifiers embedded in retained JSON snapshots. The subject
`userid` in the assignment and its history is retained under this audit policy. Sites must align
their configured retention periods and legal basis with their organisational records schedule.

The `profilefield_mohhierarchy` Privacy API provider exports and deletes only its own
`user_info_data` rows. It does not delete the canonical assignment or shared hierarchy references.

## Lifecycle observers

`user_deleted` withdraws an active assignment but preserves history. `user_created` and
`user_updated` invoke the same consistency service used by the administrator report. These
observers only reconcile an existing canonical assignment; they do not trust profile-only data,
grant permission, validate account creation, recreate users, or resolve facility conflicts.

## Consistency repair

The report is available to users with `local/mohhierarchy:repairconsistency`. Every write requires a
valid session key and is re-scanned immediately before application.

Automatic repair is limited to:

- creating a canonical assignment with scope `none` and source `repair` for an older user who has
  only one valid, active custom-field facility;
- restoring an empty custom-field mirror from the canonical assignment;
- re-deriving zone and district from the assignment's existing canonical facility.

A different facility in the two stores, missing or inactive reference data, a withdrawn assignment
with a profile value, invalid profile data, or multiple field instances is shown for review. Repair
never silently moves a person from one facility to another. Every applied repair is recorded in
`local_mohh_assignlog`.
