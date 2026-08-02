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

use core_favourites\service_factory;

/**
 * Which teacher notes the current user has chosen not to see.
 *
 * Hiding is always personal: one teacher tidying their view never changes what a colleague on the
 * same course sees. There are two scopes, and the difference is what the row is keyed on:
 *
 * - SCOPE_NOTE is keyed on the course module id, so it hides exactly this note.
 * - SCOPE_GUIDANCE is keyed on the preset id, so it hides every note carrying that guidance, in
 *   every course, including courses the user has not opened yet.
 *
 * Neither is keyed on the guidance text, which is what lets a curator reword a preset without
 * silently un-hiding it for everyone who had dismissed it.
 *
 * State lives in core_favourites rather than a table of this plugin's own, matching how
 * mod_edpreset stores starred presets. "Favourite" reads oddly for a negative flag, but the table
 * is core's general "this user has flagged this item" store and it brings a privacy story and a
 * bulk-read API with it.
 *
 * Rows are stored against the user's own context, not the note's module context, for both scopes.
 * They describe a preference of the user rather than a thing in a course, and it means unhiding
 * still works after the note itself has been deleted - context_module::instance() would throw.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hidden {
    /** @var string The favourites component these rows belong to. */
    public const COMPONENT = 'mod_ednote';

    /** @var string Hide one note, keyed on its course module id. */
    public const SCOPE_NOTE = 'hiddennote';

    /** @var string Hide one preset's guidance everywhere, keyed on the preset id. */
    public const SCOPE_GUIDANCE = 'hiddenguidance';

    /**
     * Hidden item ids, keyed by user id and then by scope.
     *
     * Keyed by user rather than just held for "the current user" because the user can change
     * within a request - "log in as" and cron both do it - and answering a question about the wrong
     * person's hidden notes would be invisible until someone noticed a note that would not go away.
     *
     * @var array<int, array<string, int[]>>
     */
    protected static $cache = [];

    /**
     * Every scope this class understands.
     *
     * @return string[]
     */
    public static function scopes(): array {
        return [self::SCOPE_NOTE, self::SCOPE_GUIDANCE];
    }

    /**
     * The current user's hidden item ids, keyed by scope.
     *
     * Read once per request and held, because ednote_cm_info_view() asks about every note on the
     * page and a course with a dozen presets must not cost a dozen queries.
     *
     * @return array<string, int[]>
     */
    public static function get_for_user(): array {
        global $USER;

        $userid = (int)($USER->id ?? 0);

        if (isset(self::$cache[$userid])) {
            return self::$cache[$userid];
        }

        // Nobody is logged in during cron, CLI or an anonymous page. There is no user context to
        // read favourites from, and no note is rendered to a guest anyway.
        if (!isloggedin() || isguestuser()) {
            return self::$cache[$userid] = array_fill_keys(self::scopes(), []);
        }

        $service = service_factory::get_service_for_user_context(\context_user::instance($userid));

        $hidden = [];
        foreach (self::scopes() as $scope) {
            $ids = [];
            foreach ($service->find_favourites_by_type(self::COMPONENT, $scope) as $favourite) {
                $ids[(int)$favourite->itemid] = true;
            }
            $hidden[$scope] = $ids;
        }

        return self::$cache[$userid] = $hidden;
    }

    /**
     * Whether the current user has hidden a note.
     *
     * @param int $cmid The note's course module id.
     * @param int $presetid The preset the note carries guidance for, or 0 for a standalone note.
     * @return bool
     */
    public static function is_hidden(int $cmid, int $presetid): bool {
        $hidden = self::get_for_user();

        if (isset($hidden[self::SCOPE_NOTE][$cmid])) {
            return true;
        }

        return $presetid > 0 && isset($hidden[self::SCOPE_GUIDANCE][$presetid]);
    }

    /**
     * Hide or unhide an item for the current user.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @param int $itemid The course module id or preset id, depending on the scope.
     * @param bool $hide True to hide, false to show again.
     * @throws \coding_exception If the scope is not one this class understands.
     */
    public static function set(string $scope, int $itemid, bool $hide): void {
        global $USER;

        if (!\in_array($scope, self::scopes(), true)) {
            throw new \coding_exception('Unknown teacher note hide scope: ' . $scope);
        }

        $usercontext = \context_user::instance($USER->id);
        $service = service_factory::get_service_for_user_context($usercontext);

        // Both calls are unguarded in core - create_favourite() inserts straight into a table with
        // a unique index, and delete_favourite() throws when there is nothing to delete - so a
        // double-click or a second browser tab would otherwise produce a 500.
        $exists = $service->favourite_exists(self::COMPONENT, $scope, $itemid, $usercontext);
        if ($hide && !$exists) {
            $service->create_favourite(self::COMPONENT, $scope, $itemid, $usercontext);
        } else if (!$hide && $exists) {
            $service->delete_favourite(self::COMPONENT, $scope, $itemid, $usercontext);
        }

        self::reset_cache();
    }

    /**
     * Drop every user's hidden-note row for a course module.
     *
     * Called when a note is deleted. Rows live in each user's own context, so nothing in core
     * cleans them up on our behalf; left alone they would accumulate one per deleted note per
     * teacher forever. Passing no context to delete_favourites_by_type_and_item() is what makes
     * this cross every user rather than only the one doing the deleting.
     *
     * @param int $cmid The course module id.
     */
    public static function purge_for_cm(int $cmid): void {
        service_factory::get_service_for_component(self::COMPONENT)
            ->delete_favourites_by_type_and_item(self::SCOPE_NOTE, $cmid);

        self::reset_cache();
    }

    /**
     * Forget what was read for this request.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
