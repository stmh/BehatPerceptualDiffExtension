<?php

namespace Zodyac\Behat\PerceptualDiffExtension\Comparator;

use Behat\Gherkin\Node\ScenarioNode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Behat\Event\ScenarioEvent;
use Behat\Behat\Event\OutlineEvent;
use Behat\Gherkin\Node\StepNode;

class ScreenshotComparator implements EventSubscriberInterface
{
    /**
     * Base path
     *
     * @var string
     */
    protected $path;

    /**
     * Amount of time in seconds to sleep before taking a screenshot.
     *
     * @var int
     */
    protected $sleep;

    /**
     * Options passed to the `compare` call.
     *
     * @var array
     */
    protected $compareOptions;

    /**
     * When the suite was started.
     *
     * @var \DateTime
     */
    protected $started;

    /**
     * The scenario currently being tested.
     *
     * @var ScenarioNode
     */
    protected $currentScenario;

    /**
     * Current step (reset for each scenario)
     *
     * @var int
     */
    protected $stepNumber;

    /**
     * Diff results
     *
     * @var array
     */
    protected $diffs = array();

    /**
     * Storage for steps with diff results.
     *
     * @var array
     */
    protected $storedSteps = array();

    public function __construct($path, $sleep, array $compareOptions)
    {
        $this->path = rtrim($path, '/') . '/';
        $this->sleep = (int) $sleep;
        $this->compareOptions = $compareOptions;
        $this->started = new \DateTime();
    }

    public static function getSubscribedEvents()
    {
        return array(
            'beforeSuite' => 'clearScreenshotDiffs',
            'beforeScenario' => 'resetStepCounter',
            'beforeOutline' => 'resetStepCounter'
        );
    }

    /**
     * Returns the filename of the diff screenshot or null if
     * there were no differecnes.
     *
     * @param StepNode $step
     * @return string
     */
    public function getDiff(StepNode $step)
    {
        $hash = spl_object_hash($step);

        if (isset($this->diffs[$hash])) {
            return $this->diffs[$hash];
        }
    }

    /**
     * Returns all of the diffs
     *
     * @return array
     */
    public function getDiffs()
    {
        return $this->diffs;
    }

    /**
     * Returns the screenshot path
     *
     * @return string
     */
    public function getScreenshotPath()
    {
        return $this->path . $this->started->format('YmdHis') . '/';
    }

    /**
     * Returns the baseline screenshot path
     *
     * @return string
     */
    public function getBaselinePath()
    {
        return $this->path . 'baseline/';
    }

    /**
     * Returns the diff path
     *
     * @return string
     */
    public function getDiffPath()
    {
        return $this->path . 'diff/';
    }

    /**
     * Returns the path to images showing areas to be ignored
     *
     * @return string
     */
    public function getMaskPath() {
        return $this->path . 'ignore/';
    }

    /**
     * Remove all the previous diffs
     */
    public function clearScreenshotDiffs()
    {
        $diffPath = $this->getDiffPath();
        if (is_dir($diffPath)) {
            $this->removeDirectory($diffPath);
        }
    }

    /**
     * Keep track of the current scenario and step number for use in the file name
     *
     * @param ScenarioEvent|OutlineEvent $event
     */
    public function resetStepCounter($event)
    {
        $this->currentScenario = $event instanceof ScenarioEvent ? $event->getScenario() : $event->getOutline();
        $this->stepNumber = 0;
    }

    /**
     * Takes a screenshot if the step passes and compares it to the baseline
     *
     * @param RawMinkContext $context
     * @param StepNode $step
     *
     * @returns mixed
     *   The amount of the perceptual diff as returned by the imagemagick
     *   compare command, or FALSE if there was no difference (or if no
     *   comparison was made).
     */
    public function takeScreenshot(RawMinkContext $context, StepNode $step)
    {
        $driver = $context->getSession()->getDriver();
        if (!($driver instanceof Selenium2Driver)) {
            return false;
        }

        // Increment the step number
        $this->stepNumber++;

        if ($this->sleep > 0) {
            // Convert seconds to microseconds
            usleep($this->sleep * 1000000);
        }

        $screenshotPath = $this->getScreenshotPath();
        $screenshotFile = $screenshotPath . $this->getFilepath($step);
        $this->ensureDirectoryExists($screenshotFile);

        // Save the screenshot

        file_put_contents($screenshotFile, $context->getSession()->getScreenshot());


        // Comparison
        $baselinePath = $this->getBaselinePath();
        $diffPath = $this->getDiffPath();

        $baselineFile = str_replace($screenshotPath, $baselinePath, $screenshotFile);
        if (!is_file($baselineFile)) {
            $this->ensureDirectoryExists($baselineFile);

            // New step, move into the baseline but return as there is no need for a comparison
            copy($screenshotFile, $baselineFile);
            return false;
        }

        // Output the comparison to a temp file
        $tempFile = $this->path . 'temp.png';

        // Path to an image showing areas to be ignored in the comparison
        $maskPath = $this->getMaskPath();
        $maskFile = str_replace($screenshotPath, $maskPath, $screenshotFile);
        if (is_file($maskFile)) {
            // Create temp images for comparison that blank out the masked area.
            $baselineTempFile = $this->path . 'baseline-temp.png';
            $this->getMaskedFile($baselineFile, $maskFile, $baselineTempFile);
            $screenshotTempFile = $this->path . 'screenshot-temp.png';
            $this->getMaskedFile($screenshotFile, $maskFile, $screenshotTempFile);
            $compareCommand = $this->getCompareCommand($baselineTempFile, $screenshotTempFile, $tempFile);
        }
        else {
            $compareCommand = $this->getCompareCommand($baselineFile, $screenshotFile, $tempFile);
        }

        // Run the comparison
        $output = array();
        exec($compareCommand, $output, $return);

        // Clean up any mask files.
        $this->removeFile($this->path . 'baseline-temp.png');
        $this->removeFile($this->path . 'screenshot-temp.png');

        if ($return === 0 || $return === 1) {
            $diff = intval($output[0]);

            // Check that there are some differences
            if ($return === 1 || $diff > 0) {
                $diffFile = str_replace($screenshotPath, $diffPath, $screenshotFile);
                $this->ensureDirectoryExists($diffFile);

                // Store the diff
                rename($tempFile, $diffFile);

                // Record the diff for output
                $this->diffs[spl_object_hash($step)] = $this->getFilepath($step);
                // Keep a reference to the step object so that the object hash
                // can't be reused.
                $this->storedSteps[] = $step;
            } elseif (is_file($tempFile)) {
                // Clean up the temp file
                unlink($tempFile);
            }

            return $diff;
        }

        return false;
    }

    /**
     * Ensure the directory where the file will be saved exists.
     *
     * @param string $file
     * @return boolean Returns true if the directory exists and false if it could not be created
     */
    protected function ensureDirectoryExists($file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            return mkdir($dir, 0777, true);
        }

        return true;
    }

    /**
     * Recursively removes a directory and it's contents.
     *
     * @param string $path
     * @return boolean
     */
    protected function removeDirectory($path)
    {
        $dir = new \DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isFile() || $fileinfo->isLink()) {
                unlink($fileinfo->getPathName());
            } elseif (!$fileinfo->isDot() && $fileinfo->isDir()) {
                $this->removeDirectory($fileinfo->getPathName());
            }
        }

        return rmdir($path);
    }

    protected function removeFile($path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Returns the ImageMagick compare command with the correct arguments.
     *
     * @param string $baselineFile The baseline screenshot
     * @param string $screenshotFile The screenshot to compare with
     * @param string $tempFile The temp file to store the diff in (this will be deleted if there are no differences)
     * @return string
     */
    protected function getCompareCommand($baselineFile, $screenshotFile, $tempFile)
    {
        return sprintf(
            'compare -fuzz %d%% -metric %s -highlight-color %s %s %s %s 2>&1',
            $this->compareOptions['fuzz'],
            escapeshellarg($this->compareOptions['metric']),
            escapeshellarg($this->compareOptions['highlight_color']),
            escapeshellarg($baselineFile),
            escapeshellarg($screenshotFile),
            escapeshellarg($tempFile)
        );
    }

    /**
     * Returns the relative file path for the given step
     *
     * @param StepNode $step
     * @return string
     */
    protected function getFilepath($step)
    {
        return sprintf('%s/%s/%d-%s.png',
            $this->formatString($this->currentScenario->getFeature()->getTitle()),
            $this->formatString($this->currentScenario->getTitle()),
            $this->stepNumber,
            $this->formatString($step->getText())
        );
    }

    protected function getMaskedFile($base, $mask, $result) {
        $command = sprintf(
            'composite %s %s %s',
            escapeshellarg($mask),
            escapeshellarg($base),
            escapeshellarg($result)
        );
        $output = array();
        exec($command, $output, $return);
        return $return;
    }

    /**
     * Formats a title string into a filename friendly string
     *
     * @param string $string
     * @return string
     */
    protected function formatString($string)
    {
        $string = preg_replace('/[^\w\s\-]/', '', $string);
        $string = preg_replace('/[\s\-]+/', '-', $string);

        return $string;
    }
}
