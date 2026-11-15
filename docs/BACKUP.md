# Backup and restore

Edict has two layers of backup:

1. **The host's backups.** Roanoke's nightly `pg_dumpall` holds the whole database, and Borg holds `DATA_DIR`, which includes the media originals (`DATA_DIR/private/ab/cd/<sha256>`). Restoring those brings everything back, including comments, likes and reader accounts. That is Ops' job (see "Host changes" in `CLAUDE.md`).
2. **The JSON export**, from the dashboard's Backup panel ("Export everything (JSON)", `GET /admin/export`). The owner can keep it anywhere, and Edict can read it back. It is the way back when the database is lost but the media files survive, and the way to move the content to a fresh install.

## What the export holds

The file is `"format": "edict-export", "version": 2`:

- **articles**: every article and page, including drafts and those in the trash: id, kind, status, address (`slug`) and old addresses that still redirect (`old_slugs`), language, title, teaser, body, tags, cover and its description, comment setting, the dates (`created_at`, `published_at`, `revised_at`, `updated_at`, `deleted_at`), and the unpublished draft.
- **tags**: every tag, by slug and name.
- **settings**: site name, description, menu, footer, theme, accent colour, logo and icon (as media ids), imprint and privacy pages, and the comment rules. There are no secrets in settings; those live in `.env`.
- **media**: one entry per media row: id, SHA-256, kind, extension, size, original file name, width, height, status, upload date, and for a video the chosen poster frame (`poster_seconds`). Descriptions (alt texts) and captions are part of the article bodies. The files themselves are **not** in the export.

Left out on purpose:

- **Comments, likes and reader accounts.** They belong to people who signed in with Google or Twitch, so they are personal data. Only the host's database backup keeps them.
- **Published versions (revisions).** Up to 20 per article would make the file many times larger. A restored article starts with an empty version history.
- **Media variants** (thumbnails, transcodes, posters, site icons). The worker makes them again from the originals.

Version 1 files (exports before this format had a version) hold only articles and tags.

## Restoring

A restore only goes into an install **without articles, pages or media**: after losing the database, or into a new one. It never overwrites or merges, so it can't destroy anything. Everything is checked first, and everything is written in one transaction: a refused restore writes nothing.

It refuses, with a message:

- an install that already has articles, pages or media,
- a file that isn't an Edict export, or has a version this release doesn't know,
- invalid data, such as a body the editor wouldn't save (nested too deeply, a gallery of more than 100 images), a duplicate id or address, an unknown status or a malformed date.

It restores:

- articles and pages with their ids, statuses, dates, addresses, old addresses, language, tags, covers, drafts and trash state,
- media rows, but only those whose original is in `DATA_DIR`. Missing ones are skipped and listed; their ids stay unused, so a later upload never shows up where an article expects the old picture. Covers, logo and icon that point at a missing file are removed. The rows start as "processing", and the worker makes their variants again, a video's chosen poster frame included, and the site icons,
- the settings,
- the search index, which is derived from the bodies.

From a version 1 file, articles, pages and tags come back. Their media references can't be relinked (that file has no media rows), covers are removed, and the summary says how many references are affected.

### After losing the database (Ops)

With the media files back in `DATA_DIR`:

```sh
php bin/edict migrate
php bin/edict content:restore /path/to/edict-export-YYYY-MM-DD.json
php bin/edict admin:create <username>    # before or after; the admin account isn't in the export
```

The worker (cron, every minute) then processes the media; thumbnails appear as it gets to them.

### From the dashboard

On an install without content, the Backup panel offers "Restore from a file": choose the export, then confirm with the admin password and an authenticator code. It shows what came back: articles, pages, tags, media relinked and missing, and whether settings were restored. A file larger than PHP's `upload_max_filesize` has to go through `content:restore` instead.
