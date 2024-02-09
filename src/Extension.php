<?php

namespace SilverStripe\BehatExtension;

use Behat\Testwork\Call\ServiceContainer\CallExtension;
use Behat\Testwork\Cli\ServiceContainer\CliExtension;
use Behat\Testwork\Suite\Cli\InitializationController;
use Behat\Testwork\Suite\ServiceContainer\SuiteExtension;
use SilverStripe\BehatExtension\Controllers\ModuleInitialisationController;
use SilverStripe\BehatExtension\Controllers\ModuleSuiteLocator;
use SilverStripe\BehatExtension\Utility\RetryableCallHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\ServiceContainer\Extension as ExtensionInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/*
 * This file is part of the SilverStripe\BehatExtension
 *
 * (c) Michał Ochman <ochman.d.michal@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * SilverStripe extension for Behat class.
 *
 * Configured by adding `SilverStripe\BehatExtension\Extension` to your behat.yml
 *
 * @author Michał Ochman <ochman.d.michal@gmail.com>
 */
class Extension implements ExtensionInterface
{
    /**
    * Extension configuration ID.
    */
    const SILVERSTRIPE_ID = 'silverstripe_extension';

    /**
    * {@inheritDoc}
    */
    public function getConfigKey()
    {
        return self::SILVERSTRIPE_ID;
    }

    public function initialize(ExtensionManager $extensionManager)
    {
        // Find PHPUnit assertion implementations

        $found = false;

        $options = [
            BASE_PATH . '/vendor/sminnee/phpunit/src/Framework/Assert/Functions.php',
            BASE_PATH . '/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php'
        ];

        foreach ($options as $file) {
            if (file_exists($file ?? '')) {
                require_once $file;
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new RuntimeException('Could not find PHPUnit installation');
        }
    }

    public function load(ContainerBuilder $container, array $config)
    {
        // Load yml config
        if ($this->getShouldBootstrap($container)) {
            $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../config'));
            $loader->load('silverstripe.yml');
        }

        // Add CLI substitutions
        $this->loadSuiteLocator($container);
        $this->loadBootstrapController($container);
        $this->loadCallHandlers($container, $config['error_reporting'], $config['retry_seconds']);

        // Set various paths
        $container->setParameter('silverstripe_extension.admin_url', $config['admin_url']);
        $container->setParameter('silverstripe_extension.login_url', $config['login_url']);
        $container->setParameter('silverstripe_extension.screenshot_path', $config['screenshot_path']);
        $container->setParameter('silverstripe_extension.ajax_timeout', $config['ajax_timeout']);
        if (isset($config['ajax_steps'])) {
            $container->setParameter('silverstripe_extension.ajax_steps', $config['ajax_steps']);
        }
        if (isset($config['region_map'])) {
             $container->setParameter('silverstripe_extension.region_map', $config['region_map']);
        }
        $container->setParameter('silverstripe_extension.bootstrap_file', $config['bootstrap_file']);
    }

    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container)
    {
        if ($this->getShouldBootstrap($container)) {
            $corePass = new Compiler\CoreInitializationPass();
            $corePass->process($container);
        }
    }

    public function configure(ArrayNodeDefinition $builder)
    {
        $builder->
            children()->
                scalarNode('screenshot_path')->
                    defaultNull()->
                end()->
                arrayNode('region_map')->
                    useAttributeAsKey('key')->
                    prototype('variable')->end()->
                end()->
                scalarNode('admin_url')->
                    defaultValue('/admin/')->
                end()->
                scalarNode('login_url')->
                    defaultValue('/Security/login')->
                end()->
                scalarNode('ajax_timeout')->
                    defaultValue(5000)->
                end()->
                scalarNode('bootstrap_file')->
                    defaultNull()->
                end()->
                scalarNode('error_reporting')->
                    info('Call executor will catch exceptions matching this level')->
                    defaultValue(E_ALL | E_STRICT)->
                end()->
                scalarNode('retry_seconds')->
                    info('Number of seconds that @retry tags will retry for')->
                    defaultValue(2)->
                end()->
                arrayNode('ajax_steps')->
                    defaultValue(array(
                        'go to',
                        'follow',
                        'press',
                        'click',
                        'submit'
                    ))->
                    prototype('scalar')->
                end()->
            end()->
        end();
    }

    /**
     * Loads module suite locator.
     * This is responsible for bootstrapping the module config
     * for running tests.
     *
     * @param ContainerBuilder $container
     */
    protected function loadSuiteLocator(ContainerBuilder $container)
    {
        $definition = new Definition(ModuleSuiteLocator::class, [
            $container,
            new Reference(SuiteExtension::REGISTRY_ID)
        ]);
        $definition->addTag(CliExtension::CONTROLLER_TAG, ['priority' => 9999]);
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.suite', $definition);
    }

    /**
     * Loads suite bootstrap controller.
     * This is responsible for invoking --init commands for modules.
     * Replaces the core behat InitializationController
     *
     * @see InitializationController
     * @param ContainerBuilder $container
     */
    protected function loadBootstrapController(ContainerBuilder $container)
    {
        $definition = new Definition(ModuleInitialisationController::class, [
            $container,
            new Reference(SuiteExtension::REGISTRY_ID),
            new Reference(SuiteExtension::BOOTSTRAPPER_ID)
        ]);
        $definition->addTag(CliExtension::CONTROLLER_TAG, ['priority' => 900]);
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.initialization', $definition);
    }

    /**
     * Shivs in custom call handler
     *
     * @param ContainerBuilder $container
     * @param integer $errorReporting
     * @param int $retrySeconds
     */
    protected function loadCallHandlers(ContainerBuilder $container, $errorReporting, $retrySeconds)
    {
        $definition = new Definition(RetryableCallHandler::class, [$errorReporting, $retrySeconds]);
        $definition->addTag(CallExtension::CALL_HANDLER_TAG, ['priority' => 50]);
        $container->setDefinition(CallExtension::CALL_HANDLER_TAG . '.runtime', $definition);
    }

    /**
     * Check whether the extension should bootstrap or not.
     * The extension should always bootstrap unless the `-h` or `--help` option is passed.
     */
    private function getShouldBootstrap(ContainerBuilder $container): bool
    {
        if (!$container->has('cli.input')) {
            // If the input isn't there for some bizarre reason, just assume we should bootstrap.
            return true;
        }

        /** @var ArgvInput $input */
        $input = $container->get('cli.input');
        return !$input->hasParameterOption(['--help', '-h']);
    }
}
