<?php

namespace Zodyac\Behat\PerceptualDiffExtension\Formatter;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zodyac\Behat\ExtensibleHtmlFormatter\Event\FormatterEvent;
use Zodyac\Behat\ExtensibleHtmlFormatter\Event\FormatterStepEvent;
use Zodyac\Behat\PerceptualDiffExtension\Comparator\ScreenshotComparator;
use Zodyac\Behat\PerceptualDiffExtension\Exception\PerceptualDiffException;

class HtmlFormatterListener implements EventSubscriberInterface
{
    protected $screenshotComparator;

    public function __construct(ScreenshotComparator $screenshotComparator)
    {
        $this->screenshotComparator = $screenshotComparator;
    }

    public static function getSubscribedEvents()
    {
        return array(
            'formatter.html.step' => 'printPdiff',
            'formatter.html.head' => 'printStyles'
        );
    }

    protected static function createUrl($path, $basePath) {
        return str_replace($basePath, 'pdiffs/', $path);
    }

    public function printPdiff(FormatterStepEvent $event)
    {
        // Get the diff filename
        $filename = $this->screenshotComparator->getDiff($event->getStep());
        if ($filename !== null) {

            $basePath = $this->screenshotComparator->getBasePath();


            // Output the pdiff section if there was a diff
            $baselinePath = $this->screenshotComparator->getBaselinePath() . $filename;
            $diffPath = $this->screenshotComparator->getDiffPath() . $filename;
            $screenshotPath = $this->screenshotComparator->getScreenshotPath() . $filename;

            $baselineUrl = $this->createUrl($baselinePath, $basePath);
            $diffUrl = $this->createUrl($diffPath, $basePath);
            $screenshotUrl = $this->createUrl($screenshotPath, $basePath);

            $html = <<<TEMPLATE
            <div class="pdiff">
                <a href="$baselineUrl" target="new"><img alt="Baseline" src="$baselineUrl" /></a>
                <a href="$diffUrl" target="new"><img alt="Diff" src="$diffUrl" /></a>
                <a href="$screenshotUrl" target="new"><img alt="Current" src="$screenshotUrl" /></a>
            </div>
TEMPLATE;

            $event->writeln($html);
        }
    }

    /**
     * Outputs additional CSS for the pdiff section
     *
     * @param FormatterEvent $event
     */
    public function printStyles(FormatterEvent $event)
    {
        $styles = <<<TEMPLATE
        <style type="text/css">
        #behat .pdiff img {
            width: 300px;
            height: auto;
            margin:5px;
            border:2px solid #aaa;
        }
        </style>
TEMPLATE;

        $event->writeln($styles);
    }
}

