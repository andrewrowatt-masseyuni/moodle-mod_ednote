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
 * A teacher note has no page of its own.
 *
 * FEATURE_NO_VIEW_LINK means nothing links here, but the URL is still reachable by hand and by
 * anything that builds module URLs generically, so send the visitor somewhere useful rather than
 * showing them an empty page.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$n = optional_param('n', 0, PARAM_INT);

if ($id) {
    [$course, $cm] = get_course_and_cm_from_cmid($id, 'ednote');
} else {
    [$course, $cm] = get_course_and_cm_from_instance($n, 'ednote');
}

require_login($course, true, $cm);

// The capability check that keeps students out of the note itself has to be repeated here: this URL
// is not gated by whatever made the note invisible on the course page.
require_capability('mod/ednote:view', context_module::instance($cm->id));

redirect(new moodle_url('/course/view.php', ['id' => $course->id]), '', null);
