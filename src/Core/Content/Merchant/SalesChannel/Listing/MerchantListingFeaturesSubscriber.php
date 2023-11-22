<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Listing;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantDefinition;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Events\MerchantListingCriteriaEvent;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Events\MerchantListingResultEvent;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Events\MerchantSearchCriteriaEvent;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Events\MerchantSearchResultEvent;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\Events\MerchantSuggestCriteriaEvent;
use MoorlFoundation\Core\Service\LocationServiceV2;
use MoorlFoundation\Core\Service\SortingService;
use MoorlFoundation\Core\System\EntityListingFeaturesSubscriberExtension;
use Shopware\Core\Content\Product\SalesChannel\Listing\FilterCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

class MerchantListingFeaturesSubscriber extends EntityListingFeaturesSubscriberExtension implements EventSubscriberInterface
{
    public function __construct(
        SortingService $sortingService,
        LocationServiceV2 $locationServiceV2,
        private readonly SystemConfigService $systemConfigService
    )
    {
        $this->sortingService = $sortingService;
        $this->locationServiceV2 = $locationServiceV2;
        $this->entityName = MerchantDefinition::ENTITY_NAME;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MerchantListingCriteriaEvent::class => [
                ['handleListingRequest', 100],
                ['handleFlags', -100],
            ],
            MerchantSuggestCriteriaEvent::class => [
                ['handleFlags', -100],
            ],
            MerchantSearchCriteriaEvent::class => [
                ['handleSearchRequest', 100],
                ['handleFlags', -100],
            ],
            MerchantListingResultEvent::class => [
                ['handleResult', 100]
            ],
            MerchantSearchResultEvent::class => 'handleResult',
        ];
    }

    protected function getFilters(Request $request, SalesChannelContext $context): FilterCollection
    {
        $filters = new FilterCollection();
        $salesChannelId = $context->getSalesChannelId();

        if ($this->systemConfigService->get('MoorlMerchantFinder.config.merchantRadiusFilter', $salesChannelId)) {
            $filters->add($this->getRadiusFilter($request, $context));
        }
        if ($this->systemConfigService->get('MoorlMerchantFinder.config.merchantManufacturerFilter', $salesChannelId)) {
            $filters->add($this->getManufacturerFilter($request));
        }
        if ($this->systemConfigService->get('MoorlMerchantFinder.config.merchantCountryFilter', $salesChannelId)) {
            $filters->add($this->getCountryFilter(
                $request,
                $this->systemConfigService->get('MoorlMerchantFinder.config.merchantCountryFilterValues', $salesChannelId)
            ));
        }
        if ($this->systemConfigService->get('MoorlMerchantFinder.config.merchantTagFilter', $salesChannelId)) {
            $filters->add($this->getTagFilter($request));
        }

        return $filters;
    }
}
