<?php declare(strict_types=1);

namespace Moorl\MerchantFinder;

use Doctrine\DBAL\Connection;
use MoorlFoundation\Core\PluginLifecycleHelper;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MoorlMerchantFinder extends Plugin
{
    final public const NAME = 'MoorlMerchantFinder';
    final public const DATA_CREATED_AT = '2003-03-03 03:03:10.000';
    final public const CMS_PAGE = 'moorl_merchant';
    final public const CMS_PAGE_ID = 'd294117e81a9dee634b92f190d7719a3';
    final public const CMS_PAGE_MERCHANT_DEFAULT_ID = 'fa05048f53068a5ec29e2c9aea4e8c40';
    final public const PLUGIN_TABLES = [
        'moorl_merchant',
        'moorl_zipcode',
        'moorl_merchant_category',
        'moorl_merchant_product_manufacturer',
        'moorl_merchant_tag',
        'moorl_merchant_oh',
        'moorl_merchant_translation',
        'moorl_merchant_stock',
        'moorl_merchant_customer',
        'moorl_merchant_marker',
        'moorl_merchant_area',
        'moorl_merchant_sales_channel'
    ];
    final public const SHOPWARE_TABLES = [
        'media_folder',
        'moorl_sorting',
        'cms_page',
        'cms_page_translation',
        'cms_section',
        'cms_block',
        'category',
        'category_translation',
        'product',
        'product_translation',
        'product_category',
        'product_visibility',
        'seo_url_template',
        'acl_role',
    ];
    final public const INHERITANCES = [
        'product' => ['MoorlMerchants', 'MoorlMerchantStocks'],
        'sales_channel' => ['MoorlMerchants'],
        'customer' => ['MoorlMerchants'],
        'order_line_item' => ['MoorlMerchantStock'],
    ];

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        PluginLifecycleHelper::build($container, __DIR__ . '/ElasticSearch');
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        PluginLifecycleHelper::update(self::class, $this->container);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        PluginLifecycleHelper::update(self::class, $this->container);

        $connection = $this->container->get(Connection::class);
        $connection->executeStatement("UPDATE `seo_url_template` SET `route_name` = 'frontend.moorl.merchant.detail' WHERE `route_name` = 'moorl.merchant.detail';");
        $connection->executeStatement("UPDATE `seo_url` SET `route_name` = 'frontend.moorl.merchant.detail' WHERE `route_name` = 'moorl.merchant.detail';");
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }

        PluginLifecycleHelper::uninstall(self::class, $this->container);
    }
}
