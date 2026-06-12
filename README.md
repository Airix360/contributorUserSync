# Contributor User Sync

**Contributor User Sync links OJS submission contributors to user accounts and automatically reuses verified ORCID iDs from existing user profiles, reducing repeated ORCID prompts and improving author metadata quality.**

## The problem it solves

In OJS, a contributor/author listed on a submission is not necessarily connected to a
real OJS user account — the `authors` table has no `userId` column, so contributor
metadata and user accounts live independently. As a result:

- The same person is re-entered as a contributor on every submission, with no link
  back to their account.
- An author who has already connected and **verified** their ORCID iD in their user
  profile is still asked to connect ORCID *again*, per submission. This confuses
  authors and produces inconsistent, often missing, ORCID metadata.

Contributor User Sync closes that gap: it matches contributors to existing users by
email, records the link, and copies the user's already-verified ORCID iD into the
submission's contributor metadata so the author is never asked to reconnect.

## Key features

1. **Contributor-to-user matching** — when a contributor is added or updated, the
   plugin looks up an OJS user with the same email and links them.
2. **ORCID auto-sync** — if the matched user has a *verified* ORCID iD, it is copied
   into the contributor's metadata (including the OAuth verification fields, so the
   contributor reads as verified). The author is not asked to reconnect.
3. **Sync modes** — do nothing / link existing users only / invite missing
   contributors / automatically create missing accounts.
4. **Contributor role filtering** — choose which contributor (author) roles are
   eligible for syncing.
5. **Existing-user options** — optionally fill empty contributor names from the user
   profile; optionally (off by default) push contributor names back to the profile.
6. **New-user onboarding** — created/invited accounts get the **Author role only**;
   no generated passwords are emailed.
7. **Bulk sync** — scan previous submissions, preview as a dry run, then apply, with a
   downloadable CSV report.
8. **Editor-facing outcomes** — every contributor gets a clear status: matched, ORCID
   synced, already had ORCID (skipped overwrite), no matching user, matched but no
   verified ORCID, or skipped (missing email).

## ORCID auto-sync behaviour

- Only **verified** ORCID iDs (those carrying `orcidIsVerified` from a completed ORCID
  OAuth flow) are synced by default.
- A manually typed, unverified profile ORCID is **never** treated as verified. It is
  only synced if the admin explicitly enables *"sync manually entered ORCID"*, and even
  then it is stored on the contributor as unverified.
- An ORCID iD the contributor already has is **never overwritten** unless the admin
  explicitly enables overwrite.
- If the matched user has no verified ORCID, the plugin can do nothing, warn the editor
  in the report, or flag the contributor for an ORCID connection request — your choice.

## Safe defaults

Out of the box the plugin is conservative:

| Setting | Default |
| --- | --- |
| Plugin enabled | Off (enable per journal) |
| Sync mode | **Link existing users only** |
| ORCID auto-sync | On (verified only) |
| Overwrite existing contributor ORCID | Off |
| Sync manually entered ORCID | Off |
| Auto-create users | Off |
| Push contributor name to user profile | Off |
| Created-user role | Author only |

It never creates Editor or Reviewer accounts, never emails generated passwords, and
never writes passwords or tokens to the report/logs. It operates strictly within the
journal (context) it is configured for, so it is safe on multi-journal installations.

## Bulk sync

From the plugin settings page, **Bulk sync** scans the contributors of previous
submissions in the current journal:

- **Preview** runs a complete dry run and changes nothing.
- **Run** applies the configured rules.

Both produce a report with: total contributors scanned, existing users matched, new
users created, invitations sent, ORCID iDs synced, contributors skipped (no email / no
matching user / no verified ORCID), and errors. The report is downloadable as CSV.

## Installation

1. Copy this directory to `plugins/generic/contributorUserSync` in your OJS
   installation (or install the packaged `.tar.gz` via **Settings → Website → Plugins →
   Upload a New Plugin**).
2. Run the data upgrade if prompted (`php tools/upgrade.php upgrade`), or simply enable
   the plugin — it stores its data in standard plugin/author settings and needs no schema
   changes.
3. Enable **Contributor User Sync** under **Settings → Website → Plugins → Generic
   Plugins**.

## Configuration

Open the plugin's **Settings** for sections covering: General, Sync behaviour, ORCID
auto-sync, Contributor roles, Existing users, New users, and Bulk sync. Start by
enabling the plugin with the default *link existing users only* + *verified ORCID
sync*, run a **Bulk sync preview** to see what would change, then apply.

## How the link is stored

Because core OJS authors have no `userId` field, the contributor→user link is stored as
a plugin-owned author setting (`contributorUserSyncUserId`, registered on the author
schema via the `Schema::get::author` hook) in the `author_settings` table, alongside a
last-status stamp used for editor feedback. Synced ORCID values are
written to the contributor's standard ORCID fields so they display and export normally.

## Limitations

- The contributor edit modal in OJS is a Vue component; rich inline status badges are
  not injected there yet. Per-contributor outcomes are surfaced through the bulk-sync
  report and stored as an author setting. (Planned.)
- **Invite mode** uses OJS 3.5's invitation framework: the contributor receives an
  email with an acceptance link and creates their own account (Author role only).
  On OJS 3.4, which lacks that framework, invite mode falls back to creating a
  disabled "awaiting setup" Author account. Automatic re-invitations are suppressed
  once a contributor's status is "invitation sent"; the manual Invite button re-sends.
- Matching is by **email only**. Name-only or ORCID-only matching is intentionally not
  attempted, to avoid false positives.

## Compatibility

- **Built and tested against OJS 3.5** (namespaced plugin API, `Hook::add`,
  `Repo::author()` / `Repo::user()`, the `HasOrcid` trait).
- The author/user ORCID model and hooks are shared with **OJS 3.4**, so the core
  matching and ORCID-sync paths are expected to work there with minor adjustment.
- **OJS 3.3** uses the older array-based plugin/hook API and a different ORCID storage
  shape; it is **not** supported by this build. A 3.3 backport would need a separate
  compatibility branch, and that is called out rather than shipped untested.

## License

GNU GPL v3. See [LICENSE](LICENSE).
