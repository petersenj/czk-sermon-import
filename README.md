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
| `key` | Only when not using the header | — | Import secret configured as `SERMON_IMPORT_SECRET` in `wp-config.php`. Prefer the `X-Sermon-Import-Key` HTTP header so the secret is not included in the URL. |
| `start-at` | Yes | — | First month to scan, in `YYYY-MM` format; must be `2020-01` or later. The scan continues through the current month. |
| `dryrun` | No | `1` | Runs as a preview by default. Set to `0`, `false`, `no`, or `off` to create sermons. |
| `debug` | No | `0` | Set to `1`, `true`, or `yes` to show detailed duplicate-check and WordPress post diagnostics. This may expose private site data; only use it for controlled troubleshooting. |
| `publish` | No | `0` | Set to `1`, `true`, `yes`, or `on` to publish newly imported sermons. Otherwise they are drafts. Existing sermons are still skipped and not changed. With `dryrun=1`, this only previews the intended status. |
| `email` | No | `0` | Set to `1`, `true`, `yes`, or `on` to email the HTML summary to the WordPress administrator email configured in Settings > General. Includes imported and errored files, lists only skipped files requiring attention, and counts existing sermons skipped as duplicates; also works with dry runs. WordPress must be configured to send mail. |

Parameters can be combined, for example:

```text
...?key=YOUR_SECRET&start-at=2026-09&dryrun=0&publish=1&email=1
```

The example explicitly disables dry-run mode to run a live import. For safety,
requests without `dryrun=0` preview parsed fields, preacher-match status, likely
setup/file issues, skipped files, and duplicate matches without creating or
changing sermons. The `key` query parameter is retained for manual use, but
URLs can be recorded in web-server and proxy logs; use the
`X-Sermon-Import-Key` header for automated requests.

## Automatic weekly run

To run the importer automatically, activate **Sermon Import Scheduler** in
WordPress under Plugins. Keep `SERMON_IMPORT_SECRET` defined in `wp-config.php`.
The scheduler requests the importer every Monday at 03:00 in the WordPress
timezone using a registered weekly WordPress Cron interval. It sends the import
key in an HTTP header, imports and publishes new sermons, scans the previous and
current month, and emails the summary to the WordPress administrator.

WordPress Cron runs due events when the site receives a request, so a quiet site
may run the import later than 03:00. For a more exact schedule, configure a
server cron to trigger WordPress Cron regularly. Deactivate the scheduler plugin
to stop the weekly run.

Debug cron with wp crontrol plugin.

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
