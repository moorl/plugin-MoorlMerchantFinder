<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Data;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantDefinition;
use Moorl\MerchantFinder\Core\Seo\MerchantSeoUrlRoute;
use Moorl\MerchantFinder\MoorlMerchantFinder;
use MoorlFoundation\Core\System\DataExtension;
use MoorlFoundation\Core\System\DataInterface;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Doctrine\DBAL\Connection;

class Data extends DataExtension implements DataInterface
{
    public function __construct(private readonly Connection $connection, private readonly DefinitionInstanceRegistry $definitionInstanceRegistry)
    {
    }

    public function getTables(): ?array
    {
        return array_merge(
            $this->getShopwareTables(),
            $this->getPluginTables()
        );
    }

    public function getShopwareTables(): ?array
    {
        return MoorlMerchantFinder::SHOPWARE_TABLES;
    }

    public function getPluginTables(): ?array
    {
        return MoorlMerchantFinder::PLUGIN_TABLES;
    }

    public function getPluginName(): string
    {
        return MoorlMerchantFinder::NAME;
    }

    public function getCreatedAt(): string
    {
        return MoorlMerchantFinder::DATA_CREATED_AT;
    }

    public function getLocalReplacers(): array
    {
        return [
            '{CMS_PAGE_ID}' => MoorlMerchantFinder::CMS_PAGE_ID,
            '{MAIN_ENTITY}' => MerchantDefinition::ENTITY_NAME,
            '{SEO_ROUTE_NAME}' => MerchantSeoUrlRoute::ROUTE_NAME,
            '{SEO_DEFAULT_TEMPLATE}' => MerchantSeoUrlRoute::DEFAULT_TEMPLATE
        ];
    }

    public function getName(): string
    {
        return 'data';
    }

    public function getType(): string
    {
        return 'data';
    }

    public function getPath(): string
    {
        return __DIR__;
    }

    public function getPreInstallQueries(): array
    {
        return [
            "DELETE FROM `seo_url_template` WHERE `route_name` = 'moorl.merchant-finder.merchant.page';"
        ];
    }

    public function getInstallQueries(): array
    {
        return [
            "INSERT IGNORE INTO `seo_url_template` (`id`,`is_valid`,`route_name`,`entity_name`,`template`,`created_at`) VALUES (UNHEX('{ID:WILD_0}'),1,'{SEO_ROUTE_NAME}','{MAIN_ENTITY}','{SEO_DEFAULT_TEMPLATE}','{DATA_CREATED_AT}');"
        ];
    }
}
