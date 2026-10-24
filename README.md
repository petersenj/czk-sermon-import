# Sermon MP3 importer

This WordPress utility scans sermon MP3s on the server and creates missing
`wpfc_sermon` entries. It stores a path to the existing file in `sermon_audio`;
it does not upload or create a Media Library attachment.

## File layout

- `import-sermons.php` validates the request, loads WordPress, scans month
  folders, and coordinates the import.
- `includes/config.php` contains the importer settings.
- `includes/importer.php` contains filename parsing, preacher matching,
  duplicate detection, and sermon creation.
- `includes/summary.php` renders the import results and optional diagnostics.

Keep these files together when deploying the importer.

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

Open the importer URL with the secret and the first month to scan:

```text
/wp-content/plugins/jape-sermon-import/import-sermons.php?key=YOUR_SECRET&start-at=2026-09
```

The script scans `uploads/sermons/PredigtDerWoche/YYYY/MM` from `start-at`
through the current month, inclusive. Missing month folders are ignored.

### Dry run

Add `dryrun=1` to preview proposed sermons without creating or changing posts:

```text
...?key=YOUR_SECRET&start-at=2026-09&dryrun=1
```

The preview reports parsed fields, preacher-match status, likely setup/file
issues, skipped files, and duplicate matches. Run a dry run before a live import.

### Debug output

Add `debug=1` to show duplicate-check and WordPress post diagnostics in a
collapsed section:

```text
...?key=YOUR_SECRET&start-at=2026-09&dryrun=1&debug=1
```

The debug report includes full post objects and metadata for matching sermons.
It may reveal private site data; use it only for controlled troubleshooting and
remove `debug=1` when finished.

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
- Imports create draft sermons. Review and publish them from WordPress when
  ready.
- Back up the site database before a live import.
