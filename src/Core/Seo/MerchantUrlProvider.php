<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Seo;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantCollection;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantDefinition;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantEntity;
use Moorl\MerchantFinder\Core\Content\Merchant\SalesChannel\MerchantAvailableFilter;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Service\ConfigHandler;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class MerchantUrlProvider extends AbstractUrlProvider
{
    final public const CHANGE_FREQ = 'weekly';

    public function __construct(
        private readonly ConfigHandler $configHandler,
        private readonly Connection $connection,
        private readonly MerchantDefinition $definition,
        private readonly IteratorFactory $iteratorFactory,
        private readonly RouterInterface $router,
        private readonly EntityRepository $repository,
        private readonly SystemConfigService $systemConfigService
    )
    {
    }

    public function getDecorated(): AbstractUrlProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function getName(): string
    {
        return 'moorl_merchant_page';
    }

    /**
     * {@inheritdoc}
     */
    public function getUrls(SalesChannelContext $salesChannelContext, int $limit, ?int $offset = null): UrlResult
    {
        if (!$this->systemConfigService->get('MoorlMerchantFinder.config.detailPage', $salesChannelContext->getSalesChannelId())) {
            return new UrlResult([], null);
        }

        $collection = $this->getCollection($salesChannelContext, $limit, $offset);

        if ($collection->count() === 0) {
            return new UrlResult([], null);
        }

        $seoUrls = $this->getSeoUrls($collection->getIds(), 'frontend.moorl.merchant.detail', $salesChannelContext, $this->connection);
        $seoUrls = FetchModeHelper::groupUnique($seoUrls);

        $urls = [];
        $url = new Url();
        foreach ($collection as $entity) {
            $lastMod = $entity->getUpdatedAt() ?: $entity->getCreatedAt();

            $newUrl = clone $url;
            if (isset($seoUrls[$entity->getId()])) {
                $newUrl->setLoc($seoUrls[$entity->getId()]['seo_path_info']);
            } else {
                $newUrl->setLoc($this->router->generate('frontend.moorl.merchant.detail', ['merchantId' => $entity->getId()], UrlGeneratorInterface::ABSOLUTE_PATH));
            }

            $newUrl->setLastmod($lastMod);
            $newUrl->setChangefreq(self::CHANGE_FREQ);
            $newUrl->setResource(MerchantEntity::class);
            $newUrl->setIdentifier($entity->getId());

            $urls[] = $newUrl;
        }

        if (\count($urls) < $limit) { // last run
            $nextOffset = null;
        } elseif ($offset === null) { // first run
            $nextOffset = $limit;
        } else { // 1+n run
            $nextOffset = $offset + $limit;
        }

        return new UrlResult($urls, $nextOffset);
    }

    private function getCollection(SalesChannelContext $salesChannelContext, int $limit, ?int $offset, Criteria $collectionCriteria = null): MerchantCollection
    {
        if (!$collectionCriteria) {
            $collectionCriteria = new Criteria();
            $collectionCriteria->addFilter(new MerchantAvailableFilter($salesChannelContext));
        }

        $collectionCriteria->setLimit($limit);

        if ($offset !== null) {
            $collectionCriteria->setOffset($offset);
        }

        /** @var MerchantCollection $collection */
        $collection = $this->repository->search($collectionCriteria, $salesChannelContext->getContext())->getEntities();

        return $collection;
    }
}
