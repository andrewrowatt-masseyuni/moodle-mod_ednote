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
 * The teacher notes the current user has hidden, and the way to show them again.
 *
 * Also the no-JavaScript target for the hide links on the notes themselves, which is why it takes
 * an action as well as rendering a list. The course page's AMD module intercepts those links, so
 * in a normal browser this page is only ever reached deliberately.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ednote\hidden;
use mod_ednote\output\hidden_page;

$courseid = required_param('course', PARAM_INT);
$scope = optional_param('scope', '', PARAM_ALPHA);
$itemid = optional_param('item', 0, PARAM_INT);
$hide = optional_param('hide', -1, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

$coursecontext = context_course::instance($course->id);

// Everything on this page is about notes, so someone who cannot see notes has nothing to do here.
require_capability('mod/ednote:view', $coursecontext);

$url = new moodle_url('/mod/ednote/hidden.php', ['course' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_course($course);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('hidden', 'mod_ednote'));
$PAGE->set_heading(format_string($course->fullname));

if ($scope !== '' && $hide >= 0) {
    require_sesskey();

    if (!in_array($scope, hidden::scopes(), true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }

    // A note is only hideable by someone who can see it, and a role override can differ per
    // activity, so the module context is what to check.
    //
    // Resolved with get_coursemodule_from_id() rather than get_course_and_cm_from_cmid() for the
    // same reason as the web service: the latter refuses a cm that is not user-visible, and a note
    // this user has already hidden is precisely that. Using it here would make the Show button on
    // this page fail for every note it lists.
    if ($scope === hidden::SCOPE_NOTE) {
        $cm = get_coursemodule_from_id('ednote', $itemid, $course->id, false, MUST_EXIST);
        require_capability('mod/ednote:view', context_module::instance($cm->id));
    }

    hidden::set($scope, $itemid, (bool)$hide);

    // Back where the click came from. Hiding is done from the course page, showing from this one.
    redirect($hide ? new moodle_url('/course/view.php', ['id' => $course->id]) : $url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('hidden', 'mod_ednote'));
echo $OUTPUT->render_from_template('mod_ednote/hidden_page', (new hidden_page($course))->export_for_template($OUTPUT));
echo $OUTPUT->footer();
