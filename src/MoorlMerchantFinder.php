<?php declare(strict_types=1);

namespace Moorl\MerchantFinder;

use Doctrine\DBAL\Connection;
use MoorlFoundation\Core\PluginFoundation;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class MoorlMerchantFinder extends Plugin
{
    private static $_defaults = [
        'allowedSearchCountryCodes' => ['de', 'at', 'ch'],
        'nominatim' => 'https://nominatim.openstreetmap.org/search',
    ];

    public static function getDefault($key)
    {
        return static::$_defaults[$key];
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext); // TODO: Change the autogenerated stub
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        if ($context->keepUserData()) {
            return;
        }

        /* @var $foundation PluginFoundation */
        $foundation = $this->container->get(PluginFoundation::class);
        $foundation->setContext($context->getContext());

        $foundation->dropTables([
            'moorl_merchant_tag',
            'moorl_merchant_category',
            'moorl_merchant_product_manufacturer',
            'moorl_merchant',
            'moorl_merchant_oh',
            'moorl_zipcode'
        ]);

        $foundation->removeCmsBlocks(['moorl-merchant-finder-basic']);
    }
}
