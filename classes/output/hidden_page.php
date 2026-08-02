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

namespace mod_ednote\output;

use mod_ednote\hidden;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The list of teacher notes the current user has hidden.
 *
 * A hidden note leaves nothing behind on the course page, so this is the only way back. It covers
 * both scopes: notes hidden in this course, and guidance hidden everywhere.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hidden_page implements renderable, templatable {
    /** @var stdClass The course being listed. */
    protected $course;

    /**
     * Constructor.
     *
     * @param stdClass $course The course being listed.
     */
    public function __construct(stdClass $course) {
        $this->course = $course;
    }

    /**
     * Whether the user has hidden anything relevant to a course.
     *
     * Used to decide whether the course navigation link is worth showing. Guidance hidden
     * everywhere counts, even when none of it is in this course: it is the only place the user can
     * undo that, and it should not become unreachable just because they moved on to a new course.
     *
     * @param int $courseid The course.
     * @return bool
     */
    public static function has_hidden_notes(int $courseid): bool {
        $state = hidden::get_for_user();

        if (!empty($state[hidden::SCOPE_GUIDANCE])) {
            return true;
        }

        return self::hidden_notes_in_course($courseid) !== [];
    }

    /**
     * The notes hidden by cm id that are actually in a course.
     *
     * The stored rows are just cm ids, with no course attached, so they have to be filtered against
     * the course's own modules - and against the ones that still exist, since a note the teacher
     * later deleted leaves its hide row behind until something clears it.
     *
     * @param int $courseid The course.
     * @return \cm_info[] Keyed by cm id.
     */
    protected static function hidden_notes_in_course(int $courseid): array {
        $state = hidden::get_for_user();
        if (empty($state[hidden::SCOPE_NOTE])) {
            return [];
        }

        $found = [];
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_instances_of('ednote') as $cm) {
            if (isset($state[hidden::SCOPE_NOTE][(int)$cm->id])) {
                $found[(int)$cm->id] = $cm;
            }
        }

        return $found;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $data->courseurl = (new \moodle_url('/course/view.php', ['id' => (int)$this->course->id]))->out(false);
        $data->notes = $this->export_notes();
        $data->guidance = $this->export_guidance();
        $data->hasnotes = $data->notes !== [];
        $data->hasguidance = $data->guidance !== [];
        $data->hasany = $data->hasnotes || $data->hasguidance;

        return $data;
    }

    /**
     * Notes hidden individually in this course.
     *
     * @return stdClass[]
     */
    protected function export_notes(): array {
        $courseid = (int)$this->course->id;

        $rows = [];
        foreach (self::hidden_notes_in_course($courseid) as $cm) {
            $rows[] = (object)[
                'name' => $cm->get_formatted_name(),
                'showurl' => ednote_action_url($courseid, hidden::SCOPE_NOTE, (int)$cm->id, false)->out(false),
            ];
        }

        return $rows;
    }

    /**
     * Guidance hidden everywhere, whether or not it appears in this course.
     *
     * @return stdClass[]
     */
    protected function export_guidance(): array {
        $state = hidden::get_for_user();
        $presetids = array_keys($state[hidden::SCOPE_GUIDANCE] ?? []);
        if ($presetids === []) {
            return [];
        }

        $titles = $this->preset_titles($presetids);

        $rows = [];
        foreach ($presetids as $presetid) {
            $rows[] = (object)[
                // A preset that has since been deleted still has a row to undo, so it needs a name
                // to show against rather than an empty cell.
                'name' => $titles[$presetid] ?? get_string('guidancemissing', 'mod_ednote'),
                'showurl' => ednote_action_url(
                    (int)$this->course->id,
                    hidden::SCOPE_GUIDANCE,
                    $presetid,
                    false
                )->out(false),
            ];
        }

        return $rows;
    }

    /**
     * Look up preset titles, if mod_edpreset is installed.
     *
     * @param int[] $presetids The presets to name.
     * @return array<int, string> Keyed by preset id.
     */
    protected function preset_titles(array $presetids): array {
        global $DB;

        if (!\mod_ednote\guidance::edpreset_is_installed()) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($presetids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select('edpreset_item', "id $insql", $params, '', 'id, title');

        $titles = [];
        foreach ($records as $record) {
            $titles[(int)$record->id] = format_string($record->title);
        }

        return $titles;
    }
}
