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
 * Restore task for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/ednote/backup/moodle2/restore_ednote_stepslib.php');

/**
 * Restore task for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_ednote_activity_task extends restore_activity_task {
    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the restore step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_ednote_activity_structure_step('ednote_structure', 'ednote.xml'));
    }

    /**
     * File areas to decode.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('ednote', ['intro'], 'ednote');

        return $contents;
    }

    /**
     * Link decoding rules. A teacher note has no page of its own, so there is nothing to decode.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [];
    }

    /**
     * Restore log rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
