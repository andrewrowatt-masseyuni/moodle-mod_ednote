@mod @mod_ednote
Feature: Add a teacher note to a course
  In order to leave guidance for whoever teaches this course
  As a teacher
  I need to add a note that teaching staff can read and students cannot

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Course 1 | C1        | topics | 3           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | teacher2 | Toni      | Tutor    | teacher2@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
      | student1 | C1     | student        |

  Scenario: A teacher adds a note through the activity form and it saves
    Given I log in as "teacher1"
    When I add a "ednote" activity to course "Course 1" section "1" and I fill the form with:
      | Teacher guidance | Set the due date before releasing this to students. |
    # The form must redirect back to the course rather than redisplay itself. A note has no name
    # field, so the empty name reaching moodleform_mod::validation() would otherwise bounce the
    # form with no visible error - see mod_ednote_mod_form::validation().
    Then I should see "Set the due date before releasing this to students."
    And I should see "Teacher guidance"

  Scenario: A note can be edited after it has been added
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I click on "Edit settings" "link" in the "Teacher note" "activity"
    And I set the following fields to these values:
      | Teacher guidance | Check the group mode and the grouping. |
    And I press "Save and return to course"
    Then I should see "Check the group mode and the grouping."
    And I should not see "Check the group mode." in the "region-main" "region"

  Scenario: Students never see a teacher note
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should not see "Check the group mode."
    And I should not see "Teacher guidance"

  Scenario: A non-editing teacher sees a teacher note
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    When I log in as "teacher2"
    And I am on "Course 1" course homepage
    Then I should see "Check the group mode."

  Scenario: A note is not a hidden activity
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    # Invisibility comes from the mod/ednote:view capability, not from the visibility flag, so the
    # note must not carry the badge that a bulk "Show" could clear.
    Then I should see "Check the group mode."
    And I should not see "Hidden from students"

  @javascript
  Scenario: Hiding a note explains itself and can be undone before the page reloads
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I click on "Hide this note" "link" in the "Teacher note" "activity"
    Then I should see "When you revisit this page, the note will be removed."
    And I should not see "Check the group mode."
    When I click on "Undo" "button" in the "Teacher note" "activity"
    Then I should not see "When you revisit this page, the note will be removed."
    And I should see "Check the group mode."

  @javascript
  Scenario: A note hidden by mistake can be unhidden again
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I click on "Hide this note" "link" in the "Teacher note" "activity"
    # Hiding marks the module not user-visible, so anything resolving it through require_login()
    # would now refuse the very person who hid it - and undo would be a one-way door.
    And I click on "Undo" "button" in the "Teacher note" "activity"
    And I am on "Course 1" course homepage
    Then I should see "Check the group mode."

  @javascript
  Scenario: A hidden note is gone on the next page load and can be restored
    Given the following "activities" exist:
      | activity | course | idnumber | name         | intro                 | section |
      | ednote   | C1     | ednote1  | Teacher note | Check the group mode. | 1       |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I click on "Hide this note" "link" in the "Teacher note" "activity"
    And I am on "Course 1" course homepage
    Then I should not see "Check the group mode."
    When I am on the "Course 1" "mod_ednote > hidden notes" page
    Then I should see "Notes you have hidden in this course"
    When I click on "Show" "link"
    And I am on "Course 1" course homepage
    Then I should see "Check the group mode."
