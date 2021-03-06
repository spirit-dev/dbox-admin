<?php
/**
 * Copyright (c) 2016. Spirit-Dev
 * Licensed under GPLv3 GNU License - http://www.gnu.org/licenses/gpl-3.0.html
 *    _             _
 *   /_`_  ._._/___/ | _
 * . _//_//// /   /_.'/_'|/
 *    /
 *
 * Since 2K10 until today
 *
 * Hex            53 70 69 72 69 74 2d 44 65 76
 *
 * By             Jean Bordat
 * Twitter        @Ji_Bay_
 * Mail           <bordat.jean@gmail.com>
 *
 * File           SpiritDevDBoxAdminExtension.php
 * Updated the    28/07/16 17:15
 */

namespace SpiritDev\Bundle\DBoxAdminBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class SpiritDevDBoxAdminExtension extends Extension {
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container) {
        $configuration = new Configuration();
        $config = array();
        foreach ($configs as $subConfig) {
            $config = array_merge($config, $subConfig);
        }
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        if (!isset($config['assets_root_path'])) {
            throw new \InvalidArgumentException('The "assets_root_path" option must be set');
        } else {
            $container->setParameter('spirit_dev_d_box_admin.assets_root_path', $config['assets_root_path']);
        }

        if (!isset($config['commit_assets'])) {
            throw new \InvalidArgumentException('The "commit_assets" option must be set');
        } else {
            $container->setParameter('spirit_dev_d_box_admin.commit_assets', $config['commit_assets']);
        }
    }
}
