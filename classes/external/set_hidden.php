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

namespace mod_ednote\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ednote\hidden;

/**
 * Hide or unhide a teacher note for the current user.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_hidden extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The teacher note being acted on'),
            'scope' => new external_value(
                PARAM_ALPHA,
                'Either hiddennote for this note alone, or hiddenguidance for every note sharing its guidance'
            ),
            'hidden' => new external_value(PARAM_BOOL, 'Whether the note should be hidden'),
        ]);
    }

    /**
     * Hide or unhide.
     *
     * The note is always identified by its course module id, even for the guidance scope: the
     * caller is a course page that knows which note was clicked, and resolving the preset id here
     * rather than trusting one from the browser keeps the two scopes from being used to hide
     * arbitrary presets.
     *
     * @param int $cmid The teacher note.
     * @param string $scope One of mod_ednote\hidden's SCOPE_* constants.
     * @param bool $hidden Whether it should be hidden.
     * @return array
     */
    public static function execute(int $cmid, string $scope, bool $hidden): array {
        global $DB;

        ['cmid' => $cmid, 'scope' => $scope, 'hidden' => $hidden] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'scope' => $scope, 'hidden' => $hidden]
        );

        if (!\in_array($scope, hidden::scopes(), true)) {
            throw new \invalid_parameter_exception('Unknown teacher note hide scope: ' . $scope);
        }

        // Deliberately get_coursemodule_from_id() and the COURSE context, not
        // get_course_and_cm_from_cmid() and the module context. Both of those run the module
        // through require_login(), which refuses a cm that is not user-visible - and the moment a
        // teacher hides a note, ednote_cm_info_dynamic() makes it exactly that. Going through them
        // would let a note be hidden and then never unhidden by the person who hid it.
        $cm = get_coursemodule_from_id('ednote', $cmid, 0, false, MUST_EXIST);
        self::validate_context(\context_course::instance($cm->course));

        // Which means this is the real gate rather than a formality: it is what stops a student
        // quietly accumulating rows against notes they were never shown.
        $modulecontext = \context_module::instance($cm->id);
        require_capability('mod/ednote:view', $modulecontext);

        $itemid = $cmid;
        if ($scope === hidden::SCOPE_GUIDANCE) {
            $presetid = (int)$DB->get_field('ednote', 'presetid', ['id' => $cm->instance], MUST_EXIST);
            if ($presetid <= 0) {
                // A standalone note has no guidance to hide everywhere, so the wider scope has no
                // meaning. Fall back rather than write a row keyed on 0, which would match every
                // other standalone note.
                $scope = hidden::SCOPE_NOTE;
            } else {
                $itemid = $presetid;
            }
        }

        hidden::set($scope, $itemid, $hidden);

        return ['hidden' => $hidden, 'scope' => $scope];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hidden' => new external_value(PARAM_BOOL, 'Whether the note is now hidden'),
            'scope' => new external_value(PARAM_ALPHA, 'The scope actually applied'),
        ]);
    }
}
