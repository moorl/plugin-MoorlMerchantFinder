<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Seo;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantIndexerEvent;
use Shopware\Core\Content\Seo\SeoUrlUpdater;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SeoUrlUpdateListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SeoUrlUpdater $seoUrlUpdater,
        private readonly SystemConfigService $systemConfigService
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MerchantIndexerEvent::class => 'updateMerchantUrls'
        ];
    }

    public function updateMerchantUrls(MerchantIndexerEvent $event): void
    {
        if (!$this->systemConfigService->get('MoorlMerchantFinder.config.detailPage')) {
            return;
        }

        $this->seoUrlUpdater->update(MerchantSeoUrlRoute::ROUTE_NAME, $event->getIds());
    }
}
