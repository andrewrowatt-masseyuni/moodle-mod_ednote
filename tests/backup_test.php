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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for backing up and restoring a teacher note.
 *
 * The decision under test is that presetid is carried through unchanged. It is not annotated and
 * not remapped, because it names a preset on the site rather than something inside the course being
 * copied - and because it is the key the "always hide this guidance" scope is stored against, so
 * losing it would silently un-hide guidance for everyone who had dismissed it. Both halves of that
 * are invisible at a glance and easy to "fix" into a remap, hence these tests.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_ednote_activity_structure_step
 * @covers     \restore_ednote_activity_structure_step
 */
final class backup_test extends \advanced_testcase {
    /** @var int A preset id that exists on no site, so a remap could not quietly produce it. */
    private const PRESETID = 4242;

    /**
     * Start each test with nothing resolved.
     */
    protected function setUp(): void {
        parent::setUp();
        guidance::reset_cache();
    }

    /**
     * Duplicate a course through a real backup and restore, and return the new course id.
     *
     * @param int $courseid The course to copy.
     * @return int The id of the restored course.
     */
    private function backup_and_restore(int $courseid): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backup->execute_plan();
        $results = $backup->get_results();
        $backupid = $backup->get_backupid();
        $backup->destroy();

        $path = make_backup_temp_directory($backupid);
        $results['backup_destination']->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            $path
        );

        $source = get_course($courseid);
        $newcourseid = \restore_dbops::create_new_course(
            $source->fullname,
            $source->shortname . '_copy',
            $source->category
        );

        $restore = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();

        return $newcourseid;
    }

    /**
     * The one teacher note in a course, read straight from the table.
     *
     * @param int $courseid The course.
     * @return \stdClass
     */
    private function only_note_in(int $courseid): \stdClass {
        global $DB;

        $notes = $DB->get_records('ednote', ['course' => $courseid]);
        $this->assertCount(1, $notes);

        return reset($notes);
    }

    /**
     * A note keeps the preset it points at, with the same id, in the restored course.
     */
    public function test_presetid_survives_a_restore_unchanged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => self::PRESETID,
            'intro' => '<p>Snapshot of the guidance.</p>',
        ]);

        $restored = $this->only_note_in($this->backup_and_restore((int)$course->id));

        // The whole point: the same id, not a remapped one. A remap would have produced the id of
        // some row in the restored course, and a dropped value would have produced 0.
        $this->assertSame(self::PRESETID, (int)$restored->presetid);
        $this->assertStringContainsString('Snapshot of the guidance.', $restored->intro);
    }

    /**
     * A standalone note restores as a standalone note rather than acquiring a preset.
     */
    public function test_a_standalone_note_stays_standalone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'intro' => '<p>Check the group mode.</p>',
        ]);

        $restored = $this->only_note_in($this->backup_and_restore((int)$course->id));

        $this->assertSame(0, (int)$restored->presetid);
    }

    /**
     * Images pasted into a hand-written note come across with it.
     *
     * This is what annotate_files() in the backup step and add_related_files() in the restore step
     * are for; dropping either leaves the note restored but its pictures broken.
     */
    public function test_intro_files_come_across(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $note = $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'intro' => '<p>See <img src="@@PLUGINFILE@@/diagram.png" alt="diagram"></p>',
        ]);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_module::instance($note->cmid)->id,
            'component' => 'mod_ednote',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'diagram.png',
        ], 'Pretend this is a PNG.');

        $newcourseid = $this->backup_and_restore((int)$course->id);

        $restoredcms = get_fast_modinfo($newcourseid)->get_instances_of('ednote');
        $this->assertCount(1, $restoredcms);

        $restoredcm = reset($restoredcms);
        $restoredfile = $fs->get_file(
            \context_module::instance($restoredcm->id)->id,
            'mod_ednote',
            'intro',
            0,
            '/',
            'diagram.png'
        );

        $this->assertNotEmpty($restoredfile);
        $this->assertSame('Pretend this is a PNG.', $restoredfile->get_content());
    }

    /**
     * A restored note renders, and renders the text it carried.
     *
     * The table having the right columns is not the same as the note working: this goes through the
     * resolver the course page actually uses.
     */
    public function test_a_restored_note_still_resolves_its_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('ednote', [
            'course' => $course->id,
            'presetid' => self::PRESETID,
            'intro' => '<p>Snapshot of the guidance.</p>',
        ]);

        $newcourseid = $this->backup_and_restore((int)$course->id);
        guidance::reset_cache();

        $resolved = guidance::for_course($newcourseid);
        $this->assertCount(1, $resolved);

        $note = reset($resolved);
        $this->assertSame(self::PRESETID, (int)$note->presetid);
        $this->assertStringContainsString('Snapshot of the guidance.', $note->content);
        // The preset id is real but names nothing on this site, so the note is flagged as having
        // lost its preset and falls back to the snapshot.
        $this->assertTrue($note->missing);
    }
}
