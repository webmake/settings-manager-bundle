<?php

declare(strict_types=1);

namespace Helis\SettingsManagerBundle\DependencyInjection;

use Helis\SettingsManagerBundle\DataCollector\SettingsCollector;
use Helis\SettingsManagerBundle\Provider\Factory\SimpleSettingsProviderFactory;
use Helis\SettingsManagerBundle\Provider\LazyReadableSimpleSettingsProvider;
use Helis\SettingsManagerBundle\Provider\SettingsProviderInterface;
use Helis\SettingsManagerBundle\Settings\SettingsAccessControl;
use Helis\SettingsManagerBundle\Settings\SettingsManager;
use Helis\SettingsManagerBundle\Settings\SettingsRouter;
use Helis\SettingsManagerBundle\Settings\SettingsStore;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

class HelisSettingsManagerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
        $loader->load('serializer.yml');
        $loader->load('validators.yml');
        $loader->load('twig.yml');
        $loader->load('command.yml');

        $bundles = $container->getParameter('kernel.bundles');

        if (isset($bundles['KnpMenuBundle'])) {
            $loader->load('menu.yml');
        }

        if ($config['profiler']['enabled']) {
            $this->loadDataCollector($config, $container);
        }

        if ($config['logger']['enabled']) {
            $container->setAlias('settings_manager.logger', $config['logger']['service_id']);
        }

        $this->loadSettingsAccessControl($config, $container);
        $this->loadSettingsManager($config, $container);
        $this->loadSettingsRouter($config, $container);
        $this->loadSimpleProvider($config, $container);
    }

    public function loadSettingsRouter(array $config, ContainerBuilder $container): void
    {
        $container
            ->register(SettingsRouter::class, SettingsRouter::class)
            ->setPublic(true)
            ->setArgument(0, new Reference(SettingsManager::class))
            ->setArgument(1, new Reference(SettingsStore::class))
            ->setArgument(2, new Reference('event_dispatcher'));
    }

    private function loadSettingsAccessControl(array $config, ContainerBuilder $container): void
    {
        $container
            ->register(SettingsAccessControl::class, SettingsAccessControl::class)
            ->setArgument('$authorizationChecker', new Reference(AuthorizationCheckerInterface::class))
            ->addMethodCall('setEnabled', [$config['access_control']['enabled']])
            ->setPublic(false);
    }

    private function loadSettingsManager(array $config, ContainerBuilder $container): void
    {
        $container
            ->register(SettingsManager::class, SettingsManager::class)
            ->setArgument('$eventDispatcher', new Reference(EventDispatcherInterface::class))
            ->setPublic(true)
            ->setLazy(true)
            ->addMethodCall('setLogger', [
                new Reference(
                    'settings_manager.logger',
                    ContainerInterface::IGNORE_ON_INVALID_REFERENCE
                ),
            ]);
    }

    private function loadSimpleProvider(array $config, ContainerBuilder $container): void
    {
        $settings = array_merge(
            $config['settings'],
            $this->loadSettingsFromFiles($config['settings_files'], $container)
        );

        if (!$config['settings_config']['lazy']) {
            $container
                ->register('settings_manager.provider.config', SimpleSettingsProviderFactory::class)
                ->setArguments([new Reference('settings_manager.serializer'), $settings, true])
                ->setPublic(false)
                ->addTag('settings_manager.provider_factory', [
                    'provider' => SettingsProviderInterface::DEFAULT_PROVIDER,
                    'priority' => $config['settings_config']['priority'],
                ]);

            return;
        }

        $normalizedDomains = [];
        $normalizedSettingsByDomain = [];

        foreach ($settings as $setting) {
            $normalizedDomains[$setting['domain']['name']] = $setting['domain'];
            $normalizedSettingsByDomain[$setting['domain']['name']][$setting['name']] = $setting;
        }

        $container
            ->register('settings_manager.provider.config', LazyReadableSimpleSettingsProvider::class)
            ->setArguments([
                new Reference('settings_manager.serializer'),
                $normalizedSettingsByDomain,
                $normalizedDomains,
            ])
            ->setPublic(false)
            ->addTag('settings_manager.provider', [
                'provider' => SettingsProviderInterface::DEFAULT_PROVIDER,
                'priority' => $config['settings_config']['priority'],
            ]);
    }

    private function loadSettingsFromFiles(array $files, ContainerBuilder $container): array
    {
        $configuration = new Configuration();
        $settings = [];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $fileContents = Yaml::parseFile(
                    $file,
                    Yaml::PARSE_CONSTANT | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE
                );
                $processedContents = $this->processConfiguration(
                    $configuration,
                    ['helis_settings_manager' => ['settings' => $fileContents]]
                );

                $settings = array_merge($settings, $processedContents['settings']);
                $container->addResource(new FileResource($file));
            }
        }

        return $settings;
    }

    private function loadDataCollector(array $config, ContainerBuilder $container): void
    {
        $container
            ->register(SettingsCollector::class, SettingsCollector::class)
            ->setArgument('$settingsStore', new Reference(SettingsStore::class))
            ->setPublic(false)
            ->addTag('data_collector', [
                'id' => 'settings_manager.settings_collector',
                'template' => '@HelisSettingsManager/profiler/profiler.html.twig',
            ]);
    }
}
