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

use cm_info;
use mod_ednote\hidden;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * One teacher note, as it appears on the course page.
 *
 * Both states are exported together - the guidance and the "you have hidden this" confirmation -
 * because the AMD module swaps between them by toggling one attribute. Undo therefore restores the
 * guidance without a round trip, and without this plugin rendering anything client side.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class note implements renderable, templatable {
    /** @var cm_info The note's course module. */
    protected $cm;

    /** @var stdClass The resolved guidance, from mod_ednote\guidance. */
    protected $note;

    /**
     * Constructor.
     *
     * @param cm_info $cm The note's course module.
     * @param stdClass $note The resolved guidance, with ->content, ->presetid and ->missing.
     */
    public function __construct(cm_info $cm, stdClass $note) {
        $this->cm = $cm;
        $this->note = $note;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $courseid = (int)$this->cm->course;
        $cmid = (int)$this->cm->id;
        $presetid = (int)$this->note->presetid;

        $data = new stdClass();
        $data->cmid = $cmid;
        $data->presetid = $presetid;
        // Already cleaned: preset guidance was rendered at bake time, a hand-written note by
        // format_module_intro(). The template emits it unescaped.
        $data->body = $this->note->content;
        $data->missing = (bool)$this->note->missing;

        $data->hidenoteurl = hidden::action_url($courseid, hidden::SCOPE_NOTE, $cmid, true)->out(false);
        $data->hidenotescope = hidden::SCOPE_NOTE;

        // Only offered when there is a preset to hide everywhere. A standalone note has no identity
        // beyond itself, so "always hide this guidance" would mean the same as "hide this note".
        $data->haspreset = $presetid > 0;
        if ($data->haspreset) {
            $data->hideguidanceurl = hidden::action_url(
                $courseid,
                hidden::SCOPE_GUIDANCE,
                $presetid,
                true
            )->out(false);
            $data->hideguidancescope = hidden::SCOPE_GUIDANCE;
        }

        return $data;
    }
}
