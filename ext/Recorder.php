<?php 
namespace Codeception\Extension;

use Codeception\Event\StepEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Module\WebDriver;
use Codeception\TestCase;
use Codeception\Util\FileSystem;
use Codeception\Util\Template;

class Recorder extends \Codeception\Extension
{
    protected $config = [
        'delete_successful' => true,
        'module' => 'WebDriver',
        'template' => null
    ];

    protected $template = <<<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recorder Result</title>

    <!-- Bootstrap Core CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css" rel="stylesheet">

    <style>
        html,
        body {
            height: 100%;
        }
        .carousel,
        .item,
        .active {
            height: 100%;
        }
        .carousel-caption {
            background: rgba(0,0,0,0.8);
        }
        .carousel-caption.error {
            background: #c0392b !important;
        }

        .carousel-inner {
            height: 100%;
        }

        .fill {
            width: 100%;
            height: 100%;
            text-align: center;
            overflow-y: scroll;
            background-position: top;
            -webkit-background-size: cover;
            -moz-background-size: cover;
            background-size: cover;
            -o-background-size: cover;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-inverse navbar-fixed-top" role="navigation">
        <div class="container">
        <div class="navbar-header">
            <a class="navbar-brand" href="#">{{test}}</a>

        </div>
        </div>
        <!-- /.container -->
    </nav>
    <header id="steps" class="carousel slide">
        <!-- Indicators -->
        <ol class="carousel-indicators">
            {{indicators}}
        </ol>

        <!-- Wrapper for Slides -->
        <div class="carousel-inner">
            {{slides}}
        </div>

        <!-- Controls -->
        <a class="left carousel-control" href="#steps" data-slide="prev">
            <span class="icon-prev"></span>
        </a>
        <a class="right carousel-control" href="#steps" data-slide="next">
            <span class="icon-next"></span>
        </a>

    </header>

    <!-- jQuery -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script>

    <!-- Script to Activate the Carousel -->
    <script>
    $('.carousel').carousel({
        wrap: false,
        interval: false
    })
    </script>

</body>

</html>
EOF;

    protected $indicatorTemplate = <<<EOF
<li data-target="#steps" data-slide-to="{{step}}" {{isActive}}></li>
EOF;

    protected $slidesTemplate = <<<EOF
<div class="item {{isActive}}">
    <div class="fill">
        <img src="{{image}}">
    </div>
    <div class="carousel-caption {{isError}}">
        <h2>{{caption}}</h2>
        <small>scroll up and down to see the full page</small>
    </div>
</div>
EOF;

    static $events = [
        Events::SUITE_BEFORE => 'beforeSuite',
        Events::TEST_BEFORE  => 'before',
        Events::TEST_ERROR => 'persist',
        Events::TEST_FAIL => 'persist',
        Events::TEST_SUCCESS => 'cleanup',
        Events::STEP_AFTER   => 'afterStep',
    ];

    /**
     * @var WebDriver
     */
    protected $webDriverModule;
    protected $dir;
    protected $slides = [];
    protected $stepNum = 0;
    protected $seed;



    public function beforeSuite()
    {
        $this->webDriverModule = null;
        if (!$this->hasModule($this->config['module'])) {
            return;
        }
        $this->seed = time();
        $this->webDriverModule = $this->getModule($this->config['module']);
        $this->writeln(sprintf("⏺ <bold>Recording</bold> ⏺ step-by-step screenshots will be saved to <info>%s</info>", codecept_output_dir()));
        $this->writeln("Directory Format: <debug>record_{testname}_{$this->seed}</debug> ----");
    }

    public function before(TestEvent $e)
    {
        $this->dir = null;
        $this->stepNum = 0;
        $this->slides = [];
        $testName = str_replace(['::', '\\', '/'], ['.', '', ''], TestCase::getTestSignature($e->getTest()));
        $this->dir = codecept_output_dir()."recorded_{$testName}_".$this->seed;
        mkdir($this->dir);
    }

    public function cleanup(TestEvent $e)
    {
        if (!$this->webDriverModule or !$this->dir) {
            return;
        }
        if (!$this->config['delete_successful']) {
            $this->persist($e);
            return;
        }

        // deleting successfully executed tests
        FileSystem::deleteDir($this->dir);
    }

    public function persist(TestEvent $e)
    {
        if (!$this->webDriverModule or !$this->dir) {
            return;
        }
        $indicatorHtml = '';
        $slideHtml = '';
        foreach ($this->slides as $i => $step) {
            $indicatorHtml .= (new Template($this->indicatorTemplate))
                ->place('step', (int)$i)
                ->place('isActive', (int)$i ? '' : 'class="active"')
                ->produce();

            $slideHtml .= (new Template($this->slidesTemplate))
                ->place('image', $i)
                ->place('caption', $step->getHtml('#3498db'))
                ->place('isActive', (int)$i ? '' : 'active')
                ->place('isError', $step->hasFailed() ? 'error' : '')
                ->produce();
        }

        $html = (new Template($this->template))
            ->place('indicators', $indicatorHtml)
            ->place('slides', $slideHtml)
            ->place('test', ucfirst($e->getTest()->getFeature()))
            ->produce();

        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'index.html', $html);
    }

    public function afterStep(StepEvent $e)
    {
        if (!$this->webDriverModule or !$this->dir) {
            return;
        }

        $filename = str_pad($this->stepNum, 3, "0", STR_PAD_LEFT).'.png';
        $this->webDriverModule->_saveScreenshot($this->dir . DIRECTORY_SEPARATOR . $filename);
        $this->stepNum++;
        $this->slides[$filename] = $e->getStep();
    }

}