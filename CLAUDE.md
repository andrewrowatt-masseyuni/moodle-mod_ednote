# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where this plugin sits

This directory is the `mod_ednote` plugin and **its own git repository** (remote
`git@github.com:andrewrowatt-masseyuni/moodle-mod_ednote.git`, branch `main`).

It is checked out at `mod/ednote` inside a **Moodle 4.5 core checkout** (branch `MOODLE_405_STABLE`,
upstream Moodle) at `/home/arowatt/moodle405_mod_edpreset`, which is the development host and the
session's working directory. **Two nested repositories: paths and cwd below are explicit about which
one they mean.**

Consequences:

* Commit plugin work from inside this directory. The outer repo is upstream Moodle core — never
  commit plugin changes there, and never commit `mod/ednote` into it (it shows there as an untracked
  directory, as do the other locally-installed plugins: `mod/edpreset`, `mod/questionnaire`,
  `local/codechecker`, `local/moodlecheck`, `theme/snap`).
* Editing Moodle core files is almost never the answer. When core behaviour looks wrong, read the
  core source to understand it and work around it in the plugin.
* [mod/edpreset](../edpreset/) is the companion plugin — also its own repo, with its own
  `CLAUDE.md` and a `README.md` design record. It is `mod_ednote`'s main **producer**: its
  `activity_copier::emit_note()` creates notes when a teacher copies a preset. The two are developed
  and tested as a pair, but the link is **soft and one-way**: neither declares
  `$plugin->dependencies` on the other, and each installs and works alone.

**This plugin has no README.** The design record lives in the source: the file-level and inline
comments are unusually dense and explain *why* each non-obvious decision was made, usually with the
failure mode that motivated it. Read the comments in the area you are changing before changing it,
and keep them accurate in the same change.

## Development environment

Moodle runs in `moodle-docker` containers (compose project `moodle405_mod_edpreset`), with the Moodle
root bind-mounted at `/var/www/html` — so this plugin is `/var/www/html/mod/ednote` inside the
container. Containers are normally already up.

Run anything Moodle-side inside the webserver container:

```bash
docker exec moodle405_mod_edpreset-webserver-1 <command>   # cwd is /var/www/html
```

The `moodle-docker-compose` wrapper (`/home/arowatt/moodle-docker/bin/moodle-docker-compose`) is what
[.vscode/tasks.json](.vscode/tasks.json) uses, but it needs `COMPOSE_PROJECT_NAME`,
`MOODLE_DOCKER_WWWROOT` and `MOODLE_DOCKER_DB` exported — these are **not** in the shell profile, so
prefer plain `docker exec` with the container name above.

`moodle-plugin-ci` runs on the **host** (PHP 8.3, vs PHP 8.1 in the container) from
`/home/arowatt/moodle-plugin-ci`, invoked from the Moodle root as `../moodle-plugin-ci/bin/…`.

## Commands

**Run these from the Moodle root (`/home/arowatt/moodle405_mod_edpreset`), not from this plugin
directory** — both the container paths and the `../moodle-plugin-ci` relative path are anchored
there. The grunt block below is the one exception.

```bash
# PHPUnit — whole plugin
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit --testsuite mod_ednote_testsuite

# PHPUnit — one file / one test (fastest feedback loop; use this while iterating)
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit mod/ednote/tests/guidance_test.php
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit --filter test_supports mod/ednote/tests/lib_test.php

# Behat — whole plugin
docker exec -u www-data moodle405_mod_edpreset-webserver-1 \
  php admin/tool/behat/cli/run.php --tags=@mod_ednote --format progress

# Core privacy provider test — this plugin implements privacy providers, so it must stay green
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit privacy/tests/privacy/provider_test.php

# Re-init test environments after schema/version changes (db/install.xml, version.php, generators)
docker exec moodle405_mod_edpreset-webserver-1 php admin/tool/phpunit/cli/init.php
docker exec moodle405_mod_edpreset-webserver-1 php admin/tool/behat/cli/init.php
```

Static analysis and style (host, from the Moodle root):

```bash
../moodle-plugin-ci/bin/moodle-plugin-ci phplint  ./mod/ednote
../moodle-plugin-ci/bin/moodle-plugin-ci phpcs    ./mod/ednote     # CI runs with --max-warnings 0
../moodle-plugin-ci/bin/moodle-plugin-ci phpcbf   ./mod/ednote     # auto-fix style
../moodle-plugin-ci/bin/moodle-plugin-ci phpmd    ./mod/ednote     # advisory; CI does not fail on it
../moodle-plugin-ci/bin/moodle-plugin-ci mustache ./mod/ednote
docker exec moodle405_mod_edpreset-webserver-1 \
  php local/moodlecheck/cli/moodlecheck.php -p=mod/ednote -f=text  # PHPDoc; CI runs --max-warnings 0
```

`moodle-plugin-ci validate` and `savepoints` **cannot be run on the host** — they boot Moodle and die
on `$CFG->dataroot` (which only exists inside the container). Those two are CI-only; the PHPDoc check
is covered locally by the `moodlecheck` command above rather than `moodle-plugin-ci phpdoc`.

JS/CSS/Gherkin (host, **cwd is this plugin directory** — Moodle's grunt detects which plugin to build
from cwd, so running these from the Moodle root would process all of core instead):

```bash
grunt --max-lint-warnings=0 amd          # required after editing amd/src/note.js
grunt --max-lint-warnings=0 stylelint
grunt --max-lint-warnings=0 gherkinlint
```

`amd/build/note.min.js` and its source map are **committed**. Editing `amd/src/note.js` without
running `grunt amd` ships a stale bundle, and CI's grunt step will fail on the diff.

Site maintenance:

```bash
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/upgrade.php --non-interactive   # install/upgrade after version.php bump
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/purge_caches.php                # after AMD, template, lang or capability changes
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/uninstall_plugins.php --plugins=mod_ednote --run
```

CI ([.github/workflows/moodle-ci.yml](.github/workflows/moodle-ci.yml)) runs the full
`moodle-plugin-ci` set on push/PR against Moodle 4.5 / PHP 8.1 / PostgreSQL 16: phplint, phpmd,
phpcs, phpdoc, validate, savepoints, mustache, grunt, PHPUnit, Behat.

The `moodle-review` skill reviews code against Moodle coding style, security and Core API
guidelines — use it for review passes on plugin code.

## Architecture

`mod_ednote` is a **teacher-only note rendered inline on the course page** — a label that students
cannot see. It is shaped on `mod_label`: `MOD_ARCHETYPE_RESOURCE`, `FEATURE_NO_VIEW_LINK`, body held
in `intro`, no grades, groups, completion or idnumber. [view.php](view.php) exists only to redirect
the stray visitor back to the course.

Its reason to exist is the pairing with `mod_edpreset`: a note usually carries a `presetid` and is a
**live view onto that preset's `teacherguidance`**, so a curator rewording the exemplar in the
template course updates every note already sitting in every course.

### The two load-bearing mechanisms

**1. Students are kept out by a capability, not by visibility.**
`cm_info::is_user_access_restricted_by_capability()` (`lib/modinfolib.php`) looks for a capability
named exactly `mod/<modname>:view`. Because [db/access.php](db/access.php) defines `mod/ednote:view`
and omits the `student` archetype, core marks the cm not user-visible before anything in this plugin
runs — in every theme, the course index and navigation included. There is **no second line of
defence**: granting `mod/ednote:view` to students exposes every note on the site. A note stays
`visible = 1`, so it carries no "Hidden from students" badge and cannot be exposed by the bulk *Show*
action; `moodle/course:viewhiddenactivities` deliberately does not override this.
[view.php](view.php) and `ednote_pluginfile()` re-check the capability, because neither is reached
through whatever made the note invisible on the course page.

**2. The note's text is resolved per request and never cached into modinfo.**
`ednote_get_coursemodule_info()` deliberately does **not** set `$info->content` (which is what
`mod_label` does) — `cached_cm_info` is cached in modinfo, and a cached body would show last week's
wording until something bumped the course cache. Only the `presetid` goes into `customdata`, because
that is fixed at creation. The text is resolved instead in `ednote_cm_info_view()`, via
[classes/guidance.php](classes/guidance.php).

### Text resolution — [classes/guidance.php](classes/guidance.php)

`guidance::for_course()` runs **one direct query per course page**, statically cached, and
`for_cm()` reads from it. It is a raw `$DB` query rather than a walk over `get_fast_modinfo()`
because it is called while the modinfo object for that very course is mid-build.

The `edpreset_item` table cannot be joined blindly — `mod_edpreset` is optional and its tables may
not exist — so `edpreset_is_installed()` (a `class_exists()` check) picks between two SQL variants.

Resolution order per note:

1. **Live** `edpreset_item.teacherguidance` when the note has a `presetid` and the preset exists.
2. **Snapshot** — the note's own `intro`, written by `emit_note()` at creation precisely so this
   fallback has something to show. Rendered through `format_module_intro()`, not a bare
   `format_text()`, so `@@PLUGINFILE@@` placeholders resolve against the module context and reach
   `ednote_pluginfile()`.
3. Nothing.

A note that had a `presetid` but fell through to step 2 or 3 is flagged `missing`, and the template
tells the teacher the text may be stale.

Both branches produce **already-cleaned HTML** rendered unescaped by the template: preset guidance is
cleaned once by `mod_edpreset` at bake time, a hand-written note by `format_module_intro()`.
Re-cleaning or re-escaping downstream double-escapes entities the curator meant literally.

### Per-user hiding — [classes/hidden.php](classes/hidden.php)

Hiding is always personal; one teacher tidying their view never changes what a colleague sees. State
lives in **`core_favourites`** under component `mod_ednote`, matching how `mod_edpreset` stores
starred presets. Two scopes, differing only in what the row is keyed on:

| Scope | Key | Effect |
| --- | --- | --- |
| `hiddennote` | course module id | Hides exactly this note |
| `hiddenguidance` | preset id | Hides every note carrying that guidance, in every course |

Neither is keyed on the guidance *text*, so a curator can reword a preset without silently
un-hiding it. Rows are stored in the **user's own context** for both scopes — they describe a user
preference, and it means unhiding still works after the note is deleted, where
`context_module::instance()` would throw. The read cache is keyed by user id, because the user can
change within a request ("log in as", cron).

Three traps live here:

* **Removing a hidden note from the page takes two halves.** `ednote_cm_info_dynamic()` calls
  `set_user_visible(false)`, which is enough for theme_snap (its course renderer returns early on
  `!$mod->uservisible`) but not for core's course format, which gates on `uservisibleoncoursepage` —
  already derived by `update_user_visible()` before this callback runs. The other half is the CSS
  rule `li.modtype_ednote:has(.ednote-is-hidden)` in [styles.css](styles.css), keyed on a marker
  `ednote_cm_info_view()` emits in place of the note. `mod_ednote/note` also removes those markers on
  load, for browsers without `:has()`. Doing it in CSS is safe only because it is cosmetic —
  students never reach this markup.
* **A hidden note cannot be resolved through `require_login()`.** Both [hidden.php](hidden.php) and
  [classes/external/set_hidden.php](classes/external/set_hidden.php) use
  `get_coursemodule_from_id()` plus the **course** context, never `get_course_and_cm_from_cmid()`,
  which refuses a cm that is not user-visible — which is exactly what a note becomes the moment it is
  hidden. Using it would make unhiding a one-way door. `require_capability('mod/ednote:view', …)` on
  the module context is then the real gate rather than a formality.
* **Nothing in core cleans these rows up.** They live in each user's context, so
  `ednote_delete_instance()` calls `hidden::purge_for_cm()` before deleting the record (it needs the
  record to find the cm).

The web service resolves the preset id **server-side** from the cm, so the `hiddenguidance` scope
cannot be used to hide arbitrary presets; a standalone note asking for it falls back to
`hiddennote` rather than writing a row keyed on 0.

### Request flow

* [lib.php](lib.php) — the core callbacks described above, plus `ednote_extend_navigation_course()`,
  which adds the *Hidden notes* link **only when the user has something to restore** (a hidden note
  leaves no trace on the course page, so without it there is no way back).
* [classes/output/note.php](classes/output/note.php) → [templates/note.mustache](templates/note.mustache)
  — exports **both** states, guidance and the "you have hidden this" confirmation. The AMD module
  toggles the `hidden` attribute between them, so undo needs no round trip and nothing is rendered
  client-side.
* [amd/src/note.js](amd/src/note.js) — one delegated click listener for the whole page; the JS is
  attached once per page in `ednote_cm_info_view()`, not once per note. Hiding deliberately does
  *not* remove the note from the page it was clicked on. Uses `try`/`catch` rather than a promise
  chain because `core/ajax` returns a jQuery Deferred with no `.finally()`, which would strand the
  `Pending` and leave the page permanently "not ready".
* [hidden.php](hidden.php) — lists what the user has hidden in a course and shows it again. Also the
  **no-JavaScript target** for the hide links, which are real sesskey-carrying links that the AMD
  module intercepts only when it can finish the job itself.
* [classes/privacy/provider.php](classes/privacy/provider.php) — notes are course content, not
  personal data (a preset note does not even store the text it shows). The only personal data is the
  hide state, delegated to `core_favourites`' own provider in the user context.

### The settings form — [mod_form.php](mod_form.php)

Like a label, a note has no separate title: `name` is a hidden element derived from the body in
`data_postprocessing()`. That forces two overrides:

* `validation()` drops the required-name error, because the name is still empty when
  `moodleform_mod::validation()` runs and an error on a hidden element **renders nothing** — the form
  would bounce back looking untouched.
* `FEATURE_SHOW_DESCRIPTION` is `false` (the body *is* the note), so `standard_intro_elements()` adds
  no "Display description" checkbox and the form supplies `showdescription` itself.

For a note carrying a `presetid`, `introeditor` is `hardFreeze()`d after `standard_intro_elements()`
created it, with an explanatory notice. Freezing also drops the element's rules, which is wanted:
`$CFG->requiremodintro` would otherwise demand a value for a field nobody can type into. A frozen
element submits nothing, so the stored snapshot and its files survive a save unchanged.

`presetid` is **write-once**: set in `ednote_add_instance()`, explicitly `unset()` in
`ednote_update_instance()`. Re-pointing a note at a different preset is not something the form offers.

## Conventions and traps

* **`$plugin->supported` is pinned to `[405, 405]`** to match `mod_edpreset`, this plugin's main
  producer. Nothing here depends on a deprecated API; the two are simply released as a pair.
* Both static caches (`guidance::$cache`, `hidden::$cache`) are keyed on ids that PHPUnit reuses
  between tests, since `resetAfterTest()` rolls the database back. **Reset both in `setUp()`** — see
  [tests/lib_test.php](tests/lib_test.php).
* `ednote.presetid` is deliberately **not a foreign key** — `mod_edpreset`'s tables may not exist.
  In [backup/moodle2/](backup/moodle2/) it is carried but deliberately **not annotated and not
  remapped**: it names a preset on the site, not an id inside the course. It must survive a backup
  because it is also the key the `hiddenguidance` scope is stored against.
* Bump `$plugin->version` in [version.php](version.php) with every `db/` change, and pair it with an
  `upgrade_mod_savepoint()` step in [db/upgrade.php](db/upgrade.php) — CI's `savepoints` check
  enforces this. `xmldb_ednote_upgrade()` is currently a bare `return true`.
* The web service in [db/services.php](db/services.php) is `ajax => true` and in **no service**: it is
  called by the course page's own JS as the logged-in user, never by an external client with a token.
* All user-facing text goes through `get_string()` into [lang/en/ednote.php](lang/en/ednote.php),
  whose keys are kept in alphabetical order.
* [styles.css](styles.css) targets **both** core's course format and `theme_snap` (installed in this
  checkout) — Snap has its own course renderer and its own card markup. Check both when changing
  course-page appearance.
* Behat page resolution comes from [tests/behat/behat_mod_ednote.php](tests/behat/behat_mod_ednote.php),
  which resolves `"Course 1" "mod_ednote > hidden notes"` straight to a URL rather than clicking a
  navigation node whose reachability depends on secondary-nav overflow at the current window size.
