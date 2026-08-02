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
 * Backup steps for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete teacher note structure for backup, with file annotations.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_ednote_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        // presetid is carried deliberately. It is not an id inside this course, so it is not
        // annotated and not remapped: it names a preset on the site, and a note restored into a
        // new course must still show that preset's current guidance. It is also the key the
        // "always hide this guidance" scope is stored against, so dropping it here would silently
        // un-hide guidance for everyone who had dismissed it.
        $ednote = new backup_nested_element('ednote', ['id'], [
            'name', 'intro', 'introformat', 'presetid', 'timemodified',
        ]);

        $ednote->set_source_table('ednote', ['id' => backup::VAR_ACTIVITYID]);

        $ednote->annotate_files('mod_ednote', 'intro', null);

        return $this->prepare_activity_structure($ednote);
    }
}
