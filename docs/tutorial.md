# Tutorial: Testing Form Submissions

## Overview

In this tutorial we'll show you how to test the submission
of a SilverStripe form by remote controlling a browser.
Along the way, you'll learn how to create database fixtures
and make assertions about any changed state in the database.

In order to illustrate these concepts, we'll create a "report this page"
feature which shows at the bottom of each page, and gives its visitors
an opportunity to help discover and fix problems with its content.
The "report" consists of a form with a dropdown, its submission
is stored in a custom SilverStripe database object.

![](https://www.monosnap.com/image/Xa94a2DBdcrZ21mKYVzTGXCHF.png)

## Preparation

First of all, check out a default SilverStripe project
and ensure it runs on your environment. Detailed installation instructions
can be found in the [README](../README.md) of this module.
Once you've got the SilverStripe project running, make sure you've
started ChromeDriver. With all configuration in place, initialise Behat
for

	vendor/bin/behat --init @mysite

This will create a location for our feature files later on,
in `mysite/tests/behat/features`.
Note that the module doesn't need its own `behat.yml` configuration
file since it reuses the one in the root folder.

## Feature Specification

One major goal for "testing by example" through Behat is bringing
the tests closer to the whole team, by making them part of the agile
process and ideally have the customer write some tests in his own
language (read more about this concept at
[dannorth.net](http://dannorth.net/whats-in-a-story/)).

In this spirit, we'll start "from the outside in", and
write our features before implementing them. A first draft might look
something like the following:

```cucumber
Feature: Report Abuse

	As a website user
	I want to report inappropriate content
	In order to maintain high quality content

	Scenario: Report abuse through preselected options
		Given I go to a page
		Then I should see "Report this page"
		When I select "Outdated"
		And I press the button
		Then I should see "Thanks for your submission"
```

The "syntax" conventions used here are called the
["Gherkin" language](https://github.com/cucumber/cucumber/wiki/Gherkin).
It is fairly free-form, with only few rules about indentation and
keywords such as `Feature:`, `Scenario:` or `Given`.
Each of the actual steps underneath `Scenario` needs to map
to logic which knows how to tell the browser what to do.

Let's try to run our scenario:

	vendor/bin/behat --ansi @mysite

We'll see all steps marked as "not implemented" (orange).
Thankfully Behat already comes with a lot of step definitions,
so our next move is to review what's already available:

	vendor/bin/behat @mymodule --definitions=i

The step definitions include form interactions, so we only
need to adjust our steps a bit to make them executable.

```cucumber
Scenario: Report abuse through preselected options
	Given I go to a page
	Then I should see "Report this page"
	When I select "Outdated" from "Reason"
	And I press "Submit Report"
	Then I should see "Thanks for your submission"
```

This type of refactoring is quite common, since step definitions
are ideally abstracted and shared between features. In this case,
we needed to make some steps less ambiguous, for example
referencing which form field to select from. We haven't written
the code for this form field yet, but its easy enough to label
it "Reason" without knowing much about its implementation.
Run the tests again, and you should see some steps marked
as "skipped" instead of "not implemented".

There's still a bit of ambiguity in our feature:
Each test run starts with a clean database, meaning there's no pages
to open in a browser either. Let's fix this by defining one,
and asking Behat to open it:

```cucumber
Scenario: Report abuse through preselected options
	Given a "page" "My Page"
	Given I go to the "page" "My Page"
	...
```

## SilverStripe Code

Enough theory, we have a good idea of what our feature should do,
let's get down to coding! We won't get too much into details,
but overall we're creating a new `PageAbuseReport` class with
a `has_one` relationship to `Page`. This new object gets written
by a form generated through `Page_Controller->ReportForm()`.

```php
// mysite/code/Page.php
class Page extends SiteTree {
	private static $has_many = array('PageAbuseReports' => 'PageAbuseReport');
}
class Page_Controller extends ContentController {

	// ...

	private static $allowed_actions = array('ReportForm');

	public function ReportForm() {
		return new Form(
			$this,
			'ReportForm',
			new FieldList(
				new HeaderField('ReportTitle', 'Report this page', 3),
				(new DropdownField('Reason', 'Reason'))
					->setSource(array(
						'Inappropriate' => 'Inappropriate',
						'Outdated' => 'Outdated',
						'Misleading' => 'Misleading',
					))
			),
			new FieldList(
				new FormAction('doReport', 'Submit Report')
			)
		);
	}

	public function doReport($data, $form) {
		(new PageAbuseReport(array(
			'Reason' => $data['Reason'],
			'PageID' => $this->ID,
		)))->write();
		$form->sessionMessage('Thanks for your submission!', 'good');

		return $this->redirectBack();
	}

}
```

```php
// mysite/code/PageAbuseReport.php
class PageAbuseReport extends DataObject {
	private static $db = array('Reason' => 'Text');
	private static $has_one = array('Page' => 'Page');
}
```

Now we just need to render the form on every page,
by placing it at the bottom of `themes/simple/templates/Layout/Page.ss`:

```html
<div class="content-container unit size3of4 lastUnit">
	<article>
		<h1>$Title</h1>
		<div class="content">$Content</div>
	</article>
	$ReportForm
</div>
```

You can try out this feature in your browser without Behat.
Don't forget to rebuild the database and flush the
template cache (`sake db:build --flush`) first though. If its all looking good,
kick off another Behat run - it should pass now.

	vendor/bin/behat @mysite

## Custom Step Definitions

Can you see the flaw in our test? We haven't actually checked that a report record
has been written, just that the user received a nice message after the form
submission. In order to check this, we'll need to write some custom step
definitions. This is where the SilverStripe extension to Behat comes in
handy, since you're already connected to the same test database in Behat
that the browser is using. Two separate processes, but same database -
and a clean slate on each run.

At the end of our `report-abuse.feature` file, add the following:

```cucumber
Scenario: Report abuse through preselected options
	...
	Then I should see "Thanks for your submission"
	And there should be an abuse report for "My Page" with reason "Outdated"
```

Running behat again will produce an undefined step, with a helpful PHP boilerplate
to get us started:

```php
/**
 * @Given /^there should be an abuse report for "([^"]*)" with reason "([^"]*)"$/
 */
public function thereShouldBeAnAbuseReportForWithReason($arg1, $arg2)
{
    throw new PendingException();
}
```

This code can be placed in a "context" class which was created during our
module initialization. Its located in
`mysite/tests/behat/features/bootstrap/Context/FeatureContext.php`.
The actual step implementation can vary quite a bit, and usually involves
triggering a browser action like clicking a button, or inspecting the
current browser state, e.g. check that a button is visible.
In our case, we want to talk to the SilverStripe test database,
and retrieve a record created through previous actions.
The `FeatureContext` comes predefined with a `$fixtureFactory` property,
an object which handles loading and saving of test fixtures.
You could also use `DataObject::get()` directly, but the fixture factory
allows us to use more readable aliases (`My Page`) rather than arbitrary
database identifiers. Since the fixture factory returns standard
SilverStripe ORM records, we can process them in the usual way
and check their relation for any existing reports.
The assertion framework used here is simply [PHPUnit](http://phpunit.de),
so if you have done unit testing before the `assertEquals()` call might
look familiar. Either way, it will throw an exception if there's
not exactly one record found in the relation, and hence fail that step for Behat.

```php
/**
 * @Given /^there should be an abuse report for "([^"]*)" with reason "([^"]*)"$/
 */
public function thereShouldBeAnAbuseReportForWithReason($id, $reason)
{
    $page = $this->fixtureFactory->get('Page', $id);
    Assert::assertEquals(1, $page->PageAbuseReports()->filter('Reason', $reason)->Count());
}
```

Re-run the Behat test one last time, and you should see it pass with
a more solid feature definition. Success! If you want to get your hands dirty,
try to add a second page, and assert that this page doesn't have any reports
assigned to it, ensuring that our relation setting indeed works as intended.
