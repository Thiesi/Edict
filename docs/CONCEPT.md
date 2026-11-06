# Edict: Concept v1

*Status: draft for review, 2026-11-04. Comment on the PR. Decisions already made are marked ✅, open points ❓.*

## 1. What Edict is

Edict is a single-author publishing system for a website on Roanoke. **One sovereign writes; everyone else reads, votes and comments, at the sovereign's pleasure.**

Guiding principles:

- **Small and owned.** It is not a TYPO3/WordPress clone. There are no plugins, no multi-tenant roles and no page-tree builder. Each feature exists because you need it.
- **Writing first.** The editor is the centre of the admin side. Everything else (media, moderation) should be reachable without leaving it.
- **Total control.** Every piece of reader input (comments, votes) can be removed, edited or overridden by the admin, in one click, from where it appears.
- **Boring operations.** It is a PHP app in its own php-fpm pool, data lives in PostgreSQL, and media are plain files that Apache serves directly. Existing backups cover all of it. It must not be able to fill a disk or pile up millions of files (see `CLAUDE.md`).

## 2. Decisions so far

| | Decision |
|---|---|
| ✅ Stack | PHP 8.3 (php-fpm) + PostgreSQL 16 on Roanoke |
| ✅ Media tools | ffmpeg, libvips, exiftool, poppler, installed via pkgin with your approval and a dry run first |
| ✅ Deploy | CGP model: CI builds a release, it lands in `releases/<timestamp-sha>`, the `current` symlink is flipped, a healthcheck runs, and rollback is instant. Carried out via Ops: Roanoke (§8) |
| ✅ Domain | `Edict.NetWorkXXIII.de` for now, staging proposed as `Staging.Edict.NetWorkXXIII.de`. DNS and TLS are set up by Ops: Roanoke |
| ✅ URLs | `/slug` for articles and static pages in one namespace |
| ✅ Language | English interface. Each article is marked `de` or `en` |
| ✅ Comments | posted immediately, fully nested threads |
| ✅ Reader login | Twitch and Google OAuth. Anonymous visitors can read only; commenting and voting need a login |
| ✅ Admin login | allow-listed Twitch/Google accounts, or passkey, or password + TOTP (the fallback that works when a provider is down) |
| ✅ Feed | Atom feed with full text, at launch |
| ✅ Uploads | any file type is accepted; unknown types are always served as downloads |
| ✅ Local dev | the dev PC uses a dev DB on Roanoke through an SSH tunnel |
| ✅ Import | none, fresh start |
| ✅ Design | an independent look, worked out together in a dedicated track (§7) |

## 3. Architecture

```
Browser ──HTTPS──▶ Apache (macro vhost, TLS via acme.sh)
                     ├── /media/*  → served directly from disk (immutable, long cache, Range requests)
                     ├── /assets/* → static build output (hashed filenames)
                     └── everything else → php-fpm pool "edict" (unix socket)
                                              │
                                              ▼
                                         PostgreSQL "edict"
                                              ▲
            rc.d service "edict_worker" ──────┘  (media job queue: ffmpeg / libvips / exiftool)
```

- **Backend:** a small PSR-15 app. Recommended components:
  - Slim 4 for routing and middleware
  - Twig for templates, with auto-escaping
  - PHP-DI for dependency injection
  - a migration tool (Phinx or Doctrine Migrations)
  - HTML Purifier, as the final sanitising gate on editor output
- **No full framework.** The domain is small, and a full framework would cost more in upgrades than it saves.
- **Frontend (public site):** server-rendered HTML with minimal progressive-enhancement JS for votes, comments, lightbox and players. Pages work without JS, and reading never requires it.
- **Frontend (admin):** the editor bundle is built with Vite in CI. `node_modules` exists only on the dev PC and in CI, never on Roanoke.
- **Job queue:** a PostgreSQL table read with `SELECT … FOR UPDATE SKIP LOCKED`, so no Redis or extra daemon is needed. The worker is a PHP CLI loop under rc.d, as its own user. A single worker, since ffmpeg is the bottleneck and 8 vCPUs are plenty.
- **Sessions:** stored in PostgreSQL, never as one file per session.
- **Filesystem layout on Roanoke:**
  - `/usr/local/share/edict-{production,staging}/{releases,current}` holds the code.
  - `/home/edict/data/{media,upload-tmp,job-tmp}` holds the data, which Borg already backs up nightly. Derived variants could later be excluded from backups, since they can be regenerated.
  - Later option: a dedicated LVM volume for media, so that a full media disk can never affect `/home`.
- **Staging + production** as two vhosts and two DBs from day one, so every slice can be shown on staging before going live.

## 4. Feature set

### 4.1 Required (MVP / launch)

**Authentication & admin**
- **Readers log in with Twitch or Google** (OAuth 2.0 / OpenID Connect, authorisation-code flow with `state`, `nonce` and PKCE; the attempt's state lives in an encrypted, short-lived cookie, so anonymous requests write nothing to the DB). There are no local reader passwords. The first login creates the account.
- **Minimal data:** provider, the provider's stable user ID, and a display name, with the smallest scopes that provide them. No email, no friend or channel data. No avatars at launch, because hotlinking them would leak readers' IP addresses to Google and Twitch; initials instead.
- **Accounts are never merged by email or name.** A reader can link a second provider explicitly while signed in. Identities are keyed on the provider's stable ID, never on a changeable name.
- **Self-service for readers:** edit display name, sign out everywhere, delete account (comments stay as "[deleted user]" or are removed, votes are removed). Required for GDPR.
- **Admin role.** The admin account is created via a CLI command on the host, and another allow-lists your own Twitch and Google IDs (`bin/edict admin:allow`). There is no way to become admin from the web.
- **Admin login:** an allow-listed Twitch/Google account, a passkey, or password + TOTP. Adding a passkey needs the password and code as well as a session. Passkeys are bound to the site's host name, so they must be re-added after a domain change. The last one is the fallback that keeps you in when a provider is down, and CLI reset on the host is the final recovery. Whoever controls your Google or Twitch account controls the site, so both need 2FA.
- Login endpoints are rate-limited. Sessions are stored in the DB with a secure, HttpOnly, `SameSite=Lax` cookie (`Strict` would drop the cookie on the redirect back from the provider), and every state-changing action is CSRF-protected.
- When logged in, the public site shows inline admin controls: edit, delete comment, adjust votes, unpublish.

**Articles**
- Fields: title, slug (auto-generated, editable), language (`de`/`en`, defaulting to the last one used), teaser, cover image, body, tags, status (draft / published / unpublished).
- Each article's language sets the page's `lang` attribute, hyphenation, quote style and date format inside the article. The interface around it stays English. Overview pages show a small DE/EN marker.
- **Autosave** every few seconds to a separate draft, so half-finished edits of a published article stay private until you press Update. A warning appears before closing the page with unsaved changes. Text that can't reach the server is kept in the browser and offered again. Losing text is unacceptable.
- Previews render a draft exactly as the public will see it.
- Static pages (About, Imprint/Impressum, Privacy) use the same editor but are not listed as articles.
- Hard delete with confirmation, and a 30-day trash before that.

**Full-screen WYSIWYG editor**
- Built on **TipTap/ProseMirror**. Its output is structured JSON, not soup HTML. We store the JSON as the source of truth and render HTML on the server through an allow-list. Malformed or malicious markup cannot get in, and the editor can be swapped later.
- Distraction-free full-screen mode. A toolbar appears on text selection, plus `/` commands to insert blocks.
- Blocks: headings, paragraphs, bold/italic/strike/code, links, quotes, lists, horizontal rule, code blocks with syntax highlighting, tables, image, gallery, audio, video, file attachment, embed (nice-to-have).
- Markdown shortcuts (`##`, `**`, `>`, `-`) work while typing.
- **Attachments:**
  - Drag and drop or paste files anywhere in the editor. Each file uploads in the background with a progress bar and appears as the matching block.
  - A media library side panel lets you reuse files that are already uploaded.

**Media handling** (the "let's discuss" part, see §5 for detail)
- Images are responsive and sized to the article column, and open full-size in a lightbox on click. Several images can form a gallery with swipe.
- Audio plays inline in a styled player with a waveform.
- Video plays inline with a poster frame and native controls, transcoded to a format every browser plays.
- PDFs and all other files get a download card showing file type, name and size. PDFs also get a first-page preview.
- On upload, GPS and other privacy-sensitive metadata are stripped from every file.

**Reader interaction**
- **Votes:**
  - A single "like" per article, per logged-in account. Anonymous visitors see the count and a "log in to like" prompt.
  - The public count is `real likes + royal adjustment`. You set the adjustment to anything, any time ("+100" button), and the admin view shows both numbers.
- **Comments:**
  - Commenting needs a login. The display name comes from the account. Comments are plain text with auto-linked URLs. No HTML, no images.
  - **Fully nested threads.** Any comment can be answered. Indentation is capped visually (about 5 levels on desktop, 3 on mobile); deeper replies continue at the cap, with an "↳ in reply to *name*" marker. Each subtree can be collapsed.
  - Moderation has two modes, switchable per site and per article. The default is ✅ *post immediately* (you remove what you don't like); the other is *hold for approval*. Word-filter hits are always held, and banned accounts can't post or vote.
  - Deleting a comment that has replies offers two choices: *remove only this one* (it shows as "[removed]" and the replies stay), or *remove the whole subtree*.
- **Moderation powers (one click each, inline on the article page and in an admin queue):**
  - delete
  - edit
  - pin
  - mark as "approved author" (their future comments skip the queue)
  - ban (by account, with optional expiry)
  - close comments on an article
  - auto-close comments after N days
  - word filter (hold or reject comments containing listed words)
- **Spam protection:** the login requirement does most of the work, plus per-account rate limits and the word filter. No reCAPTCHA. Google and Twitch are the only third parties involved, and only when someone chooses to log in.

**Public site**
- Home page (latest articles), article page, archive with pagination, tag pages, static pages, and a styled 404/500.
- ✅ Clean URLs: `/my-article-slug`. Articles and static pages share one namespace. System paths (`/login`, `/auth/…`, `/account`, `/healthz`, `/tag/…`, `/archive`, `/feed.atom`, `/media/…`, `/assets/…`, the admin path, `/sitemap.xml`, …) are reserved and can't be used as slugs. Renamed slugs redirect permanently (301).
- ✅ **Atom feed with full text** (`/feed.atom`, plus a per-tag feed). Each entry carries its article's language. Images are included; audio and video appear as links or enclosures.
- Legal page templates for Impressum and Datenschutz (in German, since the operator is German).
- SEO basics: `<title>`/meta description, OpenGraph and Twitter cards (cover image), canonical URLs, `sitemap.xml`, `robots.txt`.
- Accessible markup: semantic HTML, alt texts (the editor asks for them), keyboard-operable players and lightbox.

**Security & operations**
- Strict Content-Security-Policy with no inline scripts, plus the server-wide TLS headers already set on Roanoke.
- Health endpoint for the deploy script, app logs rotated by newsyslog, and a nightly sweep of temp and orphan files.
- Disk guardrails:
  - content-addressed media in a fan-out tree
  - a per-file size limit
  - hard caps on total media bytes and file count
  - a cron alert on `df -i` and disk usage
- Backups: the PostgreSQL dump and `/home/edict` are covered by the existing Borg job. **A restore is tested once before launch.**

### 4.2 Nice-to-have (after launch, roughly by value/effort)

| Feature | Notes |
|---|---|
| Scheduled publishing | "Publish at" timestamp, picked up by the worker |
| Full-text search | PostgreSQL full-text search, stemming each article in its own language (de/en), no extra service |
| Revision history | Diff and restore of earlier versions. Autosave already gives us the data |
| Email notifications | A new comment awaiting moderation, sent via local Postfix |
| Secret preview links | Share a draft with someone before publishing |
| Embeds | YouTube/Vimeo/Mastodon, click-to-load so nothing loads from third parties until the reader clicks |
| Series / collections | Group articles into an ordered series with prev/next navigation |
| Privacy-friendly stats | View counts per article, no cookies, no third party |
| Reactions | Emoji reactions beyond "like", each with an adjustable royal offset |
| Reader subscriptions | Email when a new article appears (double opt-in) |
| Fediverse | Publish articles as ActivityPub posts, or have Akkoma (already on Roanoke) announce them |
| Adaptive video (HLS) | Only worthwhile if videos get long or large |
| Dark mode | Ties into the design work |

### 4.3 Explicitly out of scope

Multiple authors and roles, a plugin system, a page-tree builder, translated versions of the same article (each article is in exactly one language), content import, e-commerce, local reader accounts with passwords, other login providers (for now).

## 5. Media handling in detail

All uploads use **chunked upload**: 8 MB chunks straight into `/home/edict/data/upload-tmp`. They never pass through the 4 GB RAM-backed `/tmp`, large videos survive flaky connections, and Apache's `LimitRequestBody` stays small.

After upload, a file is hashed (SHA-256) and stored once at `media/ab/cd/<sha256>.<ext>`, so the same file uploaded twice is stored once. The DB records the original. Derived variants are jobs in the queue, so the editor shows the block immediately with a "processing…" state.

| Type | Accepted | What Edict does | What the reader sees |
|---|---|---|---|
| **Image** | JPEG, PNG, WebP, AVIF, GIF, HEIC* | Rotates according to EXIF orientation, strips metadata, generates widths 480/960/1600/2400 as AVIF + WebP + JPEG fallback (libvips). Animated GIFs are converted to a muted looping MP4 | `<picture>` with `srcset`, sized to the column, lazy-loaded. Click opens a lightbox at full resolution with zoom, plus swipe through all images in the article. Optional caption |
| **Gallery** | multiple images | as above | Grid or masonry, opening into the lightbox |
| **Audio** | MP3, M4A/AAC, FLAC, WAV, OGG/Opus | Transcodes to AAC 192 kbit/s M4A (plays everywhere) and computes waveform peaks (ffmpeg). Keeps the original | Styled player with waveform, scrubbing, speed control and duration; native `<audio>` underneath. Optional "download original" |
| **Video** | MP4, MOV, MKV, WebM, AVI | Transcodes to H.264 + AAC MP4, max 1080p, `faststart`. Extracts a poster frame at about 10%, which can be changed in the editor | Inline player with poster, native controls, no autoplay. Apache serves Range requests directly, so seeking is instant |
| **PDF** | PDF | ✅ First-page preview image (poppler, via libvips) | Card with preview, title and size; click opens it in the browser's PDF viewer |
| **Other** | ✅ anything | Stored as-is with the original filename recorded. On disk it lives only under its hash, so the uploaded name never becomes a path. Served with `Content-Disposition: attachment`, as `application/octet-stream` unless the type is known and harmless, plus `nosniff`. Apache never runs scripts from the media directory | Download card with an icon for the type, name and size |

\*HEIC only if pkgsrc's libvips includes libheif. To be checked during the install dry run.

Limits (defaults, all configurable):

- 50 MB per image
- 500 MB per audio file
- 4 GB per video
- 50 GB total media
- 100,000 stored files

Every limit fails loudly, before anything is written.

## 6. Data model (first cut)

`app_user` (display_name, is_admin, banned_until, deleted_at) · `user_identity` (user_id, provider, provider_user_id, unique on provider + ID) · `admin_credential` (password hash, TOTP secret, last TOTP step) · `passkey` · `session` (token hash, user_id, csrf_token, expires_at) · `article` (kind, status, slug, lang, title, teaser, body_json, draft, draft_version, published_at, deleted_at; cover_media_id, comments_mode and vote_adjustment arrive with their slices) · `article_revision` · `slug_redirect` · `tag` / `article_tag` · `media` (sha256, mime, bytes, original_name, meta_json) · `media_variant` (media_id, preset, path, bytes) · `article_media` · `job` (type, payload, state, attempts, run_after) · `vote` (article_id, user_id, created_at, unique on both) · `comment` (article_id, user_id, parent_id, path, depth, body, state, created_at) · `ban` · `setting`.

✅ Comments are fully nested. `path` is a materialised path (e.g. `0007.0012.0003`), so a whole thread loads with a single ordered query, and removing a subtree is a single `DELETE … WHERE path LIKE`.

## 7. Design track

The design is decided together and runs in parallel to the technical slices:

1. **Brief.** You tell me sites you like or dislike and the tone you want. ✅ The site gets its own identity, independent of your existing website.
2. **Three directions.** I build three clickable sample pages: home and article, with real-looking content including an image, audio and video. You pick one, or mix them.
3. **Design tokens.** Typography, colour (light/dark), spacing and widths, as CSS custom properties.
4. **Templates.** The chosen direction goes into the Twig templates in slice S9.

## 8. Implementation plan

Each slice is one PR (or a few small ones), ends in something you can see on **staging**, and keeps `main` deployable. Rough sizes: S ≈ ½–1 day, M ≈ 1–3 days, L ≈ 3–5 days of agent work, plus your review time.

| # | Slice | Delivers | Size |
|---|---|---|---|
| **S0** | Foundations | Composer project (platform pinned to PHP 8.3, `composer.phar` run with the PHP already on your PC), Slim/Twig skeleton, PHPUnit with the scratch guard, PHPStan, PHP-CS-Fixer, GitHub Actions CI with a PostgreSQL service, `docs/` conventions. Dev environment: a handoff to Ops for `edict_dev` and `edict_test` databases plus SSH tunnel access, and a `bin/tunnel` helper | M |
| **S1** | Staging on Roanoke | Edict side: deploy scripts (release/symlink/healthcheck/rollback), CI build artifact, and a written deploy handoff (vhost snippets, fpm pool, DB/role, users, paths). **The Ops: Roanoke session carries out the initial setup and first deploy** (subdomain, DNS, certificate, macro vhosts, pool, DB), so the host owner side knows everything that changed. Shows "Hello, Edict" on staging. *Needs your approvals: vhost diff, deploy key* | M |
| **S2a** | Auth core | Migrations, users, DB sessions, CSRF, rate limiting, admin CLI, password + TOTP login, logout-everywhere | M |
| **S2b** | Twitch + Google login | OAuth flows, reader accounts, admin allow-list, account page (rename, link provider, delete). *Needs from you: OAuth apps at Google and Twitch* | M |
| **S2c** | Passkeys | WebAuthn registration and login for the admin | S |
| **S3** | Articles core | Schema + migrations, minimal admin list and form, draft/publish, slugs + redirects, tags, public home/article/archive/tag pages on a plain placeholder theme | M |
| **S4** | Editor | Full-screen TipTap editor, autosave + unsaved-changes guard, JSON→HTML server renderer with allow-list, preview, static pages | L |
| **S5** | Media core + images | Chunked upload, content-addressed store, quotas/limits, job queue + rc.d worker, libvips variants, metadata stripping, image and gallery blocks, lightbox, media library panel. *Needs your approval: pkgin install* | L |
| **S6** | Audio & video | ffmpeg transcode presets, poster frames, waveform peaks, player UI, processing states in the editor | M |
| **S7** | Files & PDFs | Download cards, allow-list, PDF preview (if poppler is approved) | S |
| **S8** | Votes | Like button (one per account), login prompt for visitors, royal adjustment UI (inline + admin) | S |
| **S9** | Design implementation | The chosen direction (design track §7) applied to all public templates, light/dark, responsive, accessibility pass | L |
| **S10** | Comments & moderation | Comment form for logged-in readers, rate limits, moderation modes, inline + queue moderation, bans, word filter, closing rules | L |
| **S11** | Launch hardening | Full-text Atom feed, CSP, SEO bits (meta/OG/sitemap/robots), legal page templates (the privacy page must cover Twitch/Google login), error pages, newsyslog, disk alerts, cleanup sweeps, restore test, production vhost via Ops, **go-live** | M |
| S12+ | Post-launch | Search, scheduled publishing, notifications, … in the order you choose | — |

Order rationale: deploying early (S1) removes the biggest risks, which are on the host side. Writing (S3–S4) comes before reader features because you'll want to write real content while the rest is built. Design (S9) waits until real content exists to design against, but its exploration starts right after this concept is approved.

**Working method:** every slice is a GitHub issue. Work happens on a branch, in its own worktree, and becomes a PR. CI must be green. I review, you approve and merge, and it deploys to staging. Production deploys happen only on your go.

**Deployments go through Ops.** The initial staging setup and deploy (S1), the production go-live (S11), and any later host-side change (new packages, vhost or pool changes, cron, rc.d services) are handed to the **Ops: Roanoke** session as a written handoff, which carries them out. Edict development never changes the host directly. Once the pipeline is established, routine code-only deploys can run from CI, but only if you and Ops agree to that.

## 9. Open questions for you

1. **Design brief:** examples of sites you like or dislike, and the tone you want. This starts the design track once the concept is merged.

2. **OAuth apps:** only you can register these, in the Google Cloud Console and the Twitch Developer Console. Redirect URLs (lowercase host): `https://edict.networkxxiii.de/auth/{google,twitch}/callback`, the same on `staging.edict.networkxxiii.de`, plus `http://localhost:8080/auth/{google,twitch}/callback` for development. Client secrets go directly into `.env`, never into chats or commits.

All other questions were answered on 2026-11-04 and are recorded as ✅ above.
