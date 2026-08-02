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

namespace mod_ednote;

/**
 * Resolves the text a teacher note displays.
 *
 * A note carrying a presetid is a live view onto mod_edpreset's guidance rather than a copy of it,
 * so that editing the exemplar in the template course updates every note already sitting in a
 * course. That is why the text is resolved here, at render time, and never cached into
 * cached_cm_info by ednote_get_coursemodule_info(): modinfo would hand back last week's wording
 * until something happened to bump the course cache.
 *
 * The cost of that decision is a database read per course page, so it is paid once for the whole
 * page rather than once per note - see for_course().
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guidance {
    /** @var array<int, array<int, \stdClass>> Resolved rows, keyed by course id then cm id. */
    protected static $cache = [];

    /**
     * Every note in a course, with the text it should display.
     *
     * Deliberately a single direct query rather than a walk over get_fast_modinfo(): this is called
     * from ednote_cm_info_view(), which runs while the modinfo object for this very course is
     * mid-build, and re-entering it there is asking for trouble.
     *
     * @param int $courseid The course.
     * @return array<int, \stdClass> Keyed by cm id, each with ->presetid, ->content and ->missing.
     */
    public static function for_course(int $courseid): array {
        global $DB;

        if (isset(self::$cache[$courseid])) {
            return self::$cache[$courseid];
        }

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'ednote']);
        if (!$moduleid) {
            // The module row is gone, which happens mid-uninstall. Nothing to render.
            return self::$cache[$courseid] = [];
        }

        // mod_edpreset is an optional dependency, so its table cannot be joined unconditionally -
        // on a site without it the join would be to a table that does not exist.
        if (self::edpreset_is_installed()) {
            $sql = "SELECT cm.id AS cmid, e.presetid, e.intro, e.introformat, i.teacherguidance
                      FROM {ednote} e
                      JOIN {course_modules} cm ON cm.instance = e.id AND cm.module = :moduleid
                 LEFT JOIN {edpreset_item} i ON i.id = e.presetid
                     WHERE e.course = :courseid";
        } else {
            $sql = "SELECT cm.id AS cmid, e.presetid, e.intro, e.introformat, NULL AS teacherguidance
                      FROM {ednote} e
                      JOIN {course_modules} cm ON cm.instance = e.id AND cm.module = :moduleid
                     WHERE e.course = :courseid";
        }

        $notes = [];
        $records = $DB->get_records_sql($sql, ['moduleid' => $moduleid, 'courseid' => $courseid]);
        foreach ($records as $record) {
            $notes[(int)$record->cmid] = self::resolve($record);
        }

        return self::$cache[$courseid] = $notes;
    }

    /**
     * The text one note should display.
     *
     * @param int $courseid The course the note is in.
     * @param int $cmid The note's course module id.
     * @return \stdClass|null ->presetid, ->content and ->missing, or null if there is no such note.
     */
    public static function for_cm(int $courseid, int $cmid): ?\stdClass {
        return self::for_course($courseid)[$cmid] ?? null;
    }

    /**
     * Decide what a single row displays.
     *
     * Resolution order is live guidance, then the note's own stored copy, then nothing. The middle
     * step is what keeps a note readable after mod_edpreset is uninstalled or a preset is deleted:
     * emit_note() stores a snapshot at the moment the note is created precisely so that this
     * fallback has something to show.
     *
     * @param \stdClass $record A row from the query in for_course().
     * @return \stdClass ->presetid, ->content and ->missing.
     */
    protected static function resolve(\stdClass $record): \stdClass {
        $presetid = (int)$record->presetid;

        // Already cleaned HTML: mod_edpreset renders the curator's markdown once, at bake time.
        // Re-running format_text() here would double-escape entities the curator meant literally.
        $live = trim((string)($record->teacherguidance ?? ''));
        if ($presetid && $live !== '') {
            return (object)['presetid' => $presetid, 'content' => $live, 'missing' => false];
        }

        $snapshot = trim((string)($record->intro ?? ''));
        if ($snapshot !== '') {
            return (object)[
                'presetid' => $presetid,
                'content' => format_text($snapshot, (int)$record->introformat, ['context' => \context_system::instance()]),
                // A note that was linked to a preset but is now falling back to its snapshot is
                // showing text that may be out of date, and the teacher should be told so.
                'missing' => (bool)$presetid,
            ];
        }

        return (object)['presetid' => $presetid, 'content' => '', 'missing' => (bool)$presetid];
    }

    /**
     * Whether mod_edpreset is installed on this site.
     *
     * @return bool
     */
    public static function edpreset_is_installed(): bool {
        return class_exists('\mod_edpreset\preset');
    }

    /**
     * Forget everything resolved so far.
     *
     * Only tests need this; a request never outlives one page.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
