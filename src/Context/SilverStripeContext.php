<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Behat\Mink\Selector\Xpath\Escaper;
use Behat\MinkExtension\Context\MinkContext;
use InvalidArgumentException;
use SilverStripe\BehatExtension\Utility\TestMailer;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Resettable;
use SilverStripe\MinkFacebookWebDriver\FacebookWebDriver;
use SilverStripe\ORM\DataObject;
use SilverStripe\TestSession\TestSessionEnvironment;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\CssSelector\Exception\SyntaxErrorException;

/**
 * SilverStripeContext
 *
 * Generic context wrapper used as a base for Behat FeatureContext.
 *
 * The default context for each module should extend this and be named `FeatureContext`
 * under the standard module namespace.
 *
 * @link http://behat.org/en/latest/user_guide/context.html
 */
abstract class SilverStripeContext extends MinkContext implements SilverStripeAwareContext
{
    protected $databaseName;

    /**
     * @var array Partial string match for step names
     * that are considered to trigger Ajax request in the CMS,
     * and hence need special timeout handling.
     * @see \SilverStripe\BehatExtension\Context\BasicContextAwareTrait->handleAjaxBeforeStep().
     */
    protected $ajaxSteps;

    /**
     * @var Int Timeout in milliseconds, after which the interface assumes
     * that an Ajax request has timed out, and continues with assertions.
     */
    protected $ajaxTimeout;

    /**
     * @var String Relative URL to the SilverStripe admin interface.
     */
    protected $adminUrl;

    /**
     * @var String Relative URL to the SilverStripe login form.
     */
    protected $loginUrl;

    /**
     * @var String Relative path to a writeable folder where screenshots can be stored.
     * If set to NULL, no screenshots will be stored.
     */
    protected $screenshotPath;

    /**
     * @var TestSessionEnvironment
     */
    protected $testSessionEnvironment;

    protected $regionMap;

    /**
     * XPath escaper
     *
     * @var Escaper
     */
    protected $xpathEscaper;

    private int $screenWidth = 1024;

    private int $screenHeight = 768;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param   array   $parameters     context parameters (set them up through behat.yml)
     */
    public function __construct(?array $parameters = null)
    {
        if (!preg_match('#[\\\]FeatureContext$#', get_class($this))) {
            throw new InvalidArgumentException(
                'Subclasses of SilverStripeContext must be named FeatureContext. Found "' . get_class($this) . '""'
            );
        }

        // Initialize your context here
        $this->xpathEscaper = new Escaper();
        $this->testSessionEnvironment = TestSessionEnvironment::singleton();
    }

    /**
     * Get xpath escaper
     *
     * @return Escaper
     */
    public function getXpathEscaper()
    {
        return $this->xpathEscaper;
    }

    public function setDatabase($databaseName)
    {
        $this->databaseName = $databaseName;
    }

    public function setAjaxSteps($ajaxSteps)
    {
        if ($ajaxSteps) {
            $this->ajaxSteps = $ajaxSteps;
        }
    }

    public function getAjaxSteps()
    {
        return $this->ajaxSteps;
    }

    public function setAjaxTimeout($ajaxTimeout)
    {
        $this->ajaxTimeout = $ajaxTimeout;
    }

    public function getAjaxTimeout()
    {
        return $this->ajaxTimeout;
    }

    public function setAdminUrl($adminUrl)
    {
        $this->adminUrl = $adminUrl;
    }

    public function getAdminUrl()
    {
        return $this->adminUrl;
    }

    public function setLoginUrl($loginUrl)
    {
        $this->loginUrl = $loginUrl;
    }

    public function getLoginUrl()
    {
        return $this->loginUrl;
    }

    public function setScreenshotPath($screenshotPath)
    {
        $this->screenshotPath = $screenshotPath;
    }

    public function getScreenshotPath()
    {
        return $this->screenshotPath;
    }

    public function getRegionMap()
    {
        return $this->regionMap;
    }

    public function setRegionMap($regionMap)
    {
        $this->regionMap = $regionMap;
    }

    /**
     * Returns NodeElement based off region defined in .yml file.
     * Also supports direct CSS selectors and regions identified by a "data-title" attribute.
     * When using the "data-title" attribute, ensure not to include double quotes.
     *
     * @param string $region Region name or CSS selector
     * @return NodeElement
     * @throws ElementNotFoundException
     */
    public function getRegionObj($region)
    {
        // Try to find regions directly by CSS selector.
        try {
            $regionObj = $this->getSession()->getPage()->find(
                'css',
                // Escape CSS selector
                (false !== strpos($region ?? '', "'")) ? str_replace("'", "\\'", $region) : $region
            );
            if ($regionObj) {
                return $regionObj;
            }
        } catch (SyntaxErrorException $e) {
            // fall through to next case
        }

        // Fall back to region identified by data-title.
        // Only apply if no double quotes exist in search string,
        // which would break the CSS selector.
        if (false === strpos($region ?? '', '"')) {
            $regionObj = $this->getSession()->getPage()->find(
                'css',
                '[data-title="' . $region . '"]'
            );
            if ($regionObj) {
                return $regionObj;
            }
        }

        // Look for named region
        if (!$this->regionMap) {
            throw new \LogicException("Cannot find 'region_map' in the behat.yml");
        }
        if (!array_key_exists($region, $this->regionMap ?? [])) {
            throw new \LogicException("Cannot find the specified region in the behat.yml");
        }
        $regionObj = $this->getSession()->getPage()->find('css', $region);
        if (!$regionObj) {
            throw new ElementNotFoundException($this->getSession(), "Cannot find the specified region on the page");
        }

        return $regionObj;
    }

    /**
     * @BeforeScenario
     * @param BeforeScenarioScope $event
     */
    public function before(BeforeScenarioScope $event)
    {
        if (!isset($this->databaseName)) {
            throw new \LogicException(
                'Context\'s $databaseName has to be set when implementing SilverStripeAwareContextInterface.'
            );
        }

        $webDriverSession = $this->getSession();
        if (!$webDriverSession->isStarted()) {
            $webDriverSession->start();
        }

        $state = $this->getTestSessionState();
        $this->testSessionEnvironment->startTestSession($state);

        // Optionally import database
        if (!empty($state['importDatabasePath'])) {
            $this->testSessionEnvironment->importDatabase(
                $state['importDatabasePath'],
                !empty($state['requireDefaultRecords']) ? $state['requireDefaultRecords'] : false
            );
        } elseif (!empty($state['requireDefaultRecords']) && $state['requireDefaultRecords']) {
            $this->testSessionEnvironment->requireDefaultRecords();
        }

        // Fixtures
        $fixtureFile = (!empty($state['fixture'])) ? $state['fixture'] : null;
        if ($fixtureFile) {
            $this->testSessionEnvironment->loadFixtureIntoDb($fixtureFile);
        }

        if ($screenSize = Environment::getEnv('BEHAT_SCREEN_SIZE')) {
            list($screenWidth, $screenHeight) = explode('x', $screenSize ?? '');
            $this->screenWidth = (int)$screenWidth;
            $this->screenHeight = (int)$screenHeight;
        }
        $this->getSession()->resizeWindow($this->screenWidth, $this->screenHeight);

        // Reset everything
        foreach (ClassInfo::implementorsOf(Resettable::class) as $class) {
            $class::reset();
        }
        DataObject::flush_and_destroy_cache();
        DataObject::reset();
        if (class_exists(SiteTree::class)) {
            SiteTree::reset();
        }
        if (class_exists(Versioned::class)) {
            Versioned::set_stage(Versioned::DRAFT);
        }
    }

    /**
     * @AfterStep
     *
     * Wait for all requests to be handled after each step
     *
     * @param AfterStepScope $event
     */
    public function waitResponsesAfterStep(AfterStepScope $event)
    {
        $success = $this->testSessionEnvironment->waitForPendingRequests();
        if (!$success) {
            echo (
                'Warning! The timeout for waiting a response from the server has expired...'.PHP_EOL.
                'I keep going on, but this test behaviour may be inconsistent.'.PHP_EOL.PHP_EOL.
                'Your action required!'.PHP_EOL.PHP_EOL.
                'You may want to investigate why the server is responding that slowly.'.PHP_EOL.
                'Otherwise, you may need to increase the timeout.'
            );
        }
    }

    /**
     * Returns a parameter map of state to set within the test session.
     * Takes TESTSESSION_PARAMS environment variable into account for run-specific configurations.
     *
     * @return array
     */
    public function getTestSessionState()
    {
        $extraParams = array();
        parse_str(Environment::getEnv('TESTSESSION_PARAMS') ?? '', $extraParams);
        return array_merge(
            array(
                'database' => $this->databaseName,
                'mailer' => TestMailer::class,
            ),
            $extraParams
        );
    }

    /**
     * Parses given URL and returns its components
     *
     * @param $url
     * @return array|mixed Parsed URL
     */
    public function parseUrl($url)
    {
        $url = parse_url($url ?? '');
        $url['vars'] = array();
        if (!isset($url['fragment'])) {
            $url['fragment'] = null;
        }
        if (isset($url['query'])) {
            parse_str($url['query'] ?? '', $url['vars']);
        }

        return $url;
    }

    /**
     * Checks whether current URL is close enough to the given URL.
     * Unless specified in $url, get vars will be ignored
     * Unless specified in $url, fragment identifiers will be ignored
     *
     * @param $url string URL to compare to current URL
     * @return boolean Returns true if the current URL is close enough to the given URL, false otherwise.
     */
    public function isCurrentUrlSimilarTo($url)
    {
        $current = $this->parseUrl($this->getSession()->getCurrentUrl());
        $test = $this->parseUrl($url);

        if ($current['path'] !== $test['path']) {
            return false;
        }

        if (isset($test['fragment']) && $current['fragment'] !== $test['fragment']) {
            return false;
        }

        foreach ($test['vars'] as $name => $value) {
            if (!isset($current['vars'][$name]) || $current['vars'][$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns base URL parameter set in MinkExtension.
     * It simplifies configuration by allowing to specify this parameter
     * once but makes code dependent on MinkExtension.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->getMinkParameter('base_url') ?: '';
    }

    /**
     * Joins URL parts into an URL using forward slash.
     * Forward slash usages are normalised to one between parts.
     * This method takes variable number of parameters.
     *
     * @param string $part,...
     * @return string
     * @throws InvalidArgumentException
     */
    public function joinUrlParts($part = null)
    {
        if (0 === func_num_args()) {
            throw new InvalidArgumentException('Need at least one argument');
        }

        $parts = func_get_args();
        $trimSlashes = function (&$part) {
            $part = trim($part ?? '', '/');
        };
        array_walk($parts, $trimSlashes);

        return implode('/', $parts);
    }

    public function canIntercept()
    {
        $driver = $this->getSession()->getDriver();
        if ($driver instanceof FacebookWebDriver) {
            return false;
        }

        throw new UnsupportedDriverActionException(
            'You need to tag the scenario with "@mink:symfony". Intercepting the redirections is not supported by %s',
            get_class($driver)
        );
    }

    /**
     * Fills in form field with specified id|name|label|value.
     * Overwritten to select the first *visible* element, see https://github.com/minkphp/Mink/issues/311
     *
     * @param string $field
     * @param string $value
     * @throws ElementNotFoundException
     */
    public function fillField($field, $value)
    {
        $value = $this->fixStepArgument($value);
        $nodes = $this->getSession()->getPage()->findAll('named', array(
            'field', $this->getXpathEscaper()->escapeLiteral($field)
        ));
        if ($nodes) {
            /** @var NodeElement $node */
            foreach ($nodes as $node) {
                if ($node->isVisible()) {
                    $node->setValue($value);
                    return;
                }
            }
        }

        throw new ElementNotFoundException(
            $this->getSession(),
            'form field',
            'id|name|label|value',
            $field
        );
    }

    /**
     * Overwritten to click the first *visible* link the DOM.
     *
     * @param string $link
     * @throws ElementNotFoundException
     */
    public function clickLink($link)
    {
        $link = $this->fixStepArgument($link);
        $nodes = $this->getSession()->getPage()->findAll('named', array(
            'link', $this->getXpathEscaper()->escapeLiteral($link)
        ));
        if ($nodes) {
            /** @var NodeElement $node */
            foreach ($nodes as $node) {
                if ($node->isVisible()) {
                    $node->click();
                    return;
                }
            }
        }
        throw new ElementNotFoundException(
            $this->getSession(),
            'link',
            'id|name|label|value',
            $link
        );
    }

     /**
     * Sets the current date. Relies on the underlying functionality using
     * {@link SS_Datetime::now()} rather than PHP's system time methods like date().
     * Supports ISO fomat: Y-m-d
     * Example: Given the current date is "2009-10-31"
     *
     * @Given /^the current date is "([^"]*)"$/
     * @param string $date
     */
    public function givenTheCurrentDateIs($date)
    {
        $newDatetime = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$newDatetime) {
            throw new InvalidArgumentException(sprintf('Invalid date format: %s (requires "Y-m-d")', $date));
        }

        $state = $this->testSessionEnvironment->getState();
        $oldDatetime = \DateTime::createFromFormat('Y-m-d H:i:s', $state->datetime ?? '');
        if ($oldDatetime) {
            $newDatetime->setTime($oldDatetime->format('H'), $oldDatetime->format('i'), $oldDatetime->format('s'));
        }
        $state->datetime = $newDatetime->format('Y-m-d H:i:s');
        $this->testSessionEnvironment->applyState($state);
    }

    /**
     * Sets the current time. Relies on the underlying functionality using
     * {@link \SS_Datetime::now()} rather than PHP's system time methods like date().
     * Supports ISO fomat: H:i:s
     * Example: Given the current time is "20:31:50"
     *
     * @Given /^the current time is "([^"]*)"$/
     * @param string $time
     */
    public function givenTheCurrentTimeIs($time)
    {
        $newDatetime = \DateTime::createFromFormat('H:i:s', $time);
        if (!$newDatetime) {
            throw new InvalidArgumentException(sprintf('Invalid date format: %s (requires "H:i:s")', $time));
        }

        $state = $this->testSessionEnvironment->getState();
        $oldDatetime = \DateTime::createFromFormat('Y-m-d H:i:s', isset($state->datetime) ? $state->datetime : null);
        if ($oldDatetime) {
            $newDatetime->setDate($oldDatetime->format('Y'), $oldDatetime->format('m'), $oldDatetime->format('d'));
        }
        $state->datetime = $newDatetime->format('Y-m-d H:i:s');
        $this->testSessionEnvironment->applyState($state);
    }

    /**
     * Selects option in select field with specified id|name|label|value.
     *
     * @override /^(?:|I )select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)"$/
     * @param string $select
     * @param string $option
     */
    public function selectOption($select, $option)
    {
        // Find field
        $field = $this
            ->getSession()
            ->getPage()
            ->findField($this->fixStepArgument($select));

        // If field is visible then select it as per normal
        if ($field && $field->isVisible()) {
            parent::selectOption($select, $option);
        } else {
            $this->selectOptionWithJavascript($select, $option);
        }
    }

    /**
     * Selects option in select field with specified id|name|label|value using javascript
     * This method uses javascript to allow selection of options that may be
     * overridden by javascript libraries, and thus hide the element.
     *
     * @When /^(?:|I )select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)" with javascript$/
     * @param string $select
     * @param string $option
     * @throws ElementNotFoundException
     */
    public function selectOptionWithJavascript($select, $option)
    {
        $select = $this->fixStepArgument($select);
        $option = $this->fixStepArgument($option);
        $page = $this->getSession()->getPage();

        // Find field
        $field = $page->findField($select);
        if (null === $field) {
            throw new ElementNotFoundException($this->getSession(), 'form field', 'id|name|label|value', $select);
        }

        // Find option
        $opt = $field->find('named', array(
            'option', $this->getXpathEscaper()->escapeLiteral($option)
        ));
        if (null === $opt) {
            throw new ElementNotFoundException($this->getSession(), 'select option', 'value|text', $option);
        }

        // Merge new option in with old handling both multiselect and single select
        $value = $field->getValue();
        $newValue = $opt->getAttribute('value');
        if (is_array($value)) {
            if (!in_array($newValue, $value ?? [])) {
                $value[] = $newValue;
            }
        } else {
            $value = $newValue;
        }
        $valueEncoded = json_encode($value);

        // Inject this value via javascript
        $fieldID = $field->getAttribute('ID');
        $script = <<<EOS
			(function($) {
				$("#$fieldID")
					.val($valueEncoded)
					.change()
					.trigger('liszt:updated')
					.trigger('chosen:updated');
			})(jQuery);
EOS;
        $this->getSession()->getDriver()->executeScript($script);
    }

    /**
     * Set the screen to a specific width, using the default height.
     *
     * Example: Given I set the screen width to 500px
     *
     * @Given /^I set the screen width to ([\d]+)px$/
     */
    public function setScreenWidth($width)
    {
        $this->getSession()->resizeWindow($width, $this->screenHeight);
    }

    /**
     * Set the screen to a specific height, using the default width.
     * Example: Given I set the screen height to 500px
     *
     * @Given /^I set the screen height to ([\d]+)px$/
     */
    public function setScreenHeight($height)
    {
        $this->getSession()->resizeWindow($this->screenWidth, $height);
    }

    /**
     * Set the screen to a specific width and height.
     * Example: Given I set the screen size to 1024px by 768px
     *
     * @Given /^I set the screen size to ([\d]+)px by ([\d]+)px$/
     */
    public function setScreenSize($width, $height)
    {
        $this->getSession()->resizeWindow($width, $height);
    }

    /**
     * Reset the screen size to what it was at the start of the test
     * Example: Given I reset the screen size
     *
     * @Given /^I reset the screen size$/
     */
    public function resetScreenSize()
    {
        $this->getSession()->resizeWindow($this->screenWidth, $this->screenHeight);
    }
}
