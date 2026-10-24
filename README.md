# Sermon MP3 importer

This WordPress utility scans sermon MP3s on the server and creates missing
`wpfc_sermon` entries. It stores a path to the existing file in `sermon_audio`;
it does not upload or create a Media Library attachment.

## Setup

1. Place the importer folder inside the WordPress installation so the entry
   script can find `wp-load.php`.
2. Define a long, unique secret in `wp-config.php`:

   ```php
   define('SERMON_IMPORT_SECRET', 'replace-with-a-long-random-secret');
   ```

3. Review `includes/config.php`. Defaults are:
   - Sermon upload folder: `sermons/PredigtDerWoche`
   - Post type: `wpfc_sermon`
   - Status: `draft`
   - Author user ID: `10`
   - Post time: `10:30:00`
   - Minimum `start-at` month: `2020-01`
   - Service type term ID: `594`

The configured author ID must exist on the WordPress site. The sermon post type,
preacher taxonomy, service-type taxonomy, and service term must also be available.

## Run

Open the importer URL with the required parameters:

```text
/wp-content/plugins/jape-sermon-import/import-sermons.php?key=YOUR_SECRET&start-at=2026-09
```

The script scans `uploads/sermons/PredigtDerWoche/YYYY/MM` from `start-at`
through the current month, inclusive. Missing month folders are ignored.

### URL parameters

| Parameter | Required | Default | Description |
| --- | --- | --- | --- |
| `key` | Yes | — | Import secret configured as `SERMON_IMPORT_SECRET` in `wp-config.php`. |
| `start-at` | Yes | — | First month to scan, in `YYYY-MM` format; must be `2020-01` or later. The scan continues through the current month. |
| `dryrun` | No | `0` | Set to `1`, `true`, or `yes` to preview without creating or changing sermons. |
| `debug` | No | `0` | Set to `1`, `true`, or `yes` to show detailed duplicate-check and WordPress post diagnostics. This may expose private site data; only use it for controlled troubleshooting. |
| `publish` | No | `0` | Set to `1`, `true`, `yes`, or `on` to publish newly imported sermons. Otherwise they are drafts. Existing sermons are still skipped and not changed. With `dryrun=1`, this only previews the intended status. |
| `email` | No | `0` | Set to `1`, `true`, `yes`, or `on` to email the HTML summary to the WordPress administrator email configured in Settings > General. Includes imported and errored files, lists only skipped files requiring attention, and counts existing sermons skipped as duplicates; also works with dry runs. WordPress must be configured to send mail. |

Parameters can be combined, for example:

```text
...?key=YOUR_SECRET&start-at=2026-09&publish=1&email=1
```

Run with `dryrun=1` before a live import to preview parsed fields,
preacher-match status, likely setup/file issues, skipped files, and duplicate
matches.

## Filename format

The expected pattern is:

```text
YYYY-MM-DD_PREACHER_SERMON-TITLE.mp3
```

For example:

```text
2026-09-20_Michael-Knorr_Wurzeln-der-Bitterkeit-verhindern.mp3
```

The date must be a valid calendar date. Underscores and hyphens are accepted as
field separators. The importer checks existing preacher taxonomy names and uses
the longest unambiguous preacher-name match to identify where the title begins.
Punctuation and separators in names are normalized; spaces within names or
titles are supported. If the preacher cannot be found or multiple terms match,
the sermon can still be created, but no preacher term is assigned and the issue
is highlighted in the results.

## Duplicate handling

Existing `wpfc_sermon` posts in the requested sermon-date range are checked
before importing. The importer compares normalized audio paths from
`sermon_audio`, falling back to `enclosure` where needed. A matching sermon is
listed as skipped rather than imported again.

## Security and operation

- The `key` parameter is required for every run.
- Use HTTPS and keep the secret private; URLs can be retained in browser,
  server, and proxy logs.
- Debug output contains post metadata and should not be left enabled for routine
  use.
- Imports create draft sermons unless `publish=1` is supplied.
- Back up the site database before a live import.
