# Teacher note (mod_ednote)

[![Moodle Plugin CI](https://github.com/andrewrowatt-masseyuni/moodle-mod_ednote/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/andrewrowatt-masseyuni/moodle-mod_ednote/actions/workflows/moodle-ci.yml)

Shows guidance on the course page to teaching staff only. A teacher note reads like a label, sits
inline between the activities, and is never visible to students.

A note can be written by hand, or it can carry the teacher guidance recorded against a preset in
`mod_edpreset` — in which case it is a live view onto that guidance, so rewording the preset updates
every note already sitting in every course. Each teacher can hide notes they have finished with,
either one at a time or all the notes carrying a particular piece of guidance, without affecting what
their colleagues see.

## Requirements

| | |
| --- | --- |
| Moodle | 4.5 (build 2024100700). `$plugin->supported` is pinned to `[405, 405]`. |
| PHP | 8.1 or later (the version CI runs against). |
| Database | Any Moodle-supported database. CI runs PostgreSQL 16. |

Nothing here depends on a deprecated API. The version pin exists because `mod_edpreset` is pinned —
the two are developed, tested and released as a pair.

### Related plugins

`mod_edpreset` is an optional companion, and this plugin's main producer: when a teacher copies a
preset that carries teacher guidance, `mod_edpreset` drops a teacher note above the new activity.
There is deliberately **no** `$plugin->dependencies` entry in either direction — the link is one-way
and soft. A note carrying a preset id renders that preset's guidance when `mod_edpreset` is
installed, and falls back to its own stored copy when it is not, so either plugin installs and works
on its own.

## Installation

1. Copy the plugin into `mod/ednote` in your Moodle installation:

   ```
   git clone git@github.com:andrewrowatt-masseyuni/moodle-mod_ednote.git mod/ednote
   ```

   Alternatively, download the ZIP and extract it so that `version.php` sits at `mod/ednote/version.php`.

2. Visit **Site administration → Notifications**, or run:

   ```
   php admin/cli/upgrade.php
   ```

3. Purge caches after the upgrade completes.

The plugin has no admin settings and nothing to configure. It works as soon as it is installed.

Who can read a note is decided entirely by the **View teacher notes** (`mod/ednote:view`) capability,
which is granted to the non-editing teacher, editing teacher and manager archetypes and withheld from
students. Before overriding it anywhere, read [Who can see a note](#who-can-see-a-note) — the omission
of `student` is the plugin's whole security model, and there is no second line of defence behind it.

## Usage

### Adding a note by hand

Add a **Teacher note** from the activity chooser and type the guidance into the **Teacher guidance**
field. There is no separate name field: as with a label, the body is the whole thing, and the name
stored for reports and the recycle bin is derived from the first part of the body.

The note appears inline in the section, prefixed with its own header so it is obvious what it is and
who can read it. It carries no "Hidden from students" badge, because it is not a hidden activity —
see [Who can see a note](#who-can-see-a-note).

### Notes that come from a preset

A note created by `mod_edpreset` carries a preset id, and shows that preset's **Teacher guidance**
rather than anything typed into the note. Editing the guidance in the template course updates every
note that carries it, everywhere, with no rebuild and no cache purge.

Opening such a note's settings form shows a notice saying so, and the body field is frozen. What is
left in it is a snapshot taken when the note was created, shown only if the preset or the whole
`mod_edpreset` plugin goes away — editing it would be editing something nobody can see. Which preset
a note points at is fixed when the note is created and cannot be changed from the form.

If the preset is later deleted, or `mod_edpreset` is uninstalled, the note falls back to that snapshot
and tells the reader the guidance is no longer available, so nobody acts on text that may be stale.

### Hiding a note

Every note offers **Hide this note**. A note carrying preset guidance also offers **Always hide this
guidance**, which hides every note showing that same guidance, in every course, including courses the
teacher has not opened yet.

Hiding is always personal — it never changes what a colleague on the same course sees.

Clicking either one does **not** make the note disappear from the page it was clicked on. The choice
is recorded immediately, and the note is gone on the next page load, but in the meantime the body is
replaced with a line saying what will happen and an **Undo** button. An accidental click is therefore
one click to recover from rather than a trip to another page.

Both controls are ordinary links and work with JavaScript turned off; they lead to the hidden notes
page, which performs the same action.

### Restoring a hidden note

A hidden note leaves no trace on the course page, so a **Hidden teacher notes** link appears in the
course navigation for any user who has something to restore. It lists the notes hidden in this course
and, separately, the guidance hidden everywhere, each with a **Show** button. The link is absent when
there is nothing to restore, rather than always present and usually empty.

Guidance hidden everywhere is listed even if none of it appears in this course — otherwise there would
be no course in which to find it. A preset that has since been deleted is still listed, under a
placeholder name, because its hide row is still there to undo.

## Technical details

### Plugin shape

The plugin is modelled on `mod_label`, which is the closest core analogue: no view page, body held in
`intro`, rendered inline on the course page rather than as an activity card.

| Feature | Value | Why |
| --- | --- | --- |
| `FEATURE_MOD_ARCHETYPE` | `MOD_ARCHETYPE_RESOURCE` | |
| `FEATURE_MOD_PURPOSE` | `MOD_PURPOSE_ADMINISTRATION` | |
| `FEATURE_NO_VIEW_LINK` | true | The note is the content; there is nothing to click through to. |
| `FEATURE_MOD_INTRO` | true | The body lives in `intro`. |
| `FEATURE_SHOW_DESCRIPTION` | **false** | See below. |
| `FEATURE_BACKUP_MOODLE2` | true | |
| Grades, groups, groupings, outcomes, completion, idnumber | false | |

`FEATURE_SHOW_DESCRIPTION` is false for the same reason as in `mod_label`: the body *is* the note, so
it is always on the course page and a "Display description" checkbox would be a setting that does
nothing. Saying true also makes `standard_intro_elements()` add that checkbox only when the course
format has a view page, so any form code touching it breaks under the single activity format.

[view.php](view.php) exists only because the URL remains reachable by hand and by anything that builds
module URLs generically. It re-checks the capability and redirects to the course page, rather than
rendering an empty page.

### Database

One table, `ednote`, holding the standard activity columns plus:

| Column | Role |
| --- | --- |
| `intro` / `introformat` | The note body. For a standalone note this is the whole story. For a note carrying a `presetid` it is only a fallback snapshot. |
| `presetid` | The `edpreset_item.id` this note shows guidance for, or `0` for a standalone note. Indexed. |

`presetid` is deliberately **not** a foreign key: `mod_edpreset` is an optional dependency and its
tables may not exist. It is also the key the "always hide this guidance" scope is stored against,
which is why it has to survive a course backup.

No user data is stored against a note, so `ednote_reset_userdata()` returns an empty array. The
per-user hide state is not note data — see [Per-user hiding](#per-user-hiding).

### Who can see a note

This is the entire mechanism that keeps students out, and the omission of `student` from the
`mod/ednote:view` archetypes in [db/access.php](db/access.php) is load-bearing rather than an
oversight.

`cm_info::is_user_access_restricted_by_capability()` in `lib/modinfolib.php` looks for a capability
named exactly `mod/<modname>:view`. If it exists and the user does not have it, the cm is marked not
user-visible and its availability info is cleared. That check runs from **inside**
`update_user_visible()`, before `uservisibleoncoursepage` is derived, so both flags come out false
together and the activity vanishes from the course page, the course index and navigation, in every
theme.

Two consequences are worth knowing before changing anything here:

* **There is no second line of defence.** Granting `mod/ednote:view` to students exposes every teacher
  note on the site. It is not the fix for a "teacher cannot see the note" report; fix the role that is
  missing the capability instead.
* **`moodle/course:viewhiddenactivities` does not override it**, unlike a hidden (`visible = 0`)
  activity. That is the point. A note stays `visible = 1`, so it carries no "Hidden from students"
  badge and cannot be exposed by the bulk *Show* action in course editing — a note is not something a
  teacher can reveal to students by accident.

Two paths sit outside that check and repeat it explicitly: [view.php](view.php), which is not gated by
whatever made the note invisible on the course page, and `ednote_pluginfile()`, without which a student
who guessed a URL could read the guidance one embedded image at a time.

### Resolving the text

[classes/guidance.php](classes/guidance.php) decides what a note displays, at render time, on every
request.

`ednote_get_coursemodule_info()` deliberately does **not** set `$info->content`, which is what
`mod_label` does. `cached_cm_info` is stored in modinfo, and a note's text is a live view onto
`mod_edpreset`'s guidance — caching it would show the curator's previous wording until something
happened to rebuild the course cache. Only the `presetid` goes into `customdata`, which is safe
because it is fixed when the note is created.

The cost of that decision is a database read per course page, so it is paid once for the whole page
rather than once per note: `guidance::for_course()` resolves every note in the course in one query and
holds the result for the request, and `for_cm()` reads from it. It is a direct `$DB` query rather than
a walk over `get_fast_modinfo()` because it is called from `ednote_cm_info_view()`, which runs while
the modinfo object for that very course is mid-build. `tests/guidance_test.php` asserts the query count
so a regression here is caught rather than merely noticed as a slow page.

`edpreset_item` cannot be joined blindly — on a site without `mod_edpreset` the join would be to a
table that does not exist — so `edpreset_is_installed()` (a `class_exists()` check) selects between two
SQL variants.

Each note then resolves in this order:

1. **Live guidance** — `edpreset_item.teacherguidance`, when the note has a `presetid` and the preset
   exists and has guidance.
2. **The snapshot** — the note's own `intro`, which `mod_edpreset` wrote at creation time precisely so
   this fallback has something to show. Formatted with `format_module_intro()` rather than a bare
   `format_text()`, so that `@@PLUGINFILE@@` placeholders in a hand-written note resolve against the
   module context and reach `ednote_pluginfile()`; formatting against the system context instead would
   render them as broken images.
3. **Nothing** — the note renders as if it were not there.

A note that has a `presetid` but fell through to step 2 or 3 is flagged as missing its preset, and the
template says so.

Both branches produce **already-cleaned HTML**, which the template emits unescaped: preset guidance is
rendered and cleaned once by `mod_edpreset`, at bake time, and a hand-written note by
`format_module_intro()`. Re-running `format_text()` over either would double-escape entities the
author meant literally.

### Per-user hiding

[classes/hidden.php](classes/hidden.php) owns the state. There are two scopes, and the difference is
only what the row is keyed on:

| Scope | Keyed on | Hides |
| --- | --- | --- |
| `hiddennote` | course module id | Exactly this note |
| `hiddenguidance` | preset id | Every note carrying that guidance, in every course |

Neither is keyed on the guidance *text*, which is what lets a curator reword a preset without silently
un-hiding it for everyone who had dismissed it.

State lives in **`core_favourites`** rather than a table of this plugin's own, matching how
`mod_edpreset` stores starred presets. "Favourite" reads oddly for a negative flag, but the table is
core's general "this user has flagged this item" store, and it brings a privacy story and a bulk-read
API with it.

Rows are stored against the **user's own context** for both scopes, not the note's module context.
They describe a preference of the user rather than a thing in a course, and it means unhiding still
works after the note itself has been deleted, where `context_module::instance()` would throw. The
per-request cache is keyed by user id, because the user can change within a request — "log in as" and
cron both do it — and answering a question about the wrong person's hidden notes would stay invisible
until someone noticed a note that would not go away.

Core's `create_favourite()` inserts straight into a table with a unique index, and
`delete_favourite()` throws when there is nothing to delete, so `hidden::set()` checks first: a
double-click or a second browser tab would otherwise produce a 500.

Nothing in core cleans these rows up, because they live in each user's context rather than the
module's. `ednote_delete_instance()` therefore calls `hidden::purge_for_cm()` before deleting the
record — it needs the record to find the cm — passing no context, which is what makes the purge cross
every user rather than only the one doing the deleting. Left alone the rows would accumulate one per
deleted note per teacher, forever.

### Removing a hidden note from the course page

This takes two halves, and knowing why is the difference between a working change and a note that
will not go away in one theme.

`ednote_cm_info_dynamic()` calls `set_user_visible(false)`. That is enough for `theme_snap`, whose
course renderer returns early on `!$mod->uservisible`. It is **not** enough for core's course format,
which gates on `is_visible_on_course_page()`, i.e. `uservisibleoncoursepage` — a flag
`set_user_visible()` does not touch, because this callback runs *after* `update_user_visible()` has
already derived it.

`set_available(false, …)` would recompute both flags, but it is useless here: teachers and editing
teachers hold `moodle/course:ignoreavailabilityrestrictions` by default, so it has no effect on
exactly the people a hide is for.

The other half is a CSS rule. `ednote_cm_info_view()` emits a marker and nothing else for a hidden or
empty note, and [styles.css](styles.css) removes the whole course-page wrapper with
`li.modtype_ednote:has(.ednote-is-hidden)`. That wrapper is markup this plugin does not own and cannot
suppress from PHP; an empty one reads as a broken activity. Both themes put `modtype_ednote` on it —
core in `course/format/templates/local/content/section/cmitem.mustache`, Snap in its own
`course_renderer.php`.

Doing it in CSS is safe **because it is cosmetic**. A teacher seeing a note they dismissed is a
nuisance; students never reach this markup at all, because `mod/ednote:view` has already made the cm
invisible to them in core. The AMD module also removes these markers on load, for browsers without
`:has()`, where the alternative is an empty activity card with no explanation.

### Not resolving a hidden note through `require_login()`

The moment a teacher hides a note, `ednote_cm_info_dynamic()` makes it not user-visible. Both
[hidden.php](hidden.php) and [classes/external/set_hidden.php](classes/external/set_hidden.php)
therefore resolve it with `get_coursemodule_from_id()` against the **course** context, never with
`get_course_and_cm_from_cmid()`, which runs the module through `require_login()` and refuses a cm that
is not user-visible.

Using the convenient function would make hiding a one-way door: the *Show* button on the hidden notes
page would fail for every note it lists, and undo would fail for the person who had just clicked hide.
`require_capability('mod/ednote:view', …)` on the module context is then the real gate rather than a
formality — it is what stops a student quietly accumulating rows against notes they were never shown,
and it is checked per module because a role override can differ per activity.

### The settings form

A note has no separate title, so `name` is a hidden element derived from the body in
`data_postprocessing()`, the way `mod_label` does it. Being hidden is exactly why `validation()` has
to be overridden: `moodleform_mod::validation()` rejects an empty name for any form where the element
exists, the name is still empty at that point, and an error attached to a hidden element **renders
nothing** — the form would simply come back with no explanation. The override drops that one error.

`FEATURE_SHOW_DESCRIPTION` is false, so `standard_intro_elements()` adds no checkbox for it and the
form supplies `showdescription` itself.

For a note carrying a preset id, `introeditor` is `hardFreeze()`d — after `standard_intro_elements()`
has created it — under a notice explaining that the preset supplies the text. Freezing also drops the
element's rules, which is wanted: `$CFG->requiremodintro` would otherwise demand a value for a field
there is no longer any way to type into. A frozen element submits nothing, and
`MoodleQuickForm::exportValues()` falls back to the default that `get_moduleinfo_data()` prepared from
the stored intro, so the snapshot and its files survive a save unchanged.

A frozen editor renders as bare formatted text where the textarea was, which reads as loose page copy
rather than as a field somebody has been locked out of, so the form item is marked for
[styles.css](styles.css) to give it back the shape of a read-only control.

`presetid` is write-once: set in `ednote_add_instance()` and explicitly unset in
`ednote_update_instance()`. Re-pointing an existing note at a different preset is not something the
settings form offers.

### Course page JavaScript

[amd/src/note.js](amd/src/note.js) is attached once per page in `ednote_cm_info_view()`, not once per
note, and guarded against the contexts where `cm_info` is built with no page to attach requirements
to — cron, CLI and web service calls. It uses one delegated click listener for the whole page.

[classes/output/note.php](classes/output/note.php) exports **both** states — the guidance and the
"you have hidden this" confirmation — and [templates/note.mustache](templates/note.mustache) renders
both. The module swaps between them by toggling one attribute, so undo needs no round trip and this
plugin renders nothing client-side.

The hide controls are real links to `hidden.php` carrying a sesskey, so they work with JavaScript off;
the module calls `preventDefault()` only once it knows it can finish the job itself. The server decides
the scope actually applied and returns it, because asking to hide the guidance of a note that has no
preset falls back to hiding just that note, and undo has to reverse what was stored rather than what
was clicked.

`applyHidden()` is written with `try`/`catch` rather than a promise chain because `core/ajax` hands
back a jQuery Deferred, not a native promise: it has `.then()`, `.catch()` and `.always()`, but no
`.finally()`, and calling one would leave the `Pending` unresolved and the page permanently "not
ready" for Behat.

### Web service

`mod_ednote_set_hidden` is flagged `ajax => true` and put in **no service**: it is called by the
course page's own JavaScript as the logged-in user, never by an external client with a token.

The note is always identified by its course module id, even for the guidance scope. Resolving the
preset id server-side from the cm, rather than trusting one from the browser, is what keeps the two
scopes from being used to hide arbitrary presets. A standalone note asking for the guidance scope
falls back to the note scope rather than writing a row keyed on `0`, which would match every other
standalone note.

### Styling and themes

All styling hangs off classes in the markup this plugin emits, rather than on the course page's cm
wrapper: `theme_snap` does not render the extra classes that `set_extra_classes()` would set, so the
only element this plugin can reliably style is the one it writes itself.

The note is given a "quiet strip" treatment — no fill, just a teal rule down its left edge — so it
reads as a low-weight teacher aside between the activity cards rather than competing with them.
[styles.css](styles.css) also carries a small number of Snap-specific rules, including suppressing
Snap's activity-type prefix, which duplicates what the note's own header already says.

### Files

Images pasted into a hand-written note live in the standard `intro` file area and are served by
`ednote_pluginfile()`, which serves only that area and only to users with `mod/ednote:view`. Preset
guidance carries its own files, served by `mod_edpreset`.

### Backup and restore

The note backs up and restores like any other activity, with its `intro` files annotated.

`presetid` is carried deliberately, and is **not** annotated and **not** remapped. It names a preset on
the site rather than something inside the course being backed up, so there is nothing to remap; a
restore onto a different site simply finds no such preset, and the note falls back to the snapshot
text it carries. Carrying it matters because it is also the key the "always hide this guidance" scope
is stored against.

Per-user hide state is not backed up. It lives in `core_favourites` in each user's own context and is
a preference of that user rather than course content.

### Privacy

The notes themselves are course content, not personal data — a note carrying preset guidance does not
even store the text it shows. What is personal is which notes a teacher has chosen to hide, held in
`core_favourites` in that user's own context under the two item types.

`privacy\provider` therefore declares a single `core_favourites` subsystem link and implements the
metadata, plugin and userlist providers. Hides live in the user's own context, so that is the only
context it ever returns, exports into, or deletes from; every entry point returns early for anything
that is not a `context_user`. Deletion is delegated to `core_favourites`' own provider for both scopes.

## Testing

The plugin ships PHPUnit coverage for the text resolver, the hide state, the library callbacks and the
external function, plus a test data generator.

```
vendor/bin/phpunit --testsuite mod_ednote_testsuite
```

Two things about the tests are worth knowing before adding to them:

* **Both static caches must be reset in `setUp()`.** `guidance::$cache` and `hidden::$cache` are keyed
  on ids that PHPUnit reuses between tests, because `resetAfterTest()` rolls the sequences back too.
  Without the reset, a test reads the previous test's answer.
* **`test_a_course_costs_one_query()` needs live guidance**, so it needs `mod_edpreset`. A note that
  falls back to its snapshot is formatted by `format_module_intro()`, whose own reads are per-note by
  nature and would swamp the count the test is trying to take.

Behat covers the add and edit flows, the student and non-editing-teacher views, the absence of the
"Hidden from students" badge, and the full hide/undo/restore cycle, in `tests/behat/basic.feature`.
`tests/behat/behat_mod_ednote.php` resolves the `"mod_ednote > hidden notes"` page straight to its URL
rather than clicking the navigation node, because whether that node is directly clickable depends on
how much secondary navigation fits at the current window size — going via the URL keeps the tests about
this plugin rather than about nav overflow.

CI (`.github/workflows/moodle-ci.yml`) runs the full `moodle-plugin-ci` set against Moodle 4.5 on PHP
8.1 and PostgreSQL 16: phplint, phpmd, phpcs, phpdoc, validate, savepoints, mustache lint, grunt,
PHPUnit and Behat.

## License

GNU GPL v3 or later — see [LICENSE](LICENSE).

## Author

Andrew Rowatt &lt;A.J.Rowatt@massey.ac.nz&gt;, Massey University.
