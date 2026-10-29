=== ContentHealth — Content Health ===
Contributors: rbnbilisim
Tags: content health, seo, cleanup, taxonomy, wp-cli
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: adminkit
Stable tag: 0.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Link: https://github.com/RbnCore

A content-health panel in the spirit of WordPress Site Health: finds AI and interface leftovers, markdown, broken URLs, empty and duplicate tags, and missing alt text. Safe fixes with dry run, confirmation and undo.

== Description ==

* 30 rules: content cleanup, SEO, media, links, tags and categories. Each one can be turned on or off in Settings.
* Needs the AdminKit plugin (shared cache, logs and the RBN Kit menu): without it ContentHealth switches itself off and says so in the admin.
* Error score and Suggestion score (0–100, live from the status of the findings), critical/warning/info counts, a filterable and searchable findings list, CSV export.
* Batched scan with a persistent cursor: it does not strain the server, even on a large site (AJAX, WP-Cron, WP-CLI).
* Fixes first show a diff as a dry run, are applied on confirmation and are written to the undo log. The "last modified" date and the revisions do not change.
* Tag Doctor: delete empty tags, merge duplicates, shorten long names, noindex single-use tags.
* Alt Text: fill in missing alt text from a template.
* Works with the meta description fields of Yoast, Rank Math and AIOSEO.
* Zero front-end load: it adds no script, style, query or output. It calls no external service of its own (update checks are done by AdminKit).
* WP-CLI: `wp contenthealth scan|status|list|fix|undo|export`.

== Installation ==

1. Install and activate AdminKit first; ContentHealth cannot be activated without it.
2. Upload the folder to `wp-content/plugins/` and activate it.
3. RBN Kit → Content Health → "Scan now".

== Frequently Asked Questions ==

= Does it slow down the front end? =
No. On the front end only a `wp_robots` filter runs, and only if there is a noindex list.

= Can a fix be undone? =
Yes. Every run writes the previous value and its SHA-256 digest to the log; content that was changed by hand is skipped.

= Is the data deleted on uninstall? =
Not by default. If you choose "Delete on uninstall" in Settings, the tables and settings are deleted.

== Changelog ==

= 0.1.6 =
* Updates: the plugin is now updated by the AdminKit core (one central updater, AdminKit 0.1.1 or newer; "Update URI" header added). The package is checked with its SHA-256 digest and Ed25519 signature before installation. The plugin itself still calls no external service; the check goes through AdminKit and follows the RBN Kit network setting.
* The runtime gate now needs AdminKit core API level 3.

= 0.1.5 =
* Menu: the screen now sits under the shared "RBN Kit" top-level menu (RBN Kit → Content Health); the old Tools address redirects to the new one.
* New: Live score. When a post is saved, a finding is fixed, undone or ignored, the score on the Dashboard is recalculated at once (no need to wait for a scan; 60 s cache, cleared on change).
* New: Two scores. The "Error score" uses only critical + warning rules (errors on the site); the "Suggestion score" uses only info rules (improvement suggestions). The Dashboard has a separate, always-open "How is the score calculated?" card (a weight/cap table and the 3 rules that take the most points right now). The score in the scan record is now the Error score; the Suggestion score is stored in the scan details (`suggest_score`), and old records show "—".
* New: The admin CSS/JS version parameter is the file content digest; a changed file is downloaded again even when the version stays the same.
* New: When a post is saved, only the single-post rules for that post run again. A finding fixed by hand is marked "fixed" on its own (the "Fixed by hand" label); one that still matches stays open; a new one opens. General (cluster) rules do not run on save and wait for a scan.
* New: Settings → Safety → "Re-check on save" switch (on by default; when off, the old behaviour applies).
* New: A "Last check" line in the findings list and a "Post changed afterwards" badge on an open finding.
* Revisions, autosaves, imports and unauthorised contexts are not checked; if the check fails, saving is not blocked.

= 0.1.4 =
* Fix: the "AI interface leftover" finding said "nothing to change" for `attachment-container` (search result / media container); the fixer now cleans this container too. The heading text is kept inside `<p>` and the empty container is removed; media is not deleted from a container that holds an image or a link.
* Fix: when the chat wrapper was marked with an `id` (model-response-message-content…), extra classes such as `source-inline-chip-container` and nested wrappers were left unfixed after they were found; they are now cleaned.
* Fix: the LaTeX leftover rule reported the same `math-inline` trace a second time as the AI interface leftover and could not fix it; the duplicate finding was removed.
* New: `attachment-container youtube` (empty container) was added to the default pattern. If you saved the pattern list in Settings earlier, reset the list to the default to get the new pattern.

= 0.1.3 =
* The documentation moved to the `docs/` folder and was removed from the install package.

= 0.1.2 =
* The admin interface was renewed: Dashboard, Findings, Tag Doctor, Alt Text, Settings and the Undo log; first run (3 steps), drawer details, bulk-fix wizard (dry run → confirm → apply → undo), pause/cancel/retry.
* It follows the WordPress admin colour scheme; light and dark (Midnight) theme, responsive down to 375 px, keyboard and screen-reader access.
* Ready for translation: a .pot and tr_TR (.po/.mo) are under `languages/`.
* The core behaviour did not change: the scan engine, the 30 rules, undo, REST and WP-CLI are the same; no effect on the front end.

= 0.1.1 =
* First release: scanner, 30 rules, fix + undo, Tag Doctor, Alt Text, REST, WP-CLI.
