<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_ednote\guidance;
use mod_ednote\hidden;

/**
 * Declare which core features the teacher note supports.
 *
 * Modelled on mod_label, which is the closest core analogue: no view page, body held in intro,
 * rendered inline on the course page rather than as an activity card.
 *
 * @param string $feature FEATURE_* constant.
 * @return mixed True, false, a value, or null when core should decide.
 */
function ednote_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_RESOURCE,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ADMINISTRATION,
        FEATURE_NO_VIEW_LINK => true,
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => false,
        FEATURE_COMPLETION_HAS_RULES => false,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_IDNUMBER => false,
        default => null,
    };
}

/**
 * Add a teacher note.
 *
 * @param stdClass $note The submitted form data.
 * @return int The new instance id.
 */
function ednote_add_instance($note) {
    global $DB;

    $note->timemodified = time();
    $note->presetid = (int)($note->presetid ?? 0);

    return $DB->insert_record('ednote', $note);
}

/**
 * Update a teacher note.
 *
 * @param stdClass $note The submitted form data.
 * @return bool
 */
function ednote_update_instance($note) {
    global $DB;

    $note->id = $note->instance;
    $note->timemodified = time();
    // Deliberately not read from the form: presetid is set once, by whoever created the note, and
    // re-pointing an existing note at a different preset is not something the settings form offers.
    unset($note->presetid);

    return $DB->update_record('ednote', $note);
}

/**
 * Delete a teacher note.
 *
 * @param int $id The instance id.
 * @return bool
 */
function ednote_delete_instance($id) {
    global $DB;

    if (!$note = $DB->get_record('ednote', ['id' => $id])) {
        return false;
    }

    // Before the record goes: get_coursemodule_from_instance() needs it to find the cm, and the
    // per-user "I have hidden this note" rows are keyed on that cm id. Nothing in core clears them,
    // because they live in each user's own context rather than the module's.
    $cm = get_coursemodule_from_instance('ednote', $note->id, $note->course, false, IGNORE_MISSING);
    if ($cm) {
        hidden::purge_for_cm((int)$cm->id);
    }

    $DB->delete_records('ednote', ['id' => $note->id]);

    return true;
}

/**
 * No user data is ever stored against a teacher note.
 *
 * @param stdClass $data The reset form data.
 * @return array
 */
function ednote_reset_userdata($data) {
    return [];
}

/**
 * Supply the course page with the note's name.
 *
 * Deliberately does NOT set $info->content, which is what mod_label does. Content is cached in
 * modinfo, and a note's text is a live view onto mod_edpreset's guidance - caching it would show
 * the curator's previous wording until something happened to rebuild the course cache. The text is
 * resolved instead in ednote_cm_info_view(), which runs per request.
 *
 * @param stdClass $coursemodule The course module record.
 * @return cached_cm_info|null
 */
function ednote_get_coursemodule_info($coursemodule) {
    global $DB;

    $note = $DB->get_record('ednote', ['id' => $coursemodule->instance], 'id, name, presetid');
    if (!$note) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $note->name;
    // Safe to cache: which preset a note points at is fixed when the note is created.
    $info->customdata = ['presetid' => (int)$note->presetid];

    return $info;
}

/**
 * Hide the note from users who have dismissed it.
 *
 * Only half the job, and the half that Snap honours: Snap's course renderer returns early on
 * !$mod->uservisible, so this alone removes the note there. Core's course format does not - it
 * gates on is_visible_on_course_page(), i.e. uservisibleoncoursepage, which set_user_visible() does
 * not touch because this callback runs AFTER update_user_visible() has already derived it. The
 * other half is a CSS rule in styles.css keyed on the marker ednote_cm_info_view() emits.
 *
 * set_available(false, ...) would recompute both flags, but it is useless here: teachers and
 * editing teachers hold moodle/course:ignoreavailabilityrestrictions by default, so it has no
 * effect on exactly the people a hide is for.
 *
 * Note this is about a teacher's own dismissal, never about students - students are kept out by
 * the mod/ednote:view capability, in core, well before any of this runs.
 *
 * @param cm_info $cm The course module.
 */
function ednote_cm_info_dynamic(cm_info $cm) {
    $presetid = (int)($cm->customdata['presetid'] ?? 0);

    if (hidden::is_hidden((int)$cm->id, $presetid)) {
        $cm->set_user_visible(false);
    }
}

/**
 * Render the note onto the course page.
 *
 * @param cm_info $cm The course module.
 */
function ednote_cm_info_view(cm_info $cm) {
    global $PAGE;

    $presetid = (int)($cm->customdata['presetid'] ?? 0);

    // Rendered as a card rather than an activity link, like a label.
    $cm->set_custom_cmlist_item(true);

    $note = guidance::for_cm((int)$cm->course, (int)$cm->id);

    // Nothing to say, or the viewer has said they do not want to hear it. Either way emit the
    // marker and nothing else: the CSS rule on li.modtype_ednote:has(.ednote-is-hidden) removes the
    // whole course-page wrapper, which is markup this plugin does not own and cannot suppress from
    // here, and an empty wrapper reads as a broken activity.
    //
    // Using CSS for this is deliberate and safe, because it is cosmetic. A teacher seeing a note
    // they dismissed is a nuisance; students never reach this code at all, because mod/ednote:view
    // has already made the cm invisible to them in core.
    $empty = !$note || ($note->content === '' && !$note->missing);
    if ($empty || hidden::is_hidden((int)$cm->id, $presetid)) {
        $cm->set_content(html_writer::span('', 'ednote-is-hidden', ['hidden' => 'hidden']), true);
        return;
    }

    $cm->set_content(ednote_render_card($cm, $note), true);

    // Once per page, not once per note. Guarded against the contexts where cm_info is built with no
    // page to attach requirements to - cron, CLI, web service calls.
    static $jsdone = false;
    if (!$jsdone && !CLI_SCRIPT && !empty($PAGE)) {
        $jsdone = true;
        $PAGE->requires->js_call_amd('mod_ednote/note', 'init');
    }
}

/**
 * Build the note's markup.
 *
 * Both the body and the "you have hidden this" confirmation are rendered here, the latter hidden.
 * The AMD module swaps which one is shown, so undo restores the guidance without a round trip and
 * without this plugin having to re-render anything client side.
 *
 * @param cm_info $cm The course module.
 * @param stdClass $note The resolved guidance, from mod_ednote\guidance.
 * @return string HTML.
 */
function ednote_render_card(cm_info $cm, stdClass $note): string {
    global $OUTPUT;

    $body = $note->content;
    if ($note->missing) {
        $body = $OUTPUT->notification(get_string('guidancemissing', 'mod_ednote'), 'info', false) . $body;
    }

    $header = html_writer::div(
        html_writer::span(get_string('title', 'mod_ednote'), 'ednote-card-title')
        . html_writer::div(ednote_hide_menu($cm, (int)$note->presetid), 'ednote-card-actions'),
        'ednote-card-header'
    );

    // Rendered up front and hidden, so that undo can put the guidance back by toggling one
    // attribute rather than re-fetching and re-rendering it.
    $dismissed = html_writer::div(
        get_string('dismissed', 'mod_ednote')
        . ' '
        . get_string('dismissedundo', 'mod_ednote')
        . ' '
        . html_writer::tag('button', get_string('undo', 'mod_ednote'), [
            'type' => 'button',
            'class' => 'btn btn-link p-0 align-baseline',
            'data-action' => 'ednote-undo',
        ]),
        'ednote-card-dismissed',
        ['data-region' => 'ednote-dismissed', 'hidden' => 'hidden']
    );

    return html_writer::div(
        $header
        . html_writer::div($body, 'ednote-card-body', ['data-region' => 'ednote-body'])
        . $dismissed,
        'ednote-card',
        [
            'data-region' => 'ednote',
            'data-cmid' => (int)$cm->id,
            'data-presetid' => (int)$note->presetid,
        ]
    );
}

/**
 * The hide menu shown on a note.
 *
 * The items are real links to hidden.php rather than dead '#' anchors: the AMD module intercepts
 * them and swaps the card in place, but without JavaScript they still hide the note and come back
 * to the course page.
 *
 * @param cm_info $cm The course module.
 * @param int $presetid The preset the note carries guidance for, or 0.
 * @return string HTML.
 */
function ednote_hide_menu(cm_info $cm, int $presetid): string {
    global $OUTPUT;

    $menu = new \core\output\action_menu();
    $menu->set_kebab_trigger();
    $menu->set_menu_left();

    $menu->add(new \core\output\action_menu\link_secondary(
        ednote_action_url((int)$cm->course, hidden::SCOPE_NOTE, (int)$cm->id, true),
        null,
        get_string('hidethisnote', 'mod_ednote'),
        ['data-action' => 'ednote-hide', 'data-scope' => hidden::SCOPE_NOTE]
    ));

    // Only offered when there is a preset to hide everywhere. A standalone note has no identity
    // beyond itself, so "always hide this guidance" would mean the same as "hide this note".
    if ($presetid > 0) {
        $menu->add(new \core\output\action_menu\link_secondary(
            ednote_action_url((int)$cm->course, hidden::SCOPE_GUIDANCE, $presetid, true),
            null,
            get_string('hidethisguidance', 'mod_ednote'),
            ['data-action' => 'ednote-hide', 'data-scope' => hidden::SCOPE_GUIDANCE]
        ));
    }

    return $OUTPUT->render($menu);
}

/**
 * A hidden.php link that hides or shows one item.
 *
 * @param int $courseid The course to return to.
 * @param string $scope One of mod_ednote\hidden's SCOPE_* constants.
 * @param int $itemid The course module id or preset id, depending on the scope.
 * @param bool $hide True to hide, false to show again.
 * @return moodle_url
 */
function ednote_action_url(int $courseid, string $scope, int $itemid, bool $hide): moodle_url {
    return new moodle_url('/mod/ednote/hidden.php', [
        'course' => $courseid,
        'scope' => $scope,
        'item' => $itemid,
        'hide' => $hide ? 1 : 0,
        'sesskey' => sesskey(),
    ]);
}

/**
 * Serve files embedded in a note's body.
 *
 * @param stdClass $course The course.
 * @param stdClass $cm The course module.
 * @param context $context The module context.
 * @param string $filearea The file area.
 * @param array $args The remaining path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options.
 * @return bool False when the file cannot be served.
 */
function ednote_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG;

    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'intro') {
        return false;
    }

    require_course_login($course, true, $cm);

    // The same capability that decides whether the note is visible at all decides whether its
    // images are. Without this a student who guessed a URL could read the guidance one picture at
    // a time.
    if (!has_capability('mod/ednote:view', $context)) {
        return false;
    }

    require_once($CFG->libdir . '/filelib.php');

    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_ednote/$filearea/$relativepath";

    $fs = get_file_storage();
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Add a link to the hidden notes page, for users who have hidden something in this course.
 *
 * Only shown when there is something to restore: a note that has vanished leaves no trace on the
 * course page, so without this there would be no way back, but an always-present link to an
 * always-empty page is just noise.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function ednote_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('mod/ednote:view', $context)) {
        return;
    }

    if (!\mod_ednote\output\hidden_page::has_hidden_notes((int)$course->id)) {
        return;
    }

    $navigation->add(
        get_string('hidden', 'mod_ednote'),
        new moodle_url('/mod/ednote/hidden.php', ['course' => (int)$course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'ednotehidden',
        new pix_icon('monologo', '', 'mod_ednote')
    );
}
