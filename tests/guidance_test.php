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
 * Tests for resolving what a teacher note displays.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ednote\guidance
 */
final class guidance_test extends \advanced_testcase {
    /**
     * Start each test with nothing resolved.
     */
    protected function setUp(): void {
        parent::setUp();
        guidance::reset_cache();
    }

    /**
     * Create a preset carrying guidance, and return its id.
     *
     * @param string $guidance The baked guidance HTML.
     * @return int
     */
    private function create_preset(string $guidance): int {
        $preset = $this->getDataGenerator()
            ->get_plugin_generator('mod_edpreset')
            ->create_preset(['teacherguidance' => $guidance, 'title' => 'Reflective journal']);

        return (int)$preset->get('id');
    }

    /**
     * A note linked to a preset shows the preset's current guidance, not its own stored copy.
     */
    public function test_live_guidance_wins_over_the_snapshot(): void {
        $this->resetAfterTest();

        $presetid = $this->create_preset('<p>Live guidance.</p>');
        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => $presetid,
            'intro' => '<p>Stale snapshot.</p>',
        ]);

        $resolved = guidance::for_cm((int)$course->id, (int)$note->cmid);

        $this->assertStringContainsString('Live guidance.', $resolved->content);
        $this->assertStringNotContainsString('Stale snapshot.', $resolved->content);
        $this->assertFalse($resolved->missing);
    }

    /**
     * Editing the preset changes what every note shows, with nothing to rebuild.
     *
     * This is the reason the text is read at render time instead of being copied into the note, so
     * it is worth asserting rather than assuming.
     */
    public function test_editing_the_preset_updates_the_note(): void {
        global $DB;

        $this->resetAfterTest();

        $presetid = $this->create_preset('<p>First wording.</p>');
        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => $presetid,
        ]);

        $this->assertStringContainsString(
            'First wording.',
            guidance::for_cm((int)$course->id, (int)$note->cmid)->content
        );

        $DB->set_field('edpreset_item', 'teacherguidance', '<p>Second wording.</p>', ['id' => $presetid]);
        guidance::reset_cache();

        $this->assertStringContainsString(
            'Second wording.',
            guidance::for_cm((int)$course->id, (int)$note->cmid)->content
        );
    }

    /**
     * A note whose preset has gone falls back to its own copy, and says the text may be stale.
     */
    public function test_the_snapshot_is_used_when_the_preset_is_gone(): void {
        global $DB;

        $this->resetAfterTest();

        $presetid = $this->create_preset('<p>Live guidance.</p>');
        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => $presetid,
            'intro' => '<p>Snapshot.</p>',
        ]);

        $DB->delete_records('edpreset_item', ['id' => $presetid]);
        guidance::reset_cache();

        $resolved = guidance::for_cm((int)$course->id, (int)$note->cmid);

        $this->assertStringContainsString('Snapshot.', $resolved->content);
        $this->assertTrue($resolved->missing);
    }

    /**
     * A standalone note shows its own body and is never reported as missing guidance.
     */
    public function test_a_standalone_note_shows_its_own_body(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'intro' => '<p>Just a note.</p>',
        ]);

        $resolved = guidance::for_cm((int)$course->id, (int)$note->cmid);

        $this->assertStringContainsString('Just a note.', $resolved->content);
        $this->assertFalse($resolved->missing);
        $this->assertSame(0, $resolved->presetid);
    }

    /**
     * A whole course's notes are resolved in one query, however many there are.
     *
     * ednote_cm_info_view() asks about every note on the page, so a per-note query would scale with
     * the number of presets a teacher has added.
     */
    public function test_a_course_costs_one_query(): void {
        global $DB;

        $this->resetAfterTest();

        $presetid = $this->create_preset('<p>Guidance.</p>');
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_module('ednote', [
                'course' => $course->id,
                'presetid' => $presetid,
            ]);
        }

        guidance::reset_cache();

        $before = $DB->perf_get_reads();
        $notes = guidance::for_course((int)$course->id);
        $first = $DB->perf_get_reads() - $before;

        $this->assertCount(5, $notes);
        // One for the modules table lookup, one for the notes themselves.
        $this->assertLessThanOrEqual(2, $first);

        $before = $DB->perf_get_reads();
        guidance::for_course((int)$course->id);
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'The second call must be served from memory.');
    }
}
