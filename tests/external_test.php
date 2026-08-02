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

namespace mod_ednote;

use mod_ednote\external\set_hidden;

/**
 * Tests for the hide web service.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ednote\external\set_hidden
 */
final class external_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        guidance::reset_cache();
        hidden::reset_cache();
    }

    /**
     * Build a course with one note.
     *
     * @param int $presetid The preset the note carries guidance for, or 0.
     * @return array [course, note cm id]
     */
    private function make_note(int $presetid = 0): array {
        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => $presetid,
        ]);

        return [$course, (int)$note->cmid];
    }

    /**
     * A teacher can hide and then show a note again.
     */
    public function test_a_teacher_can_hide_and_show(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $result = set_hidden::execute($cmid, hidden::SCOPE_NOTE, true);
        $this->assertTrue($result['hidden']);
        $this->assertTrue(hidden::is_hidden($cmid, 0));

        $result = set_hidden::execute($cmid, hidden::SCOPE_NOTE, false);
        $this->assertFalse($result['hidden']);
        $this->assertFalse(hidden::is_hidden($cmid, 0));
    }

    /**
     * The guidance scope is stored against the preset the note actually carries.
     *
     * The preset id is resolved from the course module here rather than taken from the caller, so
     * that a browser cannot ask to hide guidance belonging to a preset it was never shown.
     */
    public function test_guidance_scope_uses_the_notes_own_preset(): void {
        $this->resetAfterTest();

        $preset = $this->getDataGenerator()
            ->get_plugin_generator('mod_edpreset')
            ->create_preset(['teacherguidance' => '<p>Guidance.</p>']);
        $presetid = (int)$preset->get('id');

        [$course, $cmid] = $this->make_note($presetid);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $result = set_hidden::execute($cmid, hidden::SCOPE_GUIDANCE, true);

        $this->assertSame(hidden::SCOPE_GUIDANCE, $result['scope']);
        // Hidden for any note carrying that preset, not just this one.
        $this->assertTrue(hidden::is_hidden(999999, $presetid));
    }

    /**
     * Asking to hide the guidance of a standalone note falls back to hiding just that note.
     *
     * Storing a row against preset 0 would otherwise match every standalone note on the site.
     */
    public function test_guidance_scope_falls_back_for_a_standalone_note(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $result = set_hidden::execute($cmid, hidden::SCOPE_GUIDANCE, true);

        $this->assertSame(hidden::SCOPE_NOTE, $result['scope']);
        $this->assertTrue(hidden::is_hidden($cmid, 0));

        // The fallback must not have hidden every other standalone note along with it.
        $this->assertFalse(hidden::is_hidden(999999, 0));
    }

    /**
     * A teacher who has hidden a note can still unhide it.
     *
     * Hiding a note makes ednote_cm_info_dynamic() mark it not user-visible, so anything that
     * resolves the module through require_login() - get_course_and_cm_from_cmid(), or
     * validate_context() on the module context - refuses the very person who hid it. Getting this
     * wrong is a one-way door that no amount of clicking Show can reopen.
     */
    public function test_a_hidden_note_can_still_be_unhidden(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        set_hidden::execute($cmid, hidden::SCOPE_NOTE, true);

        // Rebuild what the course page would see, so the cm really is not user-visible now.
        hidden::reset_cache();
        get_fast_modinfo($course, 0, true);
        $this->assertFalse(get_fast_modinfo($course)->get_cm($cmid)->uservisible);

        $this->assertFalse(set_hidden::execute($cmid, hidden::SCOPE_NOTE, false)['hidden']);
        $this->assertFalse(hidden::is_hidden($cmid, 0));
    }

    /**
     * A student cannot hide a note, because a student cannot see one.
     */
    public function test_a_student_is_refused(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        try {
            set_hidden::execute($cmid, hidden::SCOPE_NOTE, true);
            $this->fail('A student must not be able to hide a teacher note.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_string('ednote:view', 'mod_ednote'), $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('favourite', ['component' => hidden::COMPONENT]));
    }

    /**
     * The capability, not the role, is what decides this.
     */
    public function test_the_capability_and_not_the_role_is_what_decides(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        // Allowed while the capability is held.
        $this->assertTrue(set_hidden::execute($cmid, hidden::SCOPE_NOTE, true)['hidden']);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/ednote:view', CAP_PROHIBIT, $roleid, \context_course::instance($course->id), true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        set_hidden::execute($cmid, hidden::SCOPE_NOTE, false);
    }

    /**
     * An unknown scope is rejected before anything is stored.
     */
    public function test_an_unknown_scope_is_rejected(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->make_note();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $this->expectException(\invalid_parameter_exception::class);
        set_hidden::execute($cmid, 'somethingelse', true);
    }
}
