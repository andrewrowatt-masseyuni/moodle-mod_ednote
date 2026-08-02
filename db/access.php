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
 * Capabilities for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'mod/ednote:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    /*
     * This capability is the whole reason students never see a teacher note, and the omission of
     * 'student' below is load-bearing rather than an oversight.
     *
     * cm_info::is_user_access_restricted_by_capability() (lib/modinfolib.php, ~line 2683) looks for
     * a capability named exactly 'mod/<modname>:view'. If it exists and the user does not have it,
     * the cm is marked not user-visible and its availability info is cleared. That check runs from
     * INSIDE update_user_visible(), before uservisibleoncoursepage is derived, so both flags come
     * out false together and the activity vanishes from the course page, the course index and
     * navigation in every theme.
     *
     * Two consequences worth knowing before editing this:
     *
     * - Granting this to 'student' exposes every teacher note on the site to students. There is no
     *   second line of defence. Do not do it to fix a "teacher cannot see the note" report; fix the
     *   role that is missing the capability instead.
     * - Unlike a hidden (visible = 0) activity, moodle/course:viewhiddenactivities does NOT override
     *   this. That is the point: a note stays visible = 1, so it carries no "Hidden from students"
     *   badge and cannot be exposed by the bulk Show action in course editing.
     */
    'mod/ednote:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
