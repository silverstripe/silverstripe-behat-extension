<?php

namespace SilverStripe\BehatExtension\Compiler;

use InvalidArgumentException;
use SilverStripe\Core\Environment;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Behat\SilverStripe container compilation pass.
 * Passes Base URL available in MinkExtension config.
 * Used for the {@link \SilverStripe\BehatExtension\MinkExtension} subclass.
 *
 * @author Michał Ochman <ochman.d.michal@gmail.com>
 */
class MinkExtensionBaseUrlPass implements CompilerPassInterface
{
    /**
     * Passes MinkExtension's base_url parameter from environment variables if missing in behat.yml
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        // If base_url is set in behat.yml, we can just use that.
        $baseUrl = $container->getParameter('mink.base_url');
        if ($baseUrl) {
            return;
        }
        // Set url from environment
        $baseURL = Environment::getEnv('SS_BASE_URL');
        if (!$baseURL) {
            throw new InvalidArgumentException(
                '"base_url" not configured. Please specify it in your .env config with SS_BASE_URL'
            );
        }
        $container->setParameter('mink.base_url', $baseURL);

        // The Behat\MinkExtension\Extension class copies configuration into an internal hash,
        // we need to follow this pattern to propagate our changes.
        $parameters = $container->getParameter('mink.parameters');
        $parameters['base_url'] = $container->getParameter('mink.base_url');
        $container->setParameter('mink.parameters', $parameters);
    }
}
