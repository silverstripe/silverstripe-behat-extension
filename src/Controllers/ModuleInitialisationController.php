<?php

/*
 * This file is part of the Behat Testwork.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SilverStripe\BehatExtension\Controllers;

use Behat\Testwork\Cli\Controller;
use Behat\Testwork\Suite\SuiteBootstrapper;
use Behat\Testwork\Suite\SuiteRepository;
use Exception;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Model\ArrayData;
use SilverStripe\View\SSTemplateEngine;
use SilverStripe\View\ViewLayerData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Initialises module test environment.
 *
 * Replaces:
 * @see \Behat\Testwork\Suite\Cli\InitializationController
 */
class ModuleInitialisationController implements Controller
{
    use ModuleCommandTrait;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var SuiteRepository
     */
    private $repository;

    /**
     * @var SuiteBootstrapper
     */
    private $bootstrapper;

    /**
     * Initializes controller.
     *
     * @param ContainerInterface $container
     * @param SuiteRepository $repository
     * @param SuiteBootstrapper $bootstrapper
     */
    public function __construct(
        ContainerInterface $container,
        SuiteRepository $repository,
        SuiteBootstrapper $bootstrapper
    ) {
        $this->container = $container;
        $this->repository = $repository;
        $this->bootstrapper = $bootstrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function configure(Command $command)
    {
        $command->addOption(
            '--init',
            null,
            InputOption::VALUE_NONE,
            'Initialize all registered test suites.'
        );
        $command->addOption(
            '--namespace',
            null,
            InputOption::VALUE_REQUIRED,
            'Set namespace for fixture'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('init')) {
            return null;
        }

        // If module not specified, bootstrap via legacy behaviour
        if (!$input->hasArgument('module')) {
            return $this->baseExecute($output);
        }

        if (!$input->hasOption('namespace')) {
            throw new \BadMethodCallException(
                "--namespace is required if --init is invoked with a module "
                . "This should just be your root Vendor\\Module namespace (e.g. 'SilverStripe\\CMS')"
            );
        }

        // Get module
        $moduleName = $input->getArgument('module');
        $module = $this->getModule($moduleName);
        $namespaceRoot = $input->getOption('namespace');

        // Init components
        $this->initFeaturesPath($output, $module);
        $this->initClassPath($output, $module, $namespaceRoot);
        $this->initConfig($output, $module, $namespaceRoot);

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @return int
     */
    protected function baseExecute(OutputInterface $output)
    {
        $suites = $this->repository->getSuites();
        $this->bootstrapper->bootstrapSuites($suites);

        $output->write(PHP_EOL);

        return 0;
    }

    protected function initFeaturesPath(OutputInterface $output, Module $module)
    {
        // Create feature_path
        $features = $this->container->getParameter('silverstripe_extension.context.features_path');
        $fullPath = $module->getResource($features)->getPath();
        if (is_dir($fullPath ?? '')) {
            return;
        }
        mkdir($fullPath ?? '', 0777, true);
        $output->writeln(
            "<info>{$fullPath}</info> - <comment>place your *.feature files here</comment>"
        );

        // Create dummy feature
        $engine = SSTemplateEngine::create(__DIR__.'/../../templates/SkeletonFeature.ss');
        $featureContent = $engine->render(ViewLayerData::create([]));
        file_put_contents($fullPath.'/placeholder.feature', $featureContent);
    }

    /**
     * Init class_path
     *
     * @param OutputInterface $output
     * @param Module $module
     * @param string $namespaceRoot
     * @throws Exception
     */
    protected function initClassPath(OutputInterface $output, Module $module, $namespaceRoot)
    {
        $classesPath = $this->container->getParameter('silverstripe_extension.context.class_path');
        $dirPath = $module->getResource($classesPath)->getPath();
        if (!is_dir($dirPath ?? '')) {
            mkdir($dirPath ?? '', 0777, true);
        }

        // Scaffold base context file
        $classPath = "{$dirPath}/FeatureContext.php";
        if (is_file($classPath ?? '')) {
            return;
        }

        // Build class name
        $fullNamespace = $this->getFixtureNamespace($namespaceRoot);
        $class = $this->getFixtureClass($namespaceRoot);

        // Render class
        $obj = ArrayData::create([
            'Namespace' => $fullNamespace,
            'ClassName' => $class,
        ]);
        $engine = SSTemplateEngine::create(__DIR__.'/../../templates/FeatureContext.ss');
        $classContent = $engine->render(ViewLayerData::create($obj));
        file_put_contents($classPath ?? '', $classContent);

        // Log
        $output->writeln(
            "<info>{$classPath}</info> - <comment>place your feature related code here</comment>"
        );

        // Add to composer json
        $composerFile = $module->getResource('composer.json')->getPath();
        if (!file_exists($composerFile ?? '')) {
            return;
        }

        // Add autoload directive to composer
        $composerData = json_decode(file_get_contents($composerFile ?? '') ?? '', true);
        if (json_last_error()) {
            throw new Exception(json_last_error_msg());
        }
        if (!isset($composerData['autoload'])) {
            $composerData['autoload'] = [];
        }
        if (!isset($composerData['autoload']['psr-4'])) {
            $composerData['autoload']['psr-4'] = [];
        }
        $composerData['autoload']['psr-4']["{$fullNamespace}\\"] = $classesPath;
        file_put_contents(
            $composerFile ?? '',
            json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $output->writeln(
            "<info>{$composerFile}</info> - <comment>psr-4 autload for this class added</comment>"
        );
    }

    /**
     * Get fixture class name
     *
     * @param string $namespaceRoot
     * @return string
     */
    protected function getFixtureClass($namespaceRoot)
    {
        $fullNamespace = $this->getFixtureNamespace($namespaceRoot);
        return $fullNamespace . '\FeatureContext';
    }

    /**
     * @param string $namespaceRoot
     * @return string
     */
    protected function getFixtureNamespace($namespaceRoot)
    {
        $namespaceSuffix = $this->container->getParameter('silverstripe_extension.context.namespace_suffix');
        return trim($namespaceRoot ?? '', '/\\') . '\\' . $namespaceSuffix;
    }

    /**
     * Init config file behat.yml
     *
     * @param OutputInterface $output
     * @param Module $module
     * @param string $namespaceRoot
     */
    protected function initConfig($output, $module, $namespaceRoot)
    {
        $configPath = $module->getResource('behat.yml')->getPath();
        if (file_exists($configPath ?? '')) {
            return;
        }
        $class = $this->getFixtureClass($namespaceRoot);

        // load config from yml
        $features = $this->container->getParameter('silverstripe_extension.context.features_path');
        $data = Yaml::parse(file_get_contents(__DIR__.'/../../templates/config-base.yml'));
        $shortname = $module->getShortName();
        $data['default']['suites'][$shortname] = [
            'paths' => [
                "%paths.modules.{$shortname}%/{$features}",
            ],
            'contexts' => [
                $class,
                \SilverStripe\Framework\Tests\Behaviour\CmsFormsContext::class,
                \SilverStripe\Framework\Tests\Behaviour\CmsUiContext::class,
                \SilverStripe\BehatExtension\Context\BasicContext::class,
                \SilverStripe\BehatExtension\Context\EmailContext::class,
                \SilverStripe\BehatExtension\Context\LoginContext::class,
                [
                    \SilverStripe\BehatExtension\Context\FixtureContext::class => [
                        '%paths.modules.framework%/tests/behat/features/files/'
                    ]
                ]
            ]
        ];
        file_put_contents($configPath ?? '', Yaml::dump($data, 99999999, 2));

        $output->writeln(
            "<info>{$configPath}</info> - <comment>default behat.yml created</comment>"
        );
    }
}
