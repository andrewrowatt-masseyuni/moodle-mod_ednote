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
 * Settings form for the teacher note.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * The teacher note settings form.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_ednote_mod_form extends moodleform_mod {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Like a label, the note has no separate title: the body is the whole thing. A name is
        // still stored, because course reports and the recycle bin need something to call it.
        $mform->addElement('hidden', 'name', '');
        $mform->setType('name', PARAM_TEXT);

        $this->standard_intro_elements(get_string('title', 'mod_ednote'));
        $mform->removeElement('showdescription');

        // A note carrying a preset's guidance shows that instead of what is typed here, so say so
        // rather than letting a curator edit a field that will not be displayed.
        if (!empty($this->current->presetid)) {
            $mform->addElement('static', 'ednote_presetnotice', '', get_string('presetguidance', 'mod_ednote'));
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Derive the stored name from the body, the way mod_label does.
     *
     * @param stdClass $data The submitted data.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        $body = trim(html_to_text($data->introeditor['text'] ?? '', 0, false));
        $name = shorten_text($body, 250, true);

        $data->name = $name !== '' ? $name : get_string('title', 'mod_ednote');
    }
}
