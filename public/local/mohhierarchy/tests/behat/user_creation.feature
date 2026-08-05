@local_mohhierarchy @profilefield_mohhierarchy @javascript
Feature: Hierarchy-aware user creation
  In order to place Moodle users safely in the Ministry of Health hierarchy
  As an administrator or delegated hierarchy manager
  I need facility-first placement and server-side scope enforcement

  Background:
    Given the following "users" exist:
      | username        | firstname | lastname | email                            |
      | zonemanager     | Zone      | Manager  | zonemanager@example.invalid      |
      | facilitymanager | Facility  | Manager  | facilitymanager@example.invalid  |
    And the following "custom profile fields" exist:
      | datatype | shortname    | name                | visible |
      | text     | existingcode | Existing staff code | 2       |
    And the following MoH hierarchy exists:
      | type     | key | parent | name        | code |
      | zone     | za  |        | Zone A      |      |
      | zone     | zb  |        | Zone B      |      |
      | district | da1 | za     | District A1 |      |
      | district | da2 | za     | District A2 |      |
      | district | db1 | zb     | District B1 |      |
      | facility | fa1 | da1    | Facility A1 | FA1  |
      | facility | fa2 | da2    | Facility A2 | FA2  |
      | facility | fb1 | db1    | Facility B1 | FB1  |
    And user "zonemanager" manages facility "Facility A1" with "zone" scope
    And user "facilitymanager" manages facility "Facility A1" with "facility" scope

  Scenario: Site administrator creates a user through the standard advanced form
    Given I log in as "admin"
    When I navigate to "Users > Accounts > Add a new user" in site administration
    And I expand all fieldsets
    Then I should see "Existing staff code"
    And I should see "Facility hierarchy"
    When I set the following fields to these values:
      | Username     | advanced.hierarchy |
      | New password | ValidPassword!9274 |
      | First name   | Advanced           |
      | Last name    | Hierarchy          |
      | Email address | advanced.hierarchy@example.invalid |
      | City/town    | Blantyre           |
      | Facility     | Facility A1         |
    And I wait until the page is ready
    And the field "District" matches value "District A1"
    And the field "Zone" matches value "Zone A"
    And I press "Create user"
    Then I should see "New user created"

  Scenario: Zone manager creates a user through the strict plugin page
    Given I log in as "zonemanager"
    And I visit "/local/mohhierarchy/createuser.php"
    When I set the following fields to these values:
      | Username     | delegated.hierarchy |
      | First name   | Delegated           |
      | Surname      | Hierarchy           |
      | Email address | delegated.hierarchy@example.invalid |
      | New password | ValidPassword!9274  |
      | Facility     | Facility A2         |
    And I wait until the page is ready
    And the field "District" matches value "District A2"
    And the field "Zone" matches value "Zone A"
    And I press "Create hierarchy user"
    Then I should see "Hierarchy user created"
    And I should see "delegated.hierarchy"

  Scenario: Facility selection derives district and zone
    Given I log in as "admin"
    And I visit "/local/mohhierarchy/createuser.php"
    When I set the field "Facility" to "Facility B1"
    And I wait until the page is ready
    Then the field "District" matches value "District B1"
    And the field "Zone" matches value "Zone B"

  Scenario: Facility dropdown is filtered by manager scope
    Given I log in as "zonemanager"
    And I visit "/local/mohhierarchy/createuser.php"
    Then the "Facility" select box should contain "Facility A1"
    And the "Facility" select box should contain "Facility A2"
    And the "Facility" select box should not contain "Facility B1"

  Scenario: Facility manager sees fixed hierarchy values
    Given I log in as "facilitymanager"
    When I visit "/local/mohhierarchy/createuser.php"
    Then I should see "Zone A"
    And I should see "District A1"
    And I should see "Facility A1"
    And the field "Zone" matches value "Zone A"
    And the field "District" matches value "District A1"
    And the field "Facility" matches value "Facility A1"

  Scenario: Tampered out-of-scope facility is rejected by the server
    Given I log in as "zonemanager"
    And I visit "/local/mohhierarchy/createuser.php"
    And I set the following fields to these values:
      | Username      | tampered.hierarchy |
      | First name    | Tampered           |
      | Surname       | Hierarchy          |
      | Email address | tampered.hierarchy@example.invalid |
      | New password  | ValidPassword!9274 |
      | District      | District A1        |
    And I wait until the page is ready
    When I force the submitted MoH facility to "Facility B1"
    And I press "Create hierarchy user"
    Then I should see "do not belong to the same hierarchy path"
    And I should not see "Hierarchy user created"

  Scenario: Existing custom profile fields remain on the core user form
    Given I log in as "admin"
    When I navigate to "Users > Accounts > Add a new user" in site administration
    And I expand all fieldsets
    Then I should see "Existing staff code"
    And I should see "Facility hierarchy"
