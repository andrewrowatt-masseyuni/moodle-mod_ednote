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

namespace mod_ednote\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\metadata\provider as metadata_provider;
use mod_ednote\hidden;

/**
 * Privacy provider for the teacher note.
 *
 * The notes themselves are course content, not personal data - a note carrying preset guidance does
 * not even store the text it shows. What is personal is which notes a teacher has chosen to hide,
 * held in core_favourites in that user's own context under two item types.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describe what this plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link(
            'core_favourites',
            [],
            'privacy:metadata:favourites'
        );

        return $collection;
    }

    /**
     * The contexts holding data for a user.
     *
     * Hides live in the user's own context, so that is the only context this plugin ever returns.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        foreach (hidden::scopes() as $scope) {
            \core_favourites\privacy\provider::add_contexts_for_userid(
                $contextlist,
                $userid,
                hidden::COMPONENT,
                $scope
            );
        }

        return $contextlist;
    }

    /**
     * The users holding data in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_user) {
            return;
        }

        foreach (hidden::scopes() as $scope) {
            \core_favourites\privacy\provider::add_userids_for_context($userlist, $scope);
        }
    }

    /**
     * Export the hidden notes.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || $context->instanceid != $userid) {
                continue;
            }

            $favourites = $DB->get_records('favourite', [
                'userid' => $userid,
                'component' => hidden::COMPONENT,
            ]);

            $rows = [];
            foreach ($favourites as $favourite) {
                if (!\in_array($favourite->itemtype, hidden::scopes(), true)) {
                    continue;
                }

                $rows[] = [
                    'scope' => $favourite->itemtype,
                    'itemid' => (int)$favourite->itemid,
                    'timecreated' => transform::datetime($favourite->timecreated),
                ];
            }

            if ($rows) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:hidden', 'mod_ednote')],
                    (object)['hidden' => $rows]
                );
            }
        }
    }

    /**
     * Delete every user's hides in a context.
     *
     * @param context $context The context.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof \context_user) {
            return;
        }

        foreach (hidden::scopes() as $scope) {
            \core_favourites\privacy\provider::delete_favourites_for_all_users(
                $context,
                hidden::COMPONENT,
                $scope
            );
        }
    }

    /**
     * Delete one user's hides.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach (hidden::scopes() as $scope) {
            \core_favourites\privacy\provider::delete_favourites_for_user(
                $contextlist,
                hidden::COMPONENT,
                $scope
            );
        }
    }

    /**
     * Delete the hides of a set of users in a context.
     *
     * @param approved_userlist $userlist The approved userlist.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_user) {
            return;
        }

        foreach (hidden::scopes() as $scope) {
            \core_favourites\privacy\provider::delete_favourites_for_userlist($userlist, $scope);
        }
    }
}
