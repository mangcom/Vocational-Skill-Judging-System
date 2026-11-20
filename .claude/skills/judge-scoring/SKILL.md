---
name: judge-scoring
description: Reusable architecture, conventions, and hard-won gotchas for the ระบบให้คะแนนกรรมการ (Vocational Skill Judging System / VSJS) — a PHP 8.2/MariaDB/Bootstrap 5/Docker Compose app for scoring vocational-skill competitions (ปวส./ปวช.). Use when extending this project: adding pages, changing the DB schema, touching the sidebar nav, generating printable PDF documents, working with the permission model (Admin/ประธานกรรมการ/กรรมการและเลขานุการ/กรรมการ), or regenerating the user manual. Also use when debugging Clean URL routing, Docker-on-Windows quirks, 2FA/TOTP, or Bootstrap table sticky-header issues in this codebase.
---

# judge-scoring (VSJS) — project skill

Thai vocational-skill competition judging system. Single Docker Compose stack: `app` (PHP 8.2 + Apache) and `db` (MariaDB 11). No Composer/vendor — everything is vanilla PHP, including TOTP 2FA (no external libraries at all). Bootstrap 5.3.3 + SweetAlert2 + Google Fonts (Sarabun) loaded from CDN.

## Repo layout

```
judge-scoring/
├── docker-compose.yml          # services: app (port APP_PORT, default 8080), db
├── .env / .env.example
├── README.md                   # user/admin-facing docs — keep in sync with features
├── php/
│   ├── Dockerfile, docker-entrypoint.sh, uploads.ini (upload_max_filesize=100M)
│   └── src/
│       ├── app/                 # server logic — OUTSIDE Apache DocumentRoot, not web-reachable
│       │   ├── bootstrap.php    # require order: db → helpers → totp → auth → scoring; session_start()
│       │   ├── db.php           # PDO connection (reads DB_HOST/DB_NAME/DB_USER/DB_PASSWORD env)
│       │   ├── helpers.php      # e(), csrf_*, flash_*, base_url(), judge_position_options(), position_label(), fmt_score()
│       │   ├── auth.php         # current_user(), require_login/role, is_head_judge/is_skill_secretary, can_manage_skill_criteria(), attempt_login() (2-step for 2FA), finalize_login(), verify_pending_2fa()
│       │   ├── totp.php         # dependency-free RFC 6238 TOTP (base32 encode/decode, HMAC-SHA1)
│       │   ├── scoring.php      # scoring_all_divisions(), scoring_current_division(), scoring_judge_allowed_teams(), score matrix helpers
│       │   ├── migration_runner.php  # run_migrations(PDO): CREATE TABLE IF NOT EXISTS + safe_exec() ALTERs + idempotent seed data ($divisionsSeed, $skillsSeed)
│       │   ├── migrate.php      # CLI entrypoint: require db.php + migration_runner.php; run_migrations(db())
│       │   ├── seed_password.php # CLI-only (PHP_SAPI==='cli' guard): reset any user's password
│       │   └── wait_db.php, env_file.php
│       └── public/              # Apache DocumentRoot
│           ├── .htaccess        # Clean URL rewrite (see below)
│           ├── index.php, login.php, logout.php, install.php, help.php
│           ├── verify_2fa.php   # 2nd login step when user.totp_enabled
│           ├── profile.php      # self-service: name, avatar, password, 2FA enable/disable
│           ├── criteria_doc.php # printable "เกณฑ์การให้คะแนน" doc; ALSO the entry point judges use to edit their own criteria
│           ├── download_criteria.php  # serves division's uploaded original rules PDF (auth-gated)
│           ├── admin/           # divisions, skills, criteria, judges, teams, summary, summary_detail, score_sheet, backup
│           ├── judge/           # dashboard, score, assign
│           ├── includes/        # header.php (sidebar+topbar shell), footer.php, division_tabs.php
│           └── assets/
│               ├── css/style.css, js/app.js
│               ├── docs/user-manual.pdf   # printable manual — regenerate workflow below
│               └── uploads/avatars/       # user-uploaded avatars, gitignored except .gitkeep
└── storage/uploads/criteria/    # admin-uploaded original competition-rules files, gitignored except .gitkeep
```

## Database model (key tables)

- `divisions` — a "ทักษะ" / competition track (code, name, sort_order, description, criteria_file_path/name — the uploaded original rules PDF).
- `skills` — a "กลุ่มเกณฑ์การให้คะแนน" within a division (code, name, max_score, sort_order, is_bonus). `division_id` FK.
- `criteria_groups` — optional "หัวข้อ" sub-headings within a skill, no score of their own (code, name, description). Only some skills use these (e.g. DevOps "1.1 VLAN Configuration"); POC-HR (ปวช.) has none.
- `criteria` — the actual scored line items (code, name, description = "วิธีตรวจ/เกณฑ์ได้คะแนน", max_score, sort_order, optional `group_id`).
- `teams` — per-division, team_no unique within division.
- `users` — role ENUM('admin','judge'), position ENUM(5 values — see below), `avatar_path`, `totp_secret`, `totp_enabled`.
- `judge_skills` — which skills (criteria groups) a judge is responsible for; drives both score visibility and criteria_doc.php filtering.
- `judge_team_assignments` — optional per-division team restriction (chairman sets via judge/assign.php).
- `scores` — judge_id + team_id + criteria_id + score, unique per triple; team score for a criterion = AVERAGE across judges who scored it.

Position ENUM, in rank order (also drives `judge_position_options()` and print-sheet sort order):
`ประธานกรรมการ, รองประธานกรรมการ, กรรมการ, กรรมการและเลขานุการ, ผู้ช่วยกรรมการและเลขานุการ`

## Permission model

- `require_login()` / `require_role('admin')` / `require_admin_or_head_judge()` in `auth.php`.
- `is_head_judge($user)`: role=judge AND position='ประธานกรรมการ'.
- `is_skill_secretary($user)`: role=judge AND position='กรรมการและเลขานุการ'.
- `can_manage_skill_criteria($user, $pdo, $skillId)`: admin → true; else must be head-judge-or-secretary **and** have a `judge_skills` row for that exact skill. Used by `admin/criteria.php` (gates the whole page, not just admin-only anymore) and to show the "✏️ แก้ไขเกณฑ์" link on `criteria_doc.php`.
- Judges never see `admin/skills.php` (division-wide skill list) — only `/criteria_doc`, pre-filtered via `judge_skills`, with "ห้ามแสดงกลุ่มเกณฑ์อื่น" enforced server-side (not just hidden in UI).
- 2FA: `attempt_login()` returns `['status' => 'ok'|'needs_2fa'|'invalid', 'user'?]`. On `needs_2fa`, sets `$_SESSION['pending_2fa_user_id']` and `login.php` redirects to `verify_2fa.php`, which calls `verify_pending_2fa($code)` → `totp_verify_code()` → `finalize_login()`.

## Clean URLs

`public/.htaccess`: if a literal file/dir doesn't exist but `<path>.php` does, rewrite internally to it (URL bar stays clean). `base_url()` in helpers.php strips a single trailing `.php` from any path you pass it, so **always link via `base_url('admin/foo.php?x=1')`** — never hand-write `/admin/foo` — and old `.php` links still resolve too (both work). Server-side `$_SERVER['SCRIPT_NAME']`-based logic (e.g. `division_tabs.php`) does `preg_replace('/\.php$/', '', $_SERVER['SCRIPT_NAME'])` to build same-page links.

## Seeding pattern (migration_runner.php)

`run_migrations($pdo)` is idempotent and runs on every container start (`migrate.php`) and from `/install.php`. Pattern per entity: `SELECT ... WHERE code = ?` to check existence, only `INSERT` if missing — safe to re-run forever, **never duplicates**, and editing content directly in a live DB will NOT get overwritten by a later `migrate.php` run (by design). Column/constraint changes use `safe_exec($pdo, "ALTER TABLE ... ADD COLUMN ...")`, which swallows "already exists"-class MySQL error codes (1005, 1060, 1061, 1091, 1068, 121) — add new ones there if a future ALTER needs it.

`$skillsSeed` entries: first (default) division's skills omit `'division_id'`; every other division's entries need `'division_id' => $someVarName` (the PHP variable holding that division's live ID, resolved earlier in the function from `$divisionIds[$code]`). Each skill entry optionally has `'groups' => [[code, name, description], ...]` and always has `'criteria' => [[code, name, description, max_score], ...]` or 5-tuple with a trailing group-code string to attach it to a group.

**When you edit criteria/skills/divisions content live via the admin UI during a session, mirror the change into `$skillsSeed`/`$divisionsSeed` afterward** so fresh installs match — this project's history is full of "live DB ≠ seed" drift. The reliable way to regenerate the seed exactly from a live DB: write a one-off PHP script (`app/_dump_seed.php` pattern used repeatedly this project) that queries `divisions`/`skills`/`criteria_groups`/`criteria` and emits the PHP array literal text, then splice it into `migration_runner.php` — don't hand-transcribe 80+ criteria rows.

## Printable PDF documents (score_sheet.php, criteria_doc.php print branch)

No server-side PDF library. Pattern: render a **full standalone HTML document** (own `<head>`, Google Fonts Sarabun, `@page { size: A4; }` print CSS, a `.toolbar.no-print` with a `window.print()` button) directly from the PHP page when a specific POST/GET flag is present, then `exit;` before falling through to the normal `header.php`-wrapped page. The user prints to PDF via the browser's own print dialog — nothing is generated server-side. `criteria_doc.php` supports `?all=1` or `?skill_ids[]=` as a GET shortcut (useful for direct links / testing) in addition to the POST form.

## 2FA (TOTP) — app/totp.php

Pure-PHP RFC 6238: `totp_generate_secret()`, `totp_base32_encode/decode()`, `totp_code_at()`, `totp_verify_code($secret, $code, $window=1)` (±30s tolerance), `totp_provisioning_uri()` (builds `otpauth://` URI). QR code is rendered **client-side only** via the `qrcode@1.5.3` CDN JS library in `profile.php` — the secret is embedded in the page (unavoidable, user must enter it anyway) but never sent to any third-party QR-image service. Setup flow: `enable_2fa_init` generates secret into `$_SESSION['totp_setup_secret']` → user scans + submits a code → `enable_2fa_confirm` verifies and persists to `users.totp_secret`/`totp_enabled`.

## Avatar uploads

Stored **inside** DocumentRoot at `public/assets/uploads/avatars/` (unlike criteria-file uploads, which are deliberately outside DocumentRoot) because avatars aren't sensitive and need direct `<img src>` access. Filename pattern `u{userId}_{random12hex}.{ext}`; old file is `@unlink()`'d on replace. 2MB limit, extensions jpg/jpeg/png/gif/webp.

## Sidebar / topbar shell (includes/header.php, includes/footer.php, assets/css/style.css)

Redesigned from a Bootstrap top navbar to a left sidebar (commit history: "Move top navbar menu into a collapsible left sidebar"). Key points if touching this again:
- Nav items are built as a PHP array in `header.php` based on `$user['role']` / `is_head_judge()`, then rendered as `<aside id="appSidebar">`. Add new pages' nav entries there, not in multiple places.
- `--sidebar-width` / `--sidebar-width-collapsed` / `--topbar-height` CSS vars in `style.css`. Collapse state persists via `localStorage('sidebarCollapsed')`, applied **in `<head>`** (before body paint) to avoid FOUC.
- Mobile (<992px): sidebar becomes an off-canvas drawer (`html.sidebar-mobile-open`), toggled by the same hamburger button; JS lives in `assets/js/app.js`.
- `app.js` and `style.css` are loaded with `?v=<?= @filemtime(...) ?>` cache-busting — **if you edit either and testing shows stale behavior, it's almost always this filemtime query string not being what you think, or the browser having fetched before the file's mtime changed**.
- `division_tabs.php` renders the division switcher as a plain `<select>` (not a Bootstrap dropdown) — explicitly chosen over a btn-group dropdown because the dropdown overflowed on mobile; keep it a `<select>`.

## Known CSS gotcha: sticky table headers inside Bootstrap `.table-responsive`

Multiple attempts in this project's history to add `position: sticky` to `<thead><th>` inside `.table-responsive` (which has Bootstrap's `overflow-x: auto`) produced a recurring bug: a sliver of a scrolled-past row visible **above** the sticky header. Root cause, confirmed by live pixel-probing with `elementFromPoint()`: `.table-responsive` is combined with Bootstrap's `.card-body` class, which carries `padding-top: 16px`. `position: sticky; top: 0` sticks to the **padding edge**, but `overflow: auto` only clips at the **border edge** — so that 16px of padding is an uncovered gap where scrolled content paints through. Fix (if reintroducing sticky headers): `padding-top: 0` on `.table-responsive`, and move the 16px into the `<thead><th>`'s own `padding-top` instead, so it's covered by the header's own opaque background. Also use `border-collapse: separate; border-spacing: 0` on the table — `border-collapse: collapse` + sticky cells is a second, independent source of border/background rendering glitches in Chromium. As of the last change in this project, sticky headers were **reverted entirely** (kept only `max-height: 70vh; overflow: auto` + `border-collapse: separate` + `white-space: nowrap` header cells + a `.col-manage` nowrap fix for the Edit/Delete button column) because repeated blind CSS iteration without live browser verification kept trading one visual bug for another (including clipping Thai vowel marks). **Don't re-attempt sticky headers without live browser access to pixel-verify** — see the Dev workflow section below for how to get that access.

## Dev workflow / Windows + Docker gotchas

- `docker-compose.yml` binds `./php/src:/var/www/html` — edits to `php/src/**` take effect immediately, no rebuild needed for PHP changes.
- **Always prefix `docker compose exec` calls with `MSYS_NO_PATHCONV=1`** when using the Bash tool on Windows/Git Bash, or absolute Unix paths like `/var/www/html/app/foo.php` get mangled into `C:/Program Files/Git/var/...`. Example: `MSYS_NO_PATHCONV=1 docker compose exec app php -l /var/www/html/app/foo.php`.
- Lint every PHP edit with `php -l` before considering it done — this project has no test suite, `php -l` is the only automated check.
- After any migration_runner.php change, run `MSYS_NO_PATHCONV=1 docker compose exec app php app/migrate.php` and confirm `[migrate] done` with no errors (and ideally re-run it a second time to confirm idempotency — no duplicate-row errors).
- `localhost:8080` can be hijacked by unrelated Windows processes on `[::1]:8080`; prefer `127.0.0.1:8080` for curl/PowerShell tests (the built-in browser tool is fine with either).
- To get **live, pixel-accurate browser verification** (strongly recommended before touching CSS/layout, per the sticky-header lesson above): you need real login credentials. `seed_password.php` can mint a test password, but resetting *any* account's password (even a throwaway judge test account) is reliably blocked by this environment's auto-mode permission classifier as a "destructive/unauthorized" action — don't keep retrying it blind. Either ask the user for current admin credentials, or ask them to grant the reset explicitly.
- `.env` is gitignored; `.env.example` holds fallback defaults. Real DB/Admin credentials are set via `/install.php` on first run, not by editing `.env` by hand (though `APP_PORT` / `DB_ROOT_PASSWORD` can be pre-set in `.env` before first `docker compose up`).

## User manual PDF (assets/docs/user-manual.pdf) — regeneration workflow

There is no PDF library in this stack, so the manual is built the same way as the printable in-app documents: author a **standalone HTML file** (A4 print CSS, Sarabun font, styled with `.note`/`.warn`/`.tip` callout boxes, numbered `.step` circles, a cover page) in the session's scratchpad directory, then render it with headless Edge:

```powershell
& "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --headless --disable-gpu --no-pdf-header-footer --print-to-pdf="<out>.pdf" --virtual-time-budget=15000 "file:///<path-to-html>"
```

Then copy the result over `php/src/public/assets/docs/user-manual.pdf`. **Keep the scratchpad HTML file around across sessions** (don't delete it) — it's the editable source for the PDF; re-reading/re-editing it and re-running the Edge command is far faster than rebuilding the manual from scratch. Verify pages with the `Read` tool's `pages` parameter (e.g. `pages: "1-2"`) after regenerating, since text can silently clip or mis-render.

The manual's content should track `README.md` section-for-section — when a feature changes, update both, in the same change if possible (this project has repeatedly drifted: menu labels renamed in the app but not in README/manual, "แถบเมนูด้านบน" wording left over from the pre-sidebar UI, etc.).

## Quick checklist when adding a new admin/judge page

1. Create the PHP file under `public/admin/` or `public/judge/` (or project root for cross-role pages like `profile.php`/`criteria_doc.php`).
2. `require __DIR__ . '/../../app/bootstrap.php';` (two `../` from `admin/`/`judge/`, one `../` from project root).
3. Gate access: `require_role('admin')`, `require_login()` + manual role check, or `can_manage_skill_criteria()` as appropriate — don't default to admin-only if judges/chairman legitimately need it.
4. Add a nav entry in `includes/header.php`'s `$navItems` array (role-conditional).
5. Use `base_url('admin/foo.php?...')` for every link/redirect/form action — never hand-write a path.
6. `csrf_field()` / `csrf_verify()` on every POST form.
7. `require __DIR__ . '/../includes/header.php';` ... `require __DIR__ . '/../includes/footer.php';` for normal pages; skip both and emit a standalone `<!doctype html>` document + `exit;` for print-output branches.
8. Lint with `php -l`, test in the live browser if it's not purely server-side logic.
9. Update `README.md`'s menu/URL table and, if user-facing, the manual PDF.
