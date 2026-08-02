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
 * Tests for the teacher note's library functions.
 *
 * @package    mod_ednote
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::ednote_supports
 * @covers     ::ednote_get_coursemodule_info
 * @covers     ::ednote_cm_info_dynamic
 * @covers     ::ednote_cm_info_view
 * @covers     ::ednote_delete_instance
 */
final class lib_test extends \advanced_testcase {
    /**
     * Load the library under test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/ednote/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Start each test with nothing cached.
     *
     * Both caches are keyed on ids that PHPUnit reuses between tests, since resetAfterTest() rolls
     * the sequences back too.
     */
    protected function setUp(): void {
        parent::setUp();
        guidance::reset_cache();
        hidden::reset_cache();
    }

    /**
     * Build a course with one note and one enrolled user per role.
     *
     * @param array $record Overrides for the note.
     * @return array [course, note cm id, users keyed by role shortname]
     */
    private function make_course(array $record = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $note = $generator->create_module('ednote', ['course' => $course->id] + $record);

        $users = [];
        foreach (['student', 'teacher', 'editingteacher'] as $role) {
            $users[$role] = $generator->create_and_enrol($course, $role);
        }

        return [$course, (int)$note->cmid, $users];
    }

    /**
     * A note has no view link and is backed up with the course.
     */
    public function test_supports(): void {
        $this->assertTrue(ednote_supports(FEATURE_NO_VIEW_LINK));
        $this->assertTrue(ednote_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(ednote_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertFalse(ednote_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(ednote_supports(FEATURE_GROUPS));
        $this->assertSame(MOD_ARCHETYPE_RESOURCE, ednote_supports(FEATURE_MOD_ARCHETYPE));

        // False deliberately. If this turns true, standard_intro_elements() starts adding a
        // "Display description" checkbox - but only when the course format has a view page, so the
        // form then behaves differently under the single activity format.
        $this->assertFalse(ednote_supports(FEATURE_SHOW_DESCRIPTION));
    }

    /**
     * The settings form saves a note that has no name typed into it.
     *
     * moodleform_mod::validation() rejects an empty name whenever a name element exists, and this
     * form's name element is hidden and filled in afterwards by data_postprocessing(). Without the
     * validation() override the form fails silently - a hidden element renders no error text, so
     * the teacher is simply returned to an apparently untouched add form.
     */
    public function test_the_form_accepts_a_note_with_no_name(): void {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/ednote/mod_form.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        // Core's standard_coursemodule_elements() looks the section up against global $COURSE rather
        // than the course it was handed, and in a test that global is still the site course, whose
        // sections do not line up. Production always reaches this form through a page already set
        // to the right course.
        $PAGE->set_course($course);

        [, , , $cm, $data] = prepare_new_moduleinfo_data($course, 'ednote', 1);
        $form = new \mod_ednote_mod_form($data, 1, $cm, $course);

        // Built from the defaults core itself prepared, so that the parent validation finds the
        // completion and availability keys it expects rather than a hand-picked subset.
        $submitted = (array)$data + [
            'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}',
        ];
        $submitted['name'] = '';
        $submitted['introeditor'] = ['text' => '<p>Set the due date.</p>', 'format' => FORMAT_HTML, 'itemid' => 0];
        $submitted['modulename'] = 'ednote';
        $submitted['instance'] = 0;
        $submitted['coursemodule'] = 0;

        $errors = $form->validation($submitted, []);

        $this->assertArrayNotHasKey('name', $errors);
    }

    /**
     * Students cannot see a teacher note, and teachers can.
     *
     * This is the plugin's whole security model, and it is enforced by core rather than by any code
     * here: cm_info::is_user_access_restricted_by_capability() looks for mod/ednote:view, which
     * db/access.php grants to teaching roles and withholds from students.
     *
     * Both flags matter. uservisible governs access; uservisibleoncoursepage is what the course
     * format actually gates the activity list on, and a mechanism that cleared only the first would
     * leave students an empty card where the note used to be.
     */
    public function test_students_cannot_see_a_note(): void {
        $this->resetAfterTest();
        [$course, $cmid, $users] = $this->make_course();

        $student = get_fast_modinfo($course, $users['student']->id)->get_cm($cmid);
        $this->assertFalse($student->uservisible);
        $this->assertFalse($student->is_visible_on_course_page());

        foreach (['teacher', 'editingteacher'] as $role) {
            $cm = get_fast_modinfo($course, $users[$role]->id)->get_cm($cmid);
            $this->assertTrue($cm->uservisible, "$role should see a teacher note");
            $this->assertTrue($cm->is_visible_on_course_page(), "$role should see a teacher note on the course page");
        }
    }

    /**
     * The note is visible = 1, so it carries no "hidden from students" badge.
     *
     * A hidden activity would be exposed by the bulk Show action in course editing; being invisible
     * by capability instead is the point of the design, and a regression here would look like
     * nothing more than a cosmetic change.
     */
    public function test_a_note_is_not_a_hidden_activity(): void {
        $this->resetAfterTest();
        [$course, $cmid, ] = $this->make_course();

        $this->assertEquals(1, get_fast_modinfo($course)->get_cm($cmid)->visible);
    }

    /**
     * The preset id is cached in modinfo, but the guidance text is not.
     *
     * Caching the text would defeat the live link: a curator's edit would not reach courses until
     * something happened to rebuild their course cache.
     */
    public function test_coursemodule_info_caches_the_preset_but_not_the_text(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cmid, ] = $this->make_course(['presetid' => 42]);

        $this->assertSame(42, (int)get_fast_modinfo($course)->get_cm($cmid)->customdata['presetid']);

        // Straight at the callback, because what matters is what it hands core to cache rather
        // than what comes back out of the cache afterwards.
        $info = ednote_get_coursemodule_info($DB->get_record('course_modules', ['id' => $cmid]));

        $this->assertSame(42, (int)$info->customdata['presetid']);
        // The cached_cm_info class always declares content; what matters is that nothing was set.
        $this->assertNull($info->content, 'The note body must not be cached in modinfo.');
    }

    /**
     * A note the user has hidden renders as nothing but the marker the CSS keys on.
     */
    public function test_a_hidden_note_renders_only_the_marker(): void {
        $this->resetAfterTest();
        [$course, $cmid, $users] = $this->make_course();

        $this->setUser($users['editingteacher']);
        $this->assertStringContainsString(
            'Set the due date',
            get_fast_modinfo($course)->get_cm($cmid)->get_formatted_content()
        );

        hidden::set(hidden::SCOPE_NOTE, $cmid, true);
        get_fast_modinfo($course, 0, true);

        $content = get_fast_modinfo($course)->get_cm($cmid)->get_formatted_content();
        $this->assertStringContainsString('ednote-is-hidden', $content);
        $this->assertStringNotContainsString('Set the due date', $content);
    }

    /**
     * Deleting a note takes every user's hide rows with it.
     */
    public function test_delete_instance_purges_hidden_rows(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cmid, $users] = $this->make_course();

        $this->setUser($users['editingteacher']);
        hidden::set(hidden::SCOPE_NOTE, $cmid, true);

        $this->setUser($users['teacher']);
        hidden::set(hidden::SCOPE_NOTE, $cmid, true);

        $this->assertSame(2, $DB->count_records('favourite', [
            'component' => hidden::COMPONENT,
            'itemtype' => hidden::SCOPE_NOTE,
            'itemid' => $cmid,
        ]));

        course_delete_module($cmid);

        $this->assertSame(0, $DB->count_records('favourite', [
            'component' => hidden::COMPONENT,
            'itemtype' => hidden::SCOPE_NOTE,
            'itemid' => $cmid,
        ]));
    }
}
