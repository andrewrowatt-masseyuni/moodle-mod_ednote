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
            'scope' => new external_value(PARAM_ALPHA, 'hiddennote for this note, hiddenguidance for every note with the same guidance'),
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
        ['cmid' => $cmid, 'scope' => $scope, 'hidden' => $hidden] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'scope' => $scope, 'hidden' => $hidden]
        );

        if (!\in_array($scope, hidden::scopes(), true)) {
            throw new \invalid_parameter_exception('Unknown teacher note hide scope: ' . $scope);
        }

        [, $cm] = get_course_and_cm_from_cmid($cmid, 'ednote');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // You can only hide a note you can see. This is also what stops a student from quietly
        // accumulating rows against notes they were never shown.
        require_capability('mod/ednote:view', $context);

        $itemid = $cmid;
        if ($scope === hidden::SCOPE_GUIDANCE) {
            $presetid = (int)($cm->customdata['presetid'] ?? 0);
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
