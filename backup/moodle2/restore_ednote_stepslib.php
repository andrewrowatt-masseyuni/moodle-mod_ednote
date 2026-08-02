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
 * Restore steps for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_ednote_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the paths to restore.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('ednote', '/activity/ednote');

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore one teacher note.
     *
     * @param array $data The parsed element.
     */
    protected function process_ednote($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();
        // Kept as it was backed up. A preset id names a preset on this site, not something inside
        // the course being restored, so there is nothing to remap; a restore onto a different site
        // simply finds no such preset and the note falls back to the text it carries.
        $data->presetid = (int)($data->presetid ?? 0);

        $newitemid = $DB->insert_record('ednote', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Attach the files that belong to the note body.
     */
    protected function after_execute() {
        $this->add_related_files('mod_ednote', 'intro', null);
    }
}
