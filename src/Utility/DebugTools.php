<?php

namespace SilverStripe\BehatExtension\Utility;

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\StepScope;
use Behat\Testwork\Tester\Result\TestResult;
use Facebook\WebDriver\Exception\WebDriverException;
use SilverStripe\Assets\Filesystem;
use SilverStripe\MinkFacebookWebDriver\FacebookWebDriver;

/**
 * Step tools to help debug failing steps
 */
trait DebugTools
{
    /**
     * @var bool
     */
    private $takeScreenshotAfterEveryStep = false;

    /**
     * @var bool
     */
    private $dumpRenderedHtmlAfterEveryStep = false;

    /**
     * Ensure utilty steps are reset for subsequent scenarios
     *
     * @AfterScenario
     * @param AfterScenarioScope $event
     */
    public function resetUtilitiesAfterStep(AfterScenarioScope $event): void
    {
        $this->takeScreenshotAfterEveryStep = false;
        $this->dumpRenderedHtmlAfterEveryStep = false;
    }

    /**
     * Useful step for working out why a behat testing isn't working when running
     * the browser headless
     * Remove this step from in a feature file once the test is working correct
     *
     * @Given /^I take a screenshot after every step$/
     */
    public function iTakeAScreenshotAfterEveryStep()
    {
        $this->takeScreenshotAfterEveryStep = true;
    }

    /**
     * Utility function for debugging failing behat tests
     * Remove this step from in a feature file once the test is working correct
     *
     * @Given /^I dump the rendered HTML after every step$/
     */
    public function iDumpTheRenderedHtmlAfterEveryStep()
    {
        $this->dumpRenderedHtmlAfterEveryStep = true;
    }

    /**
     * Take a screenshot when step fails, or
     * take a screenshot after every step if the use has specified
     * "I take a screenshot after every step"
     * Works only with FacebookWebDriver.
     *
     * @AfterStep
     * @param AfterStepScope $event
     */
    public function takeScreenshotAfterFailedStep(AfterStepScope $event)
    {
        // Check failure code
        if (!$this->takeScreenshotAfterEveryStep && $event->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }
        try {
            $this->takeScreenshot($event);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Dump HTML when step fails.
     *
     * @AfterStep
     * @param AfterStepScope $event
     */
    public function dumpHtmlAfterStep(AfterStepScope $event): void
    {
        // Check failure code
        if ($event->getTestResult()->getResultCode() !== TestResult::FAILED && !$this->dumpRenderedHtmlAfterEveryStep) {
            return;
        }
        try {
            $this->dumpRenderedHtml($event);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Dump rendered HTML to disk
     * Useful for seeing the state of a page when writing and debugging feature files
     *
     * @param StepScope $event
     */
    public function dumpRenderedHtml(StepScope $event)
    {
        $feature = $event->getFeature();
        $step = $event->getStep();
        $path = $this->prepareScreenshotPath();
        if (!$path) {
            return;
        }
        // prefix with zz_ so that it alpha sorts in the directory lower than screenshots which
        // will typically be referred to far more often.  This is mainly for when you have
        // enabled `dumpRenderedHtmlAfterEveryStep`
        $path = sprintf('%s/zz_%s_%d.html', $path, basename($feature->getFile()), $step->getLine());
        $html = $this->getSession()->getPage()->getOuterHtml();
        file_put_contents($path, $html);
        $this->logMessage(sprintf('Saving HTML into %s', $path));
    }

    /**
     * Take a nice screenshot
     *
     * @param StepScope $event
     */
    public function takeScreenshot(StepScope $event)
    {
        // Validate driver
        $driver = $this->getSession()->getDriver();
        if (!($driver instanceof FacebookWebDriver)) {
            $this->logMessage('ScreenShots are only supported for FacebookWebDriver: skipping');
            return;
        }
        $feature = $event->getFeature();
        $step = $event->getStep();
        $path = $this->prepareScreenshotPath();
        if (!$path) {
            return;
        }
        $path = sprintf('%s/%s_%d.png', $path, basename($feature->getFile()), $step->getLine());
        $screenshot = $driver->getScreenshot();
        file_put_contents($path, $screenshot);
        $this->logMessage(sprintf('Saving screenshot into %s', $path));
    }

    /**
     * Ensure the screenshots path is created
     */
    private function prepareScreenshotPath()
    {
        // Check paths are configured
        $path = $this->getMainContext()->getScreenshotPath();
        if (!$path) {
            $this->logMessage('ScreenShots path not configured: skipping');
            return;
        }
        Filesystem::makeFolder($path);
        $path = realpath($path);
        if (!file_exists($path)) {
            $this->logMessage(sprintf('"%s" is not valid directory and failed to create it', $path));
            return;
        }
        if (file_exists($path) && !is_dir($path)) {
            $this->logMessage(sprintf('"%s" is not valid directory', $path));
            return;
        }
        if (file_exists($path) && !is_writable($path)) {
            $this->logMessage(sprintf('"%s" directory is not writable', $path));
            return;
        }
        return $path;
    }
}
