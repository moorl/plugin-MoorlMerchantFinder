<?php

namespace Moorl\MerchantFinder\Core\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Client;
use Moorl\MerchantFinder\Core\Content\Aggregate\MerchantStock\MerchantStockEntity;
use MoorlFoundation\Core\Framework\DataAbstractionLayer\Search\Sorting\DistanceFieldSorting;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantEntity;
use Moorl\MerchantFinder\Core\Content\OpeningHourCollection;
use Moorl\MerchantFinder\MoorlMerchantFinder;
use Moorl\MerchantFinder\Core\Event\MerchantsLoadedEvent;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class MerchantService
{
    private $repository;
    private $systemConfigService;
    private $session;
    private $connection;
    /**
     * @var int|null
     */
    private $merchantsCount;
    /**
     * @var array|null
     */
    private $myLocation;
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var OpeningHourCollection
     */
    private $openingHours;
    /**
     * @var EntityRepositoryInterface
     */
    private $openingHourRepo;
    /**
     * @var SalesChannelContext|null
     */
    private $salesChannelContext;
    /**
     * @var SeoUrlPlaceholderHandlerInterface
     */
    private $seoUrlReplacer;
    /**
     * @var DefinitionInstanceRegistry
     */
    private $definitionInstanceRegistry;
    /**
     * @var Context
     */
    private $context;
    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(
        RequestStack $requestStack,
        DefinitionInstanceRegistry $definitionInstanceRegistry,
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $repository,
        EntityRepositoryInterface $openingHourRepo,
        Connection $connection,
        Session $session,
        EventDispatcherInterface $eventDispatcher,
        SeoUrlPlaceholderHandlerInterface $seoUrlReplacer
    )
    {
        $this->requestStack = $requestStack;
        $this->definitionInstanceRegistry = $definitionInstanceRegistry;
        $this->systemConfigService = $systemConfigService;
        $this->repository = $repository;
        $this->openingHourRepo = $openingHourRepo;
        $this->connection = $connection;
        $this->session = $session;
        $this->eventDispatcher = $eventDispatcher;
        $this->seoUrlReplacer = $seoUrlReplacer;
    }

    /**
     * @return Context
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @param Context $context
     */
    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * @return array|null
     */
    public function getMyLocation(): ?array
    {
        return $this->myLocation;
    }

    /**
     * @param array|null $myLocation
     */
    public function setMyLocation(?array $myLocation): void
    {
        $this->myLocation = $myLocation;
    }

    /**
     * @return int|null
     */
    public function getMerchantsCount(): ?int
    {
        return $this->merchantsCount;
    }

    /**
     * @param int|null $merchantsCount
     */
    public function setMerchantsCount(?int $merchantsCount): void
    {
        $this->merchantsCount = $merchantsCount;
    }

    public function patchProductEntity(ProductEntity $product): void
    {
        $merchantStocks = $this->getMerchantsByProductId($product->getId());
        if (!$merchantStocks) {
            return;
        }
        $product->addExtension('MoorlMerchantStocks', $merchantStocks);
    }

    public function getMerchantsByProductId(string $productId): EntityCollection
    {
        // merchant stock extension
        $repo = $this->definitionInstanceRegistry->getRepository('moorl_merchant_stock');
        $criteria = new Criteria();
        $criteria->addAssociation('merchant');
        $criteria->addAssociation('deliveryTime');
        $criteria->addFilter(new EqualsFilter('productId', $productId));
        $criteria->addFilter(new MultiFilter('OR', [
            new EqualsFilter('merchant.salesChannelId', null),
            new EqualsFilter('merchant.salesChannelId', $this->getSalesChannelContext()->getSalesChannel()->getId())
        ]));
        return $repo->search($criteria, $this->getContext())->getEntities();
    }

    public function getMerchantStock(string $merchantStockId): ?MerchantStockEntity
    {
        return $this->definitionInstanceRegistry
            ->getRepository('moorl_merchant_stock')
            ->search((new Criteria([$merchantStockId]))->addAssociation('merchant'), $this->getContext())
            ->first();
    }

    public function updateOrderLineItems(OrderEntity $order): void
    {
        $update = [];
        $lineItems = $order->getLineItems();

        if ($lineItems->count() == 0) {
            return;
        }

        foreach ($lineItems as $lineItem) {
            $pl = $lineItem->getPayload();
            if (!$pl || !isset($pl['MoorlMerchantStock'])) {
                continue;
            }
            $pl = $pl['MoorlMerchantStock'];

            $customFields = $lineItem->getCustomFields() ?: [];

            if (isset($pl)) {
                $customFields = array_merge($customFields, $pl);

                $this->updateOrderMerchantStock($pl, $lineItem);
            }
            $update[] = [
                'id' => $lineItem->getId(),
                'customFields' => $customFields
            ];
        }

        if (count($update) != 0) {
            $this->definitionInstanceRegistry->getRepository('order_line_item')->update($update, $this->getContext());
        }
    }

    public function updateOrderMerchantStock(array $pl, OrderLineItemEntity $lineItem): void
    {
        $merchantStock = $this->getMerchantStock($pl['merchantStockId']);

        if (!$merchantStock || !$merchantStock->getIsStock()) {
            return;
        }

        $stock = $merchantStock->getStock() - $lineItem->getQuantity();

        $this->definitionInstanceRegistry->getRepository('moorl_merchant_stock')->update([
            [
                'id' => $merchantStock->getId(),
                'stock' => $stock
            ]
        ], $this->getContext());
    }

    public function getLineItemMerchantStockId($lineItem): ?string
    {
        $pl = $lineItem->getPayload();
        if (!$pl || !isset($pl['MoorlMerchantStock'])) {
            return null;
        }
        return $pl['MoorlMerchantStock']['merchantStockId'];
    }

    public function getLineItemMerchantStockIds($lineItems): array
    {
        $ids = [];
        foreach ($lineItems as $lineItem) {
            $merchantStockId = $this->getLineItemMerchantStockId($lineItem);
            if ($merchantStockId) {
                $ids[] = $merchantStockId;
            }
        }
        return $ids;
    }

    public function patchLineItems($lineItems): void
    {
        if (!$lineItems || $lineItems->count() == 0) {
            return;
        }

        $ids = $this->getLineItemMerchantStockIds($lineItems);

        if (count($ids) == 0) {
            return;
        }

        $merchantStocks = $this->definitionInstanceRegistry
            ->getRepository('moorl_merchant_stock')
            ->search((new Criteria($ids))->addAssociation('merchant')->addAssociation('deliveryTime'), $this->getContext())
            ->getEntities();

        foreach ($lineItems as $lineItem) {
            $merchantStockId = $this->getLineItemMerchantStockId($lineItem);
            if (!$merchantStockId) {
                continue;
            }
            $merchantStock = $merchantStocks->get($merchantStockId);
            if ($merchantStock) {
                $lineItem->addExtension('MoorlMerchantStock', $merchantStock);
            }
        }
    }

    public function patchLineItem(LineItem $lineItem, Cart $cart): void
    {
        if ($lineItem->getType() != 'product') {
            return;
        }

        // line item has stock information?
        $productId = $lineItem->getReferencedId();
        $salesChannelContext = $this->getSalesChannelContext();

        $criteria = (new Criteria())
            ->addAssociation('merchant')
            ->addFilter(new EqualsFilter('productId', $productId))
            ->addFilter(
                new MultiFilter(
                    MultiFilter::CONNECTION_OR, [
                        new EqualsFilter('merchant.salesChannelId', null),
                        new EqualsFilter('merchant.salesChannelId', $salesChannelContext->getSalesChannel()->getId())
                    ]
                )
            );

        $merchantStocks = $this->definitionInstanceRegistry
            ->getRepository('moorl_merchant_stock')
            ->search($criteria, $this->getContext())
            ->getEntities();

        // yes
        if ($merchantStocks->count() > 0) {
            $merchantStockId = $this->requestStack->getCurrentRequest()->get('merchantStockId');

            $merchantStock = $merchantStocks->get($merchantStockId);

            // stock infos are valid?
            if (!$merchantStock) {
                // no
                $cart->remove($lineItem->getId());
                $this->session->getFlashBag()->add('danger', 'Please select a merchant');
                return;
            }

            // yes
            $lineItem->setId($merchantStock->getId()); // stack similar line items by stock id

            $lineItem->setPayloadValue('MoorlMerchantStock', [
                'merchantStockId' => $merchantStock->getId(),
                'merchantId' => $merchantStock->getMerchant()->getId(),
                'merchantOriginId' => $merchantStock->getMerchant()->getOriginId(),
                'merchantName' => $merchantStock->getMerchant()->getTranslated()['name'],
                'merchantCompany' => $merchantStock->getMerchant()->getCompany()
            ]);
        }

        return;
    }

    public function getMerchants(?ArrayStruct $data = null): EntityCollection
    {
        // TODO: Remove ParameterBag
        if (!$data) {
            $data = $this->requestStack->getCurrentRequest();
        }

        $distance = (int)$data->get('distance') ?: 30;
        $limit = (int)$data->get('items') ?: 500;
        $offset = (int)$data->get('offset') ?: 0;

        $context = $this->getContext();
        $options = new ArrayStruct(json_decode($data->get('options'), true) ?: []);

        $this->initGlobalOpeningHours();

        if ($data->get('id')) {
            $criteria = new Criteria([$data->get('id')]);
        } else {
            if ($options->get('myLocation')) {
                $this->myLocation = $options->get('myLocation');
            } else {
                $this->getLocationByTerm($data->get('zipcode'));
            }

            if ($this->myLocation && count($this->myLocation) > 0) {
                $context->addExtension('DistanceField', new ArrayStruct($this->myLocation[0]));

                $criteria = new Criteria();
                $criteria->addSorting(new FieldSorting('distance'));
                $criteria->addFilter(new RangeFilter('distance', ['lte' => $distance]));
            } else {
                $criteria = new Criteria();
                $criteria->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));
                $criteria->addSorting(new FieldSorting('highlight', FieldSorting::DESCENDING));
                $criteria->addSorting(new FieldSorting('company', FieldSorting::ASCENDING));
            }

            $criteria->setLimit($limit);
            $criteria->setOffset($offset);
            $criteria->setTotalCountMode(1);
        }

        $criteria->addAssociation('tags');
        $criteria->addAssociation('merchantOpeningHours');
        $criteria->addAssociation('productManufacturers.media');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('media');
        $criteria->addAssociation('marker');
        $criteria->addAssociation('markerShadow');
        $criteria->addFilter(new EqualsFilter('active', true));

        if ($data->get('countryCode')) {
            $criteria->addFilter(new EqualsFilter('countryCode', $data->get('countryCode')));
        }

        if ($data->get('categoryId')) {
            $criteria->addFilter(new EqualsFilter('categories.id', $data->get('categoryId')));
        }

        if ($data->get('productManufacturerId')) {
            $criteria->addFilter(new EqualsFilter('productManufacturers.id', $data->get('productManufacturerId')));
        }

        if ($data->get('productId')) {
            $criteria->addAssociation('products');
            $criteria->addFilter(new EqualsFilter('products.id', $data->get('productId')));
        }

        if ($data->get('tags')) {
            $criteria->addFilter(new EqualsFilter('tags.id', $data->get('tags')));
        }

        if ($data->get('search')) {
            $criteria->setTerm($data->get('search'));
        }

        if ($data->get('term')) {
            $criteria->setTerm($data->get('term'));
        }

        if ($data->get('rules')) {
            $rules = is_array($data->get('rules')) ? $data->get('rules') : $data->get('rules')->all();

            if (is_array($rules)) {
                if (in_array('isHighlighted', $rules)) {
                    $criteria->addFilter(new EqualsFilter('highlight', 1));
                }
                if (in_array('hasPriority', $rules)) {
                    $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                        new EqualsFilter('priority', 0)
                    ]));
                }
                if (in_array('hasLogo', $rules)) {
                    $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                        new EqualsFilter('media.id', null)
                    ]));
                }
            }
        }

        $salesChannelContext = $this->getSalesChannelContext();

        if ($salesChannelContext) {
            $criteria->addFilter(
                new MultiFilter(
                    MultiFilter::CONNECTION_OR, [
                        new EqualsFilter('salesChannelId', null),
                        new EqualsFilter('salesChannelId', $salesChannelContext->getSalesChannel()->getId())
                    ]
                )
            );

            $customer = $salesChannelContext->getCustomer();

            if ($customer) {
                $criteria->addFilter(
                    new MultiFilter(
                        MultiFilter::CONNECTION_OR, [
                            new EqualsFilter('customerGroupId', null),
                            new EqualsFilter('customerGroupId', $customer->getGroupId())
                        ]
                    )
                );
            } else {
                $criteria->addFilter(new EqualsFilter('customerGroupId', null));
            }
        }

        $resultData = $this->repository->search($criteria, $context);

        /* @var $entity MerchantEntity */
        foreach ($resultData->getEntities() as $entity) {
            if ($context->hasExtension('DistanceField')) {
                $entity->setDistance($this->distance(
                    (float) $context->getExtension('DistanceField')['lat'],
                    (float) $context->getExtension('DistanceField')['lon'],
                    $entity->getLocationLat(),
                    $entity->getLocationLon()
                ));
            }

            $entity->setSeoUrl(
                $this->seoUrlReplacer->generate('moorl.merchant-finder.merchant.page', ['merchantId' => $entity->getId()])
            );

            $entity->getMerchantOpeningHours()->merge($this->openingHours);
        }

        $this->setMerchantsCount($resultData->getTotal());

        $merchants = $resultData->getEntities();

        $event = new MerchantsLoadedEvent($context, $merchants);
        $this->eventDispatcher->dispatch($event);

        return $merchants;
    }

    public function initGlobalOpeningHours(): void
    {
        $criteria = new Criteria();

        $time = new \DateTimeImmutable();

        $criteria->addFilter(new EqualsFilter('merchantId', null));
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('showFrom', null),
                    new RangeFilter('showFrom', ['lte' => $time->format(DATE_ATOM)])
                ]
            )
        );
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('showUntil', null),
                    new RangeFilter('showUntil', ['gte' => $time->format(DATE_ATOM)])
                ]
            )
        );
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('date', null),
                    new EqualsFilter('date', $time->format("Y-m-d"))
                ]
            )
        );

        $this->openingHours = $this->openingHourRepo->search($criteria, $this->getContext())->getEntities();
    }

    public function getLocationByTerm($term): array
    {
        if (!$term || empty($term)) {
            return [];
        }

        $pluginConfig = $this->systemConfigService->getDomain('MoorlMerchantFinder.config');
        $filterCountries = !empty($pluginConfig['MoorlMerchantFinder.config.allowedSearchCountryCodes']) ? explode(',', $pluginConfig['MoorlMerchantFinder.config.allowedSearchCountryCodes']) : MoorlMerchantFinder::getDefault('allowedSearchCountryCodes');
        $searchEngine = !empty($pluginConfig['MoorlMerchantFinder.config.nominatim']) ? $pluginConfig['MoorlMerchantFinder.config.nominatim'] : MoorlMerchantFinder::getDefault('nominatim');

        $sql = <<<SQL
SELECT * FROM `moorl_zipcode`
WHERE `city` LIKE :city OR `zipcode` LIKE :zipcode AND country_code IN (:countries)
LIMIT 10; 
SQL;

        $myLocation = $this->connection->executeQuery($sql, [
                'city' => '%' . $term . '%',
                'zipcode' => $term . '%',
                'countries' => implode(',', $filterCountries),
            ]
        )->fetchAll(FetchMode::ASSOCIATIVE);

        // No location found - Get them from OSM
        if (count($myLocation) == 0) {
            $queryString = implode(' ', [
                $term,
                count($filterCountries) == 1 ? current($filterCountries) : "",
            ]);

            $query = http_build_query([
                'q' => $queryString,
                'format' => 'json',
                'addressdetails' => 1,
            ]);

            $client = new Client();
            $res = $client->request('GET', $searchEngine . '?' . $query, ['headers' => ['Accept' => 'application/json', 'Content-type' => 'application/json']]);
            $resultData = json_decode($res->getBody()->getContents(), true);

            foreach ($resultData as $item) {
                if (in_array($item['address']['country_code'], $filterCountries)) {
                    // Fill local database with locations
                    $sql = <<<SQL
INSERT IGNORE INTO `moorl_zipcode` (
    `id`,
    `zipcode`,
    `city`,
    `state`,
    `country`,
    `country_code`,
    `suburb`,
    `lon`,
    `lat`,
    `licence`
) VALUES (
    :id,
    :zipcode,
    :city,
    :state,
    :country,
    :country_code,
    :suburb,
    :lon,
    :lat,
    :licence
);
SQL;

                    $placeholder = [
                        'id' => $item['place_id'],
                        'zipcode' => isset($item['address']['postcode']) ? $item['address']['postcode'] : null,
                        'city' => isset($item['address']['city']) ? $item['address']['city'] : null,
                        'state' => isset($item['address']['state']) ? $item['address']['state'] : null,
                        'country' => $item['address']['country'],
                        'country_code' => $item['address']['country_code'],
                        'suburb' => isset($item['address']['suburb']) ? $item['address']['suburb'] : null,
                        'lon' => $item['lon'],
                        'lat' => $item['lat'],
                        'licence' => $item['licence']
                    ];

                    $this->connection->executeQuery($sql, $placeholder);

                    $myLocation[] = $placeholder;
                }
            }
        }

        $this->setMyLocation($myLocation);

        return $myLocation;
    }

    /**
     * @return SalesChannelContext
     */
    public function getSalesChannelContext(): ?SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     */
    public function setSalesChannelContext(SalesChannelContext $salesChannelContext): void
    {
        $this->salesChannelContext = $salesChannelContext;
        $this->context = $salesChannelContext->getContext();
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2, string $unit = "K") {
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        }
        else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);

            if ($unit == "K") {
                return ($miles * 1.609344);
            } else if ($unit == "N") {
                return ($miles * 0.8684);
            } else {
                return $miles;
            }
        }
    }
}