<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantDefinition;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\Exception\PageNotFoundException;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class MerchantDetailRoute
{
    public function __construct(
        private readonly SalesChannelRepository $merchantRepository,
        private readonly SalesChannelCmsPageLoaderInterface $cmsPageLoader,
        private readonly MerchantDefinition $merchantDefinition,
        private readonly SystemConfigService $systemConfigService
    )
    {
    }

    public function getDecorated(): never
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @throws PageNotFoundException
     */
    public function load(string $merchantId, Request $request, SalesChannelContext $context, Criteria $criteria): MerchantDetailRouteResponse
    {
        if (!$this->systemConfigService->get('MoorlMerchantFinder.config.detailPage', $context->getSalesChannelId())) {
            throw new PageNotFoundException($merchantId);
        }

        $criteria->setIds([$merchantId]);

        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addAssociation('marker');
        $criteria->addAssociation('salutation');
        $criteria->addAssociation('categories');

        /** @var MerchantEntity $merchant */
        $merchant = $this->merchantRepository
            ->search($criteria, $context)
            ->first();

        if (!$merchant) {
            throw new PageNotFoundException($merchantId);
        }

        $defaultCmsPageId = $this->systemConfigService->get(
            'MoorlMerchantFinder.config.cmsPageId',
            $context->getSalesChannelId()
        );

        $pageId = $merchant->getCmsPageId($defaultCmsPageId);
        $slotConfig = $merchant->getTranslation('slotConfig');

        if (!$pageId) {
            return new MerchantDetailRouteResponse($merchant);
        }

        $resolverContext = new EntityResolverContext($context, $request, $this->merchantDefinition, $merchant);

        $pages = $this->cmsPageLoader->load(
            $request,
            $this->createCriteria($pageId, $request),
            $context,
            $slotConfig,
            $resolverContext
        );

        if (!$pages->has($pageId)) {
            throw new PageNotFoundException($pageId);
        }

        $merchant->setCmsPage($pages->get($pageId));

        return new MerchantDetailRouteResponse($merchant);
    }

    private function createCriteria(string $pageId, Request $request): Criteria
    {
        $criteria = new Criteria([$pageId]);
        $criteria->setTitle('merchant_detail::cms-page');

        $slots = $request->get('slots');

        if (\is_string($slots)) {
            $slots = explode('|', $slots);
        }

        if (!empty($slots) && \is_array($slots)) {
            $criteria
                ->getAssociation('sections.blocks')
                ->addFilter(new EqualsAnyFilter('slots.id', $slots));
        }

        return $criteria;
    }
}
