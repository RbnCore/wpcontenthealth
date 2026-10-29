# ContentHealth

ContentHealth is a content-health panel for WordPress, in the spirit of Site Health: it scans posts, tags and images for leftovers and common problems, and fixes them safely with a dry run, a confirmation step and an undo log.

## AdminKit is required

ContentHealth does not work without the **AdminKit** plugin. Its header declares `Requires Plugins: adminkit`.

- On WordPress 6.5 and later, WordPress refuses to activate ContentHealth while AdminKit is missing or inactive.
- A second check runs on every request: without AdminKit, or with an AdminKit older than core API level 1, ContentHealth adds no hook, prints nothing and shows one admin notice with a link to activate AdminKit (when it is installed) or to download and install it.
- Install and activate **AdminKit first**, then ContentHealth.

AdminKit supplies what this plugin shares with the other RBN plugins: the RBN Kit menu, the admin interface kit, the cache group `contenthealth.scores` and the log channel `contenthealth.errors`.

## Who it is for

Site owners and editors with a growing archive: content pasted from chat assistants or word processors, articles migrated between sites, empty or duplicate tags, images without alt text, and links that point at a development server.

## Features

- **30 rules in five groups**: content cleanup, SEO, media, links, and tags/categories. Each rule can be switched on or off in Settings, and thresholds and patterns are edited per rule.
- **Two scores**, both 0–100: an **Error score** from critical and warning findings, and a **Suggestion score** from informational ones. The dashboard explains the formula and lists the three rules that cost the most points right now.
- **Live scores.** Saving a post, fixing, undoing or ignoring a finding recalculates the score at once; no scan is needed (60-second cache, cleared on change).
- **Batched scanning** with a persistent cursor, triggered from the admin screen (AJAX), WP-Cron or WP-CLI. Runs can be paused, resumed, cancelled and retried; a run that stalls for 900 seconds is treated as abandoned.
- **Findings list** with rule, severity and status filters, search, pagination and bulk select. A drawer shows the proposed change as a diff.
- **Safe fixes.** A dry run comes first (REST and the admin screen default to it), changes are applied only after confirmation, and every changed field is written to the undo log with its SHA-256 digest. Content that was edited by hand after a fix is skipped when you undo.
- **The post's modified date and its revisions do not change** when a fix is applied (a setting, on by default).
- **Tag Doctor**: delete empty terms, merge duplicates, rename over-long terms, noindex single-use tags.
- **Alt Text**: fill in missing alt text from a template such as `{title}`, `{file_name}` or `{site_name}`.
- **Re-check on save.** When a post is saved, the single-post rules run again for that post only; a finding you fixed by hand is marked "Fixed by hand" without waiting for a full scan. Settings → Safety → "Re-check on save" (on by default).
- **Works with existing SEO plugins**: it reads the meta description fields of Yoast SEO, Rank Math and AIOSEO.
- **Zero front-end load**: no script, style, query or output; the only front-end hook is a `wp_robots` filter that is registered only when a noindex term list exists.
- **CSV export** of the findings list, with formula-injection protection.
- **WP-CLI**: `wp contenthealth scan|status|list|fix|undo|export`.
- **REST API** under `contenthealth/v1` for the admin screen (status, scan steps, issues, fix, undo); every route requires `manage_options` and a valid nonce.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- The AdminKit plugin (`Requires Plugins: adminkit`)
- A standard WordPress database; the plugin creates its own `{prefix}contenthealth_*` tables

## Installation

1. Install and activate **AdminKit** first; ContentHealth cannot be activated without it.
2. Download `contenthealth-0.1.5.zip` from [Releases](https://github.com/RbnCore/wpcontenthealth/releases). If you downloaded the package by hand, compare its SHA-256 with the `.sha256` asset next to it.
3. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
4. Open **RBN Kit → Content Health** and press **Scan now**. A three-step welcome screen offers to set scan frequency, scope and the first scan; you can skip it.

You can also copy the `contenthealth` folder into `wp-content/plugins/`.

## Updating

Version 0.1.5 has no automatic updater and does not contact GitHub.

To update, upload the new `contenthealth-<version>.zip` over the installed copy (Plugins → Add New → Upload Plugin → *Replace current*). Settings, findings and the undo log are kept: the database schema is additive and versioned, and an update only touches files.

## Privacy and external requests

- The plugin calls no external service and sends no telemetry.
- The only outgoing request it can make is part of the **"Broken internal links"** rule, which is off by default: it sends rate-limited HEAD requests (at most two per second) to links on your own site to look for 404/410 answers. No other host is contacted.
- Log lines are written only when `WP_DEBUG_LOG` is enabled, and they are short.
- On uninstall the data is kept by default. If you tick **"Delete on uninstall"** in Settings first, the plugin's tables and options are removed; deactivating the plugin only clears its scheduled events.

## Translation

English is the source language. A Turkish (`tr_TR`) translation ships in `languages/` and follows the user's profile language, so a user whose profile is English sees English on a Turkish site. The `{title}`, `{file_name}` and `{site_name}` placeholders are translatable; the older Turkish placeholders still work in existing settings.

## RBN Kit menu

The screen lives under the shared **RBN Kit** top-level menu, just below Dashboard, as **RBN Kit → Content Health**. The old Tools address redirects to the new one. The menu itself comes from AdminKit, and all RBN plugins share it with a fixed item order (Overview → AdminKit → Cookies and Consent → Content Health → SEO Toolkit).

## Version and license

- Version 0.1.5
- License: GPL-2.0-or-later (see [LICENSE](LICENSE))
- Text domain: `contenthealth`

## Development notes

- This repository is the plugin folder itself, so the release package is a git archive of the repository root with the `contenthealth/` prefix.
- Structure: the main file boots the plugin and registers hooks; `includes/` holds the scanner, rules, fixer, undo log, REST routes, WP-CLI commands and screens; `includes/Modules/` groups rules by area; `languages/` holds the `.pot` and the Turkish translation.
- A rule extends the `Rule` class: id, group, severity, label, defaults, `check()` for a single object, `scan_batch()` for rules that need the whole table, and an optional fix callback. New rules are collected by the module registry.
- Settings live in one option (`contenthealth_settings`); tables are created with `dbDelta` and versioned through `contenthealth_db_version`.
- Test matrices, documentation and tooling live in the project tree outside this repository, so they never enter the package.

## Links

- Repository: https://github.com/RbnCore/wpcontenthealth
- Releases: https://github.com/RbnCore/wpcontenthealth/releases
- Issues: https://github.com/RbnCore/wpcontenthealth/issues
- Author: https://github.com/RbnCore
