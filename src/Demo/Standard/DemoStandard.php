<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Demo\Standard;

use Moorl\MerchantFinder\MoorlMerchantFinder;
use MoorlFoundation\Core\System\DataExtension;
use MoorlFoundation\Core\System\DataInterface;

class DemoStandard extends DataExtension implements DataInterface
{
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
            '{CMS_PAGE_ID}' => MoorlMerchantFinder::CMS_PAGE_ID
        ];
    }

    public function getName(): string
    {
        return 'standard';
    }

    public function getType(): string
    {
        return 'demo';
    }

    public function getPath(): string
    {
        return __DIR__;
    }

    public function getRemoveQueries(): array
    {
        return [];
    }

    public function getInstallConfig(): array
    {
        $globalReplacers = $this->getGlobalReplacers();

        return [
            "MoorlFoundation.config.fontawesome" => true,
            "MoorlMerchantFinder.config.category" => $globalReplacers['{DEMO_CATEGORY_0}'],
            "MoorlMerchantFinder.config.countryCode" => true,
            "MoorlMerchantFinder.config.defaultMarker" => $globalReplacers['{DEMO_WILD_101}'],
            "MoorlMerchantFinder.config.highlightMarker" => $globalReplacers['{DEMO_WILD_102}']
        ];
    }
}
