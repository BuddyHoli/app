@ie6-bug  @ie7-bug  @ie8-bug @ie9-bug @ie10-bug @en.wikipedia.beta.wmflabs.org @test2.wikipedia.org @login
Feature: VisualEditor References

  Scenario: VisualEditor References
    Given I am logged in
      And I am at my user page
    When I click Edit for VisualEditor
      And I click Reference
      And I can see the References User Interface
      And I enter THIS IS CONTENT into Content box
      And I click Insert reference
    Then link to Insert menu should be visible
