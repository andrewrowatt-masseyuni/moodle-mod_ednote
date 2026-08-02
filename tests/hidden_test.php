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

/**
 * Tests for the per-user hidden state.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ednote\hidden
 */
final class hidden_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        hidden::reset_cache();
    }

    /**
     * Hiding one note leaves every other note alone.
     */
    public function test_note_scope_hides_only_that_note(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        hidden::set(hidden::SCOPE_NOTE, 11, true);

        $this->assertTrue(hidden::is_hidden(11, 0));
        $this->assertFalse(hidden::is_hidden(12, 0));
    }

    /**
     * Hiding a preset's guidance hides every note carrying it, in any course.
     */
    public function test_guidance_scope_hides_every_note_with_that_preset(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        hidden::set(hidden::SCOPE_GUIDANCE, 7, true);

        // Different notes, different courses, same guidance.
        $this->assertTrue(hidden::is_hidden(101, 7));
        $this->assertTrue(hidden::is_hidden(202, 7));
        // A note carrying different guidance is unaffected.
        $this->assertFalse(hidden::is_hidden(303, 8));
        // As is a standalone note, which must not match on presetid 0.
        $this->assertFalse(hidden::is_hidden(404, 0));
    }

    /**
     * Hiding is personal: a colleague on the same course still sees the note.
     */
    public function test_hiding_is_per_user(): void {
        $this->resetAfterTest();

        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        $this->setUser($first);
        hidden::set(hidden::SCOPE_NOTE, 11, true);
        $this->assertTrue(hidden::is_hidden(11, 0));

        $this->setUser($second);
        hidden::reset_cache();
        $this->assertFalse(hidden::is_hidden(11, 0));
    }

    /**
     * Unhiding puts it back, and hiding twice is not an error.
     *
     * core_favourites throws on a duplicate insert and on deleting something that is not there, so
     * a double-click or a second browser tab would otherwise produce a 500.
     */
    public function test_setting_the_same_state_twice_is_harmless(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        hidden::set(hidden::SCOPE_NOTE, 11, true);
        hidden::set(hidden::SCOPE_NOTE, 11, true);
        $this->assertTrue(hidden::is_hidden(11, 0));

        hidden::set(hidden::SCOPE_NOTE, 11, false);
        hidden::set(hidden::SCOPE_NOTE, 11, false);
        $this->assertFalse(hidden::is_hidden(11, 0));
    }

    /**
     * An unknown scope is a programming error, not something to store.
     */
    public function test_an_unknown_scope_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\coding_exception::class);
        hidden::set('somethingelse', 11, true);
    }

    /**
     * Rewording a preset's guidance does not disturb who has hidden it.
     *
     * The hide is keyed on the preset id rather than on the text, which is what lets a curator fix
     * a typo without silently un-hiding the note for everyone who had dismissed it.
     */
    public function test_editing_guidance_leaves_the_hidden_state_alone(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $preset = $this->getDataGenerator()
            ->get_plugin_generator('mod_edpreset')
            ->create_preset(['teacherguidance' => '<p>First wording.</p>']);
        $presetid = (int)$preset->get('id');

        hidden::set(hidden::SCOPE_GUIDANCE, $presetid, true);
        $this->assertTrue(hidden::is_hidden(11, $presetid));

        $DB->set_field('edpreset_item', 'teacherguidance', '<p>Second wording.</p>', ['id' => $presetid]);
        hidden::reset_cache();

        $this->assertTrue(hidden::is_hidden(11, $presetid));
    }

    /**
     * A course page's worth of notes costs one read per scope, not one per note.
     */
    public function test_state_is_read_once_per_request(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        hidden::set(hidden::SCOPE_NOTE, 11, true);
        hidden::reset_cache();

        $before = $DB->perf_get_reads();
        for ($cmid = 1; $cmid <= 20; $cmid++) {
            hidden::is_hidden($cmid, 0);
        }

        $this->assertLessThanOrEqual(2, $DB->perf_get_reads() - $before);
    }

    /**
     * Nobody is logged in during cron, and asking then is not an error.
     */
    public function test_no_user_means_nothing_is_hidden(): void {
        $this->resetAfterTest();
        $this->setUser(null);

        $this->assertFalse(hidden::is_hidden(11, 7));
    }
}
