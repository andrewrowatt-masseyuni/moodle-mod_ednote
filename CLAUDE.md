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

[README.md](README.md) is the plugin's design record — it documents not just what the code does but
*why each non-obvious decision was made*, with the failure mode that motivated it. **Read the
relevant section before changing that area, and update it in the same change when behaviour
changes.** The source comments carry the same rationale at close range and are held to the same
standard. The notes below are the operating context that README does not cover; do not duplicate
README content here.

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
in `intro`, no grades, groups, completion or idnumber. Its reason to exist is the pairing with
`mod_edpreset`: a note usually carries a `presetid` and is a **live view onto that preset's
`teacherguidance`**, so a curator rewording the exemplar updates every note already in every course.

README's *Technical details* covers each area in full. The four things below are the ones most
likely to be broken by a plausible-looking change, so check them before editing near them.

* **Students are kept out by a capability, not by visibility.** `mod/ednote:view` exists and omits
  the `student` archetype, so core's `is_user_access_restricted_by_capability()` makes the cm
  invisible before any plugin code runs. There is no second line of defence, and
  `moodle/course:viewhiddenactivities` deliberately does not override it. `view.php` and
  `ednote_pluginfile()` sit outside that check and re-check it explicitly.
  → README *Who can see a note*.
* **The note's text is resolved per request and never cached into modinfo.**
  `ednote_get_coursemodule_info()` deliberately sets no `$info->content`; only the fixed `presetid`
  goes into `customdata`. [classes/guidance.php](classes/guidance.php) resolves the whole course in
  one query per page (live guidance → the note's own `intro` snapshot → nothing), picking between two
  SQL variants because `edpreset_item` may not exist. Both branches yield **already-cleaned HTML**
  rendered unescaped — re-cleaning double-escapes. → README *Resolving the text*.
* **Removing a hidden note from the page takes two halves.** `set_user_visible(false)` in
  `ednote_cm_info_dynamic()` is enough for Snap but not for core's format, which gates on
  `uservisibleoncoursepage` — already derived before that callback runs. The other half is the
  `li.modtype_ednote:has(.ednote-is-hidden)` rule in [styles.css](styles.css), keyed on a marker
  `ednote_cm_info_view()` emits. → README *Removing a hidden note from the course page*.
* **A hidden note cannot be resolved through `require_login()`.** [hidden.php](hidden.php) and
  [classes/external/set_hidden.php](classes/external/set_hidden.php) use `get_coursemodule_from_id()`
  plus the **course** context, never `get_course_and_cm_from_cmid()`, which refuses a cm that is not
  user-visible — which is what a note becomes the moment it is hidden. Using it makes unhiding a
  one-way door. → README *Not resolving a hidden note through `require_login()`*.

Per-user hide state lives in **`core_favourites`** under component `mod_ednote`, in the **user's own
context**, under two scopes: `hiddennote` (keyed on cm id) and `hiddenguidance` (keyed on preset id).
Nothing in core cleans those rows up, so `ednote_delete_instance()` calls `hidden::purge_for_cm()`.
→ README *Per-user hiding*.

### Request flow

* [lib.php](lib.php) — the core callbacks above, plus `ednote_extend_navigation_course()`, which adds
  the *Hidden notes* link only when the user has something to restore.
* [classes/output/note.php](classes/output/note.php) → [templates/note.mustache](templates/note.mustache)
  — exports **both** states, guidance and the "you have hidden this" confirmation; the AMD module
  toggles between them, so undo needs no round trip and nothing is rendered client-side.
* [amd/src/note.js](amd/src/note.js) — one delegated listener, attached once per page (not per note).
  `try`/`catch` rather than a promise chain: `core/ajax` returns a jQuery Deferred with no
  `.finally()`, which would strand the `Pending` and leave the page permanently "not ready".
* [hidden.php](hidden.php) — lists and restores hidden notes; also the **no-JavaScript target** for
  the hide links, which are real sesskey-carrying links the AMD module intercepts opportunistically.
* [mod_form.php](mod_form.php) — no name field (derived from the body in `data_postprocessing()`,
  which forces the `validation()` override), and `hardFreeze('introeditor')` for preset notes.
  `presetid` is write-once: set on add, explicitly unset on update.
* [classes/privacy/provider.php](classes/privacy/provider.php) — notes are course content; the only
  personal data is the hide state, delegated to `core_favourites` in the user context.
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
