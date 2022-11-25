# Silverstripe Integration for Behat

[![CI](https://github.com/silverstripe/silverstripe-behat-extension/actions/workflows/ci.yml/badge.svg)](https://github.com/silverstripe/silverstripe-behat-extension/actions/workflows/ci.yml)
[![Silverstripe supported module](https://img.shields.io/badge/silverstripe-supported-0071C4.svg)](https://www.silverstripe.org/software/addons/silverstripe-commercially-supported-module-list/)

## Overview

[Behat](http://behat.org) is a testing framework for behaviour-driven development.
Because it primarily interacts with your website through a browser,
you don't need any specific integration tools to get it going with
a basic Silverstripe website, simply follow the
[standard Behat usage instructions](http://docs.behat.org/en/latest/user_guide.html).

This extension comes in handy if you want to go beyond
interacting with an existing website and database,
for example make changes to your database content which
would need to be rolled back to a "clean slate" later on.

It provides the following helpers:

 * Provide access to Silverstripe classes in your Behat contexts
 * Set up a temporary database automatically
 * Reset the database content on every scenario
 * Prebuilt Contexts for SilverStripe's login forms and other common tasks
 * Creating of member fixtures with predefined permissions
 * YML fixture definitions inside your Behat scenarios
 * Waiting for jQuery Ajax responses (rather than fixed wait timers)
 * Captures JavaScript errors and logs them through Selenium
 * Saves screenshots to filesystem whenever an assertion error is detected

In order to achieve this, the extension makes one basic assumption:
Your Behat tests are run from the same application as the tested
Silverstripe codebase, on a locally hosted website from the same codebase.
This is important because we need access to the underlying SilverStripe
PHP classes. You can of course use a remote browser to do the actual testing.

Note: The extension has only been tested with the `selenium2` Mink driver.

## Installation

Simply [install Silverstripe through Composer](http://doc.silverstripe.org/framework/en/installation/composer).
Skip this step if adding the module to an existing project.

	composer create-project silverstripe/installer my-test-project 4.x-dev

Switch to the newly created webroot, and add the Silverstripe Behat extension.

	cd my-test-project
	composer require --dev silverstripe/behat-extension

Download the standalone [Google Chrome WebDriver](http://chromedriver.storage.googleapis.com/index.html?path=2.8/)

Now install the Silverstripe project as usual by opening it in a browser and following the instructions.
Protip: You can skip this step by using `[SS_DATABASE_CHOOSE_NAME]` in a global
[`.env`](https://docs.silverstripe.org/en/getting_started/environment_management/) file one level above the webroot.

Unless you have [`SS_BASE_URL`](http://doc.silverstripe.org/framework/en/topics/commandline#configuration) set up, 
you also need to specify the URL for your webroot. Either add it to the existing `behat.yml` configuration file
in your project root, or set is as an environment variable in your terminal session:

	export BEHAT_PARAMS="extensions[SilverStripe\BehatExtension\MinkExtension][base_url]=http://localhost/"

## Usage

### Starting ChromeDriver

You can run the server locally in a separate Terminal session:

    chromedriver

### Running the Tests

Now you can run the tests (for example for the `framework` module):

	vendor/bin/behat @framework

Or even run a single scenario by it's name (supports regular expressions):

	vendor/bin/behat --name 'My scenario title' @framework

This will start a Chrome browser by default. Other browsers and profiles can be configured in `behat.yml`.

### Running with stand-alone command (requires Bash)

If running with `silverstripe/serve` and `chromedriver`, you can also use the following command
which will automatically start and stop these services for individual tests.

    vendor/bin/behat-ss @framework

This automates:
 - starting server
 - starting chromedriver
 - running behat
 - shutting down chromedriver
 - shutting down server

Make sure you set `SS_BASE_URL` to `http://localhost:8080` in `.env`

## Tutorials

 * [Tutorial: Testing Form Submissions](docs/tutorial.md)
 * [Tutorial: Webservice Mocking with Phockito and TestSession](docs/webservice-mocking.md)
 * [Tutorial: Setting up Behat on CircleCI](docs/circleci-tutorial.md)

## Configuration

The Silverstripe installer already comes with a YML configuration
which is ready to run tests on the standalone ChromeDriver server,
located in the project root as `behat.yml`.

You should ensure that you have configured `SS_BASE_URL` in your `.env` file.

Generic Mink configuration settings are placed in `SilverStripe\BehatExtension\MinkExtension`,
which is a subclass of `Behat\MinkExtension\Extension`.

Overview of settings (all in the `extensions.SilverStripe\BehatExtension\Extension` path):

 * `ajax_steps`: Because Silverstripe uses AJAX requests quite extensively, we had to invent a way
to deal with them more efficiently and less verbose than just
Optional `ajax_steps` is used to match steps defined there so they can be "caught" by
[special AJAX handlers](http://blog.scur.pl/2012/06/ajax-callback-support-behat-mink/) that tweak the delays. You can either use a pipe delimited string or a list of substrings that match step definition.
 * `ajax_timeout`: Milliseconds after which an Ajax request is regarded as timed out,
 and the script continues with its assertions to avoid a deadlock (Default: 5000).
 * `screenshot_path`: Absolute path used to store screenshot of a last known state
of a failed step.
Screenshot names within that directory consist of feature file filename and line
number that failed.

Example: behat.yml

	default:
	  suites:
	    framework:
          paths:
            - %paths.modules.framework%/tests/behat/features
          contexts:
            - SilverStripe\Framework\Tests\Behaviour\FeatureContext
            - SilverStripe\Framework\Tests\Behaviour\CmsFormsContext
            - SilverStripe\Framework\Tests\Behaviour\CmsUiContext
            - SilverStripe\BehatExtension\Context\BasicContext
            - SilverStripe\BehatExtension\Context\EmailContext
            - SilverStripe\BehatExtension\Context\LoginContext
            -
              SilverStripe\BehatExtension\Context\FixtureContext:
                - %paths.modules.framework%/tests/behat/features/files/
	  extensions:
        SilverStripe\BehatExtension\MinkExtension:
          default_session: facebook_web_driver
          javascript_session: facebook_web_driver
          facebook_web_driver:
            browser: chrome
            wd_host: "http://127.0.0.1:9515" #chromedriver port
        SilverStripe\BehatExtension\Extension:
          screenshot_path: %paths.base%/artifacts/screenshots

## Module Initialisation

You're all set to start writing features now! Simply create `*.feature` files
anywhere in your codebase, and run them as shown above. We recommend the folder
structure of `tests/behat/features`, since its consistent with the common location
of SilverStripe's PHPUnit tests.

Behat tests rely on a `FeatureContext` class which contains step definitions,
and can be composed of other subcontexts, e.g. for SilverStripe-specific CMS steps
(details on [behat.org](http://docs.behat.org/quick_intro.html#the-context-class-featurecontext)).
Since step definitions are quite domain specific, its likely that you'll need your own context.
The Silverstripe Behat extension provides an initializer script which generates a template
in the recommended folder structure:

	vendor/bin/behat --init @mymodule --namespace="MyVendor\MyModule"

	Note: namespace is mandatory

You'll now have a class located in `mymodule/tests/behat/src/FeatureContext.php`,
which will have a psr-4 class mapping added to composer.json by default.
Also a folder for your features with `mymodule/tests/behat/features` will be created.
A `mymodule/behat.yml` is built, with a default suite named after the module.

## Available Step Definitions

The extension comes with several `BehatContext` subclasses come with some extra step defintions.
Some of them are just helpful in general website testing, other's are specific to SilverStripe.
To find out all available steps (and the files they are defined in), run the following:

	vendor/bin/behat @mymodule --definitions=i

Note: There are more specific step definitions in the Silverstripe `framework` module
for interacting with the CMS interfaces (see `framework/tests/behat/features/bootstrap`).
In addition to the dynamic list, a cheatsheet of available steps can be found at the end of this guide.

## Fixtures

Since each test run creates a new database, you can't rely on existing state unless
you explicitly define it.

### Database Defaults

The easiest way to get default data is through `DataObject->requireDefaultRecords()`.
Many modules already have this method defined, e.g. the `blog` module automatically
creates a default `BlogHolder` entry in the page tree. Sometimes these defaults can
be counterproductive though, so you need to "opt-in" to them, via the `@database-defaults`
tag placed at the top of your feature definition. The defaults are reset after each
scenario automatically.

### Inline Definition

If you need more flexibility and transparency about which records are being created,
use the inline definition syntax. The following example shows some syntax variations:

	Feature: Do something with pages
		As an site owner
		I want to manage pages

		Background:
			# Creates a new page without data. Can be accessed later under this identifier
			Given a "page" "Page 1"
			# Uses a custom RegistrationPage type
			And an "error page" "Register"
			# Creates a page with inline properties
			And a "page" "Page 2" with "URLSegment"="page-1" and "Content"="my page 1"
			# Field names can be tabular, and based on DataObject::$field_labels
			And the "page" "Page 3" has the following data
			 | Content | <blink> |
			 | My Property | foo |
			 | My Boolean | bar |
			# Pages are published by default, can be explicitly unpublished
			And the "page" "Page 1" is not published
			# Create a hierarchy, and reference a record created earlier
			And the "page" "Page 1.1" is a child of a "page" "Page 1"
			# Specific page type step
			And a "page" "My Redirect" which redirects to a "page" "Page 1"
			And a "member" "Website User" with "FavouritePage"="=>Page.Page 1"

		@javascript
		Scenario: View a page in the tree
			Given I am logged in with "ADMIN" permissions
			And I go to "/admin/pages"
			Then I should see "Page 1" in CMS Tree

 * Fixtures are created where you defined them. If you want the fixtures to be created
   before every scenario, define them in 
   [Background](http://docs.behat.org/en/latest/user_guide/writing_scenarios.html#backgrounds).
   If you want them to be created only when a particular scenario runs, define them there.
 * Fixtures are cleared between scenarios.
 * The basic syntax works for all `DataObject` subclasses, but some specific
   notations like "is not published" requires extensions like `Hierarchy` to be applied to the class
 * Record types, identifiers, property names and property values need to be quoted
 * Record types (class names) can use more natural notation ("registration page" instead of "Registration Page")
 * Record types support the `$singular_name` notation which is also used to reference the types throughout the CMS.
   Record property names support the `$field_labels` notation in the same fashion.
 * Property values may also use a `=>` symbol to indicate relationships between records.
   The notation is `=><classname>.<identifier>`. For `has_many` or `many_many` relationships,
   multiple relationships can be separated by a comma.

## Writing Behat Tests

### Directory Structure

As a convention, Silverstripe Behat tests live in a `tests/behat` subfolder
of your module. You can create it with the following commands:

	mkdir -p mymodule/tests/behat/features/
	mkdir -p mymodule/tests/behat/src/

### FeatureContext

The generic [Behat usage instructions](http://docs.behat.org/en/latest/user_guide.html) apply
here as well. The only major difference is the base class from which
to extend your own `FeatureContext`: It should be `SilverStripeContext`
rather than `BehatContext`.

Example: mymodule/tests/behat/src/FeatureContext.php

	<?php
	namespace MyModule\Test\Behaviour;

	use SilverStripe\BehatExtension\Context\SilverStripeContext;

	class FeatureContext extends SilverStripeContext
	{
	}

### Screen Size

In some Selenium drivers you can
define the desired browser window size through a `capabilities` definition.
By default, Selenium doesn't support this though, so we've added a workaround
through an environment variable:

	BEHAT_SCREEN_SIZE=320x600 vendor/bin/behat

### Inspecting PHP sessions

Behat is executed from CLI, which in turn triggers web requests in a browser.
This browser session is associated PHP session information such as the logged-in user.
After every request, the session information is persisted on disk as part
of the `TestSessionEnvironment`, in order to share it with Behat CLI.

Example: Retrieve the currently logged-in member

    use SilverStripe\TestSession\TestsessionEnvironment;

	$env = Injector::inst()->get(TestSessionEnvironment::class);
	$state = $env->getState();
	if(isset($state->session['loggedInAs'])) {
		$member = \Member::get()->byID($state->session['loggedInAs']);
	} else {
		$member = null;
	}

## FAQ

### FeatureContext not found

This is most likely a problem with Composer's autoloading generator.
Check that you have "SilverStripe" mentioned in the `vendor/composer/autoload_classmap.php` file,
and call `composer dump-autoload` if not.

### How do I wait for asynchronous actions in my steps?

Sometimes you want to wait for an AJAX request or CSS animation to complete before
calling the next step/assertion. Mink provides a [wait() method](http://mink.behat.org/en/latest/guides/session.html)
for this purpose - just let the execution wait until a JavaScript expression satisfies your criteria.
It's pretty common to make this expression a CSS selector.
The Behat tests come with built-in support to wait for any pending `jQuery.ajax()` requests,
check `BasicContext->handleAjaxBeforeStep()` and the `ajax_steps` configuration option.

### Why does the module need to know about the framework path on the filesystem?

Sometimes Silverstripe needs to know the URL of your site. When you're visiting
your site in a web browser this is easy to work out, but if you're executing
scripts on the command-line, it has no way of knowing.

### How does the module interact with the SS database?

The module creates temporary database on init and is switching to the alternative
database session before every scenario by using `/dev/tests/setdb` TestRunner
endpoint.

It also populates this temporary database with the default records if necessary.

It is possible to include your own fixtures, it is explained further.

### Why do tests pass in a fresh installation, but fail in my own project?

Because we're testing the interface directly, any changes to the
viewed elements have the potential to disrupt testing.
By building a test database from scratch, we're trying to minimize this impact.
Some examples where things can go wrong nevertheless:

 * Thirdparty Silverstripe modules which install default data
 * Changes to the default interface language
 * Configurations which remove admin areas or specific fields

Currently there's no way to exclude offending modules from a test run.
You either have to adjust the tests to work around these changes,
or run tests on a "sandbox" projects without these modules.

### How do I debug when something goes wrong?

First, read the console output. Behat will tell you which steps have failed.

Silverstripe Behaviour Testing Framework also notifies you about some events.
It tries to catch some JavaScript errors and AJAX errors as well although it
is limited to errors that occur after the page is loaded.

Screenshot will be taken by the module every time the step is marked as failed.
Refer to configuration section above to know how to set up the screenshot path.

If you are unable to debug using the information collected with the above
methods, it is possible to delay the step execution by adding the following step:

    And I put a breakpoint

This will stop the execution of the tests until you press the return key in the
terminal. This is very useful when you want to look at the error or developer console
inside the browser or if you want to interact with the session page manually.

### Can I set breakpoints through XDebug?

If you have [XDebug](http://xdebug.org) set up, breakpoints are your friend.
The problem is that you can only connect the debugger to the PHP execution
in the CLI, or in the browser, not both at the same time.

First of all, ensure that `xdebug.remote_autostart` is set to `Off`,
otherwise you'll always have an active debugging session in CLI, never in the browser.

Then you can choose to enable XDebug for the current CLI run:

	XDEBUG_CONFIG="idekey=macgdbp" vendor/bin/behat

Or you can use the `TESTSESSION_PARAMS` environment variable to pass additional
parameters to `dev/testsession/start`, and debug in the browser instead.

	TESTSESSION_PARAMS="XDEBUG_SESSION_START=macgdbp" vendor/bin/behat @app

The `macgdbp` IDE key needs to match your `xdebug.idekey` php.ini setting.

### How do I set up continuous integration through Travis?

Check out the [travis.yml](https://github.com/silverstripe/silverstripe-framework/blob/master/.travis.yml)
in `silverstripe/framework` for a good example on how to set up Behat tests through 
[travis-ci.org](http://travis-ci.org).

## Cheatsheet

This is a manually categorized list of available commands
when both the `cms` and `framework` modules are installed.
It's based on the `vendor/bin/behat -di @cms` output.

### Basics

	 Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that page contains specified text.

	 Then /^(?:|I )should not see "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that page doesn't contain specified text.

	 Then /^(?:|I )should see text matching (?P<pattern>"(?:[^"]|\\")*")$/
	    - Checks, that page contains text matching specified pattern.

	 Then /^(?:|I )should not see text matching (?P<pattern>"(?:[^"]|\\")*")$/
	    - Checks, that page doesn't contain text matching specified pattern.

	 Then /^the response should contain "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that HTML response contains specified string.

	 Then /^the response should not contain "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that HTML response doesn't contain specified string.

	 Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS contains specified text.

	 Then /^(?:|I )should not see "(?P<text>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS doesn't contain specified text.

	 Then /^the "(?P<element>[^"]*)" element should contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that element with specified CSS contains specified HTML.

	 Then /^(?:|I )should see an? "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS exists on page.

	 Then /^(?:|I )should not see an? "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS doesn't exist on page.

	 Then /^(?:|I )should be on "(?P<page>[^"]+)"$/
	    - Checks, that current page PATH is equal to specified.

	 Then /^the (?i)url(?-i) should match (?P<pattern>"([^"]|\\")*")$/
	    - Checks, that current page PATH matches regular expression.

	 Then /^the response status code should be (?P<code>\d+)$/
	    - Checks, that current page response status is equal to specified.

	 Then /^the response status code should not be (?P<code>\d+)$/
	    - Checks, that current page response status is not equal to specified.

	 Then /^(?:|I )should see (?P<num>\d+) "(?P<element>[^"]*)" elements?$/
	    - Checks, that (?P<num>\d+) CSS elements exist on the page

	 Then /^print last response$/
	    - Prints last response to console.

	 Then /^show last response$/
	    - Opens last response content in browser.

	 Then /^I should be redirected to "([^"]+)"/

	Given /^I wait (?:for )?([\d\.]+) second(?:s?)$/

	Then /^the "([^"]*)" table should contain "([^"]*)"$/

	Then /^the "([^"]*)" table should not contain "([^"]*)"$/

	Given /^I click on "([^"]*)" in the "([^"]*)" table$/

### Navigation

	Given /^(?:|I )am on homepage$/
	    - Opens homepage.

	 When /^(?:|I )go to homepage$/
	    - Opens homepage.

	Given /^(?:|I )am on "(?P<page>[^"]+)"$/
	    - Opens specified page.

	 When /^(?:|I )go to "(?P<page>[^"]+)"$/
	    - Opens specified page.

	 When /^(?:|I )reload the page$/
	    - Reloads current page.

	 When /^(?:|I )move backward one page$/
	    - Moves backward one page in history.

	 When /^(?:|I )move forward one page$/
	    - Moves forward one page in history

### Forms

	When /^(?:|I )press "(?P<button>(?:[^"]|\\")*)"$/
	    - Presses button with specified id|name|title|alt|value.

	 When /^(?:|I )follow "(?P<link>(?:[^"]|\\")*)"$/
	    - Clicks link with specified id|title|alt|text.

	 When /^(?:|I )fill in "(?P<field>(?:[^"]|\\")*)" with "(?P<value>(?:[^"]|\\")*)"$/
	    - Fills in form field with specified id|name|label|value.

	 When /^(?:|I )fill in "(?P<value>(?:[^"]|\\")*)" for "(?P<field>(?:[^"]|\\")*)"$/
	    - Fills in form field with specified id|name|label|value.

	 When /^(?:|I )fill in the following:$/
	    - Fills in form fields with provided table.

	 When /^(?:|I )select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)"$/
	    - Selects option in select field with specified id|name|label|value.

	 When /^(?:|I )additionally select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)"$/
		- Selects additional option in select field with specified id|name|label|value.

	 When /^I select the "([^"]*)" radio button$/
		- Selects a radio button with the given id|name|label|value

	 When /^(?:|I )check "(?P<option>(?:[^"]|\\")*)"$/
	    - Checks checkbox with specified id|name|label|value.

	 When /^(?:|I )uncheck "(?P<option>(?:[^"]|\\")*)"$/
	    - Unchecks checkbox with specified id|name|label|value.

	 When /^(?:|I )attach the file "(?P[^"]*)" to "(?P<field>(?:[^"]|\\")*)"$/
	    - Attaches file to field with specified id|name|label|value.

	 Then /^the "(?P<field>(?:[^"]|\\")*)" field should contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that form field with specified id|name|label|value has specified value.

	 Then /^the "(?P<field>(?:[^"]|\\")*)" field should not contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that form field with specified id|name|label|value doesn't have specified value.

	 Then /^the "(?P<checkbox>(?:[^"]|\\")*)" checkbox should be checked$/
	    - Checks, that checkbox with specified in|name|label|value is checked.

	 Then /^the "(?P<checkbox>(?:[^"]|\\")*)" checkbox should not be checked$/
	    - Checks, that checkbox with specified in|name|label|value is unchecked.

	 When /^I fill in the "(?P<field>([^"]*))" HTML field with "(?P<value>([^"]*))"$/

	 When /^I fill in "(?P<value>([^"]*))" for the "(?P<field>([^"]*))" HTML field$/

	 When /^I append "(?P<value>([^"]*))" to the "(?P<field>([^"]*))" HTML field$/

	 Then /^the "(?P<locator>([^"]*))" HTML field should contain "(?P<html>([^"]*))"$/

	When /^(?:|I )fill in the "(?P<field>(?:[^"]|\\")*)" dropdown with "(?P<value>(?:[^"]|\\")*)"$/
	  - Workaround for chosen.js dropdowns or tree dropdowns which hide the original dropdown field.

	When /^(?:|I )fill in "(?P<value>(?:[^"]|\\")*)" for "(?P<field>(?:[^"]|\\")*)" dropdown$/
	  - Workaround for chosen.js dropdowns or tree dropdowns which hide the original dropdown field.

	Given /^I select "([^"]*)" from "([^"]*)" input group$/
	  - Check an individual input button from a group of inputs
	  - Example: I select "Admins" from "Groups" input group
	   (where "Groups" is the title of the CheckboxSetField or OptionsetField form field)

### Interactions

	Given /^I press the "([^"]*)" button$/

	Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element$/

	Given /^I type "([^"]*)" into the dialog$/

	Given /^I (?:press|follow) the "([^"]*)" (?:button|link), confirming the dialog$/

	Given /^I (?:press|follow) the "([^"]*)" (?:button|link), dismissing the dialog$/

    Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, confirming the dialog$/

    Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, dismissing the dialog$/

	Given /^I confirm the dialog$/

	Given /^I dismiss the dialog$/

### Login

	Given /^I am logged in with "([^"]*)" permissions$/
	    - Creates a member in a group with the correct permissions.

	Given /^I am not logged in$/

	 When /^I log in with "(?<username>[^"]*)" and "(?<password>[^"]*)"$/

	Given /^I should see a log-in form$/

	 Then /^I will see a "bad" log-in message$/

### CMS UI

	 Then /^I should see an edit page form$/

	 Then /^I should see the CMS$/

	 Then /^I should see a "([^"]*)" message$/

	Given /^I should see a "([^"]*)" button in CMS Content Toolbar$/

	 When /^I should see "([^"]*)" in CMS Tree$/

	 When /^I should not see "([^"]*)" in CMS Tree$/

	 When /^I expand the "([^"]*)" CMS Panel$/

	 When /^I click the "([^"]*)" CMS tab$/

	 Then /^I can see the preview panel$/

	Given /^the preview contains "([^"]*)"$/

	Given /^the preview does not contain "([^"]*)"$/

### Fixtures

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" (:?which )?redirects to (?:(an|a|the) )"(?<targetType>[^"]+)" "(?<targetId>[^"]+)"$/
	    - Find or create a redirector page and link to another existing page.

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)"$/
	    - Example: Given a "page" "Page 1"

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" with (?<data>.*)$/
	    - Example: Given a "page" "Page 1" with "URL"="page-1" and "Content"="my page 1"

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" has the following data$/
	    - Example: And the "page" "Page 2" has the following data

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is a (?<relation>[^\s]*) of (?:(an|a|the) )"(?<relationType>[^"]+)" "(?<relationId>[^"]+)"/
	    - Example: Given the "page" "Page 1.1" is a child of the "page" "Page1"
	      Note that this change is not published by default

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is (?<state>[^"]*)$/
	    - Example: Given the "page" "Page 1" is not published
	    - Example: Given the "page" "Page 1" is published
	    - Example: Given the "page" "Page 1" is deleted

	Given /^there are the following ([^\s]*) records$/
	    - Accepts YAML fixture definitions similar to the ones used in Silverstripe unit testing.

	Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)"$/
	    - Example: Given a "member" "Admin" belonging to "Admin Group"

	Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)" with (?<data>.*)$/

	Given /^(?:(an|a|the) )"group" "(?<id>[^"]+)" (?:(with|has)) permissions (?<permissionStr>.*)$/
	    - Example: Given a "group" "Admin" with permissions "Access to 'Pages' section" and "Access to 'Files' section"

	Given /^I assign (?:(an|a|the) )"(?<type>[^"]+)" "(?<value>[^"]+)" to (?:(an|a|the) )"(?<relationType>[^"]+)" "(?<relationId>[^"]+)"$/
	    - Example: I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1"

	Given /^I assign (?:(an|a|the) )"(?<type>[^"]+)" "(?<value>[^"]+)" to (?:(an|a|the) )"(?<relationType>[^"]+)" "(?<relationId>[^"]+)" in the "(?<relationName>[^"]+)" relation$
		- Example: I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1" in the "Terms" relation

	Given /^the CMS settings have the following data$/
		- Example: Given the CMS settings has the following data
		- Note: It only works with the Silverstripe CMS module installed

### Environment

	Given /^the current date is "([^"]*)"$/
	Given /^the current time is "([^"]*)"$/

### Email

	Given /^there should (not |)be an email (to|from) "([^"]*)"$/

	Given /^there should (not |)be an email (to|from) "([^"]*)" titled "([^"]*)"$/

	Given /^the email should (not |)contain "([^"]*)"$/
		- Example: Given the email should contain "Thank you for registering!"

	When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)"$/

	When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)" titled "([^"]*)"$/

	When /^I click on the "([^"]*)" link in the email"$/

	Given /^I clear all emails$/

	Then /^the email should (not |)contain the following data:$/
		Example: Then the email should contain the following data:

	Then /^there should (not |)be an email titled "([^"]*)"$/

	Then /^the email should (not |)be sent from "([^"]*)"$/

	Then /^the email should (not |)be sent to "([^"]*)"$/

    When /^I click on the http link "([^"]*)" in the email$/
        - Example: When I click on the http link "http://localhost/changepassword" in the email

### Transformations

Behat [transformations](http://docs.behat.org/en/v2.5/guides/2.definitions.html#step-argument-transformations)
have the ability to change step arguments based on their original value,
for example to cast any argument matching the `\d` regex into an actual PHP integer.

 * `/^(?:(the|a)) time of (?<val>.*)$/`: Transforms relative time statements compatible with 
 [strtotime()](http://www.php.net/manual/en/datetime.formats.relative.php). Example: "the time of 1 hour ago" might 
 return "22:00:00" if its currently "23:00:00".
 * `/^(?:(the|a)) date of (?<val>.*)$/`: Transforms relative date statements compatible with 
 [strtotime()](http://www.php.net/manual/en/datetime.formats.relative.php). Example: "the date of 2 days ago" might 
 return "2013-10-10" if its currently the 12th of October 2013.
 * `/^(?:(the|a)) datetime of (?<val>.*)$/`: Transforms relative date and time statements compatible with 
 [strtotime()](http://www.php.net/manual/en/datetime.formats.relative.php). Example: "the datetime of 2 days ago" might 
 return "2013-10-10 23:00:00" if its currently the 12th of October 2013.

## Useful resources

* [Silverstripe CMS architecture](http://doc.silverstripe.org/sapphire/en/trunk/reference/cms-architecture)
* [Silverstripe Framework Test Module](https://github.com/silverstripe-labs/silverstripe-frameworktest)
* [Silverstripe Unit and Integration Testing](http://doc.silverstripe.org/sapphire/en/trunk/topics/testing)
