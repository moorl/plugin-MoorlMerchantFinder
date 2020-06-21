<?php

namespace Moorl\MerchantFinder\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Client;
use Moorl\MerchantFinder\MoorlMerchantFinder;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController as OriginController;

/**
 * @RouteScope(scopes={"storefront"})
 */
class StorefrontController extends OriginController
{

    /**
     * @var EntityRepositoryInterface
     */
    private $repository;
    private $systemConfigService;

    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $repository
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->repository = $repository;
    }

    /**
     * @Route("/moorl/merchant-finder/suggest", name="moorl.merchant-finder.search", methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function suggest(Request $request, RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        // TODO: Suggest Search, OSM doesn't allow service here
        return new JsonResponse();
    }

    /**
     * @Route("/moorl/merchant-finder/search", name="moorl.merchant-finder.search", methods={"POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function search(RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        $response = new JsonResponse();

        if ($data->get('action') == 'pick' && $data->get('merchant')) {
            $response->setData($this->setCustomerSession($data, $context));
        } else {
            $response->setData($this->searchProcess($data, $context->getContext()));
        }

        return $response;
    }

    protected function setCustomerSession($data, $context): array
    {
        if ($customer = $context->getCustomer()) {
            $customerRepo = $this->container->get('customer.repository');

            $customFields = $customer->getCustomFields() ?: [];
            $customFields['moorl_mf_merchant_id'] = $data->get('merchant');

            $customerRepo->update([[
                'id' => $customer->getId(),
                'customFields' => $customFields
            ]], $context->getContext());
        }

        $session = new Session();
        $session->set('moorl-merchant-finder_selected_merchant', $data->get('merchant'));

        /* unset to debug */
        if ($data->get('merchant') == 'b3bd48adbcb748e8a910f9155e0a8d05') {
            $session->remove('moorl-merchant-finder_selected_merchant');
        }

        return [
            'reload' => true,
        ];
    }

    protected function searchProcess($data, $context): array
    {
        $connection = $this->container->get(Connection::class);

        $data->set('distance', $data->get('distance') ?: '30');
        $data->set('items', (int)$data->get('items') ?: 500);

        $pluginConfig = $this->systemConfigService->getDomain('MoorlMerchantFinder.config');
        $filterCountries = !empty($pluginConfig['MoorlMerchantFinder.config.allowedSearchCountryCodes']) ? explode(',', $pluginConfig['MoorlMerchantFinder.config.allowedSearchCountryCodes']) : MoorlMerchantFinder::getDefault('allowedSearchCountryCodes');
        $searchEngine = !empty($pluginConfig['MoorlMerchantFinder.config.nominatim']) ? $pluginConfig['MoorlMerchantFinder.config.nominatim'] : MoorlMerchantFinder::getDefault('nominatim');

        $myLocation = [];

        if (!empty($data->get('zipcode'))) {
            $sql = <<<SQL
SELECT * FROM `moorl_zipcode`
WHERE `city` LIKE :city OR `zipcode` LIKE :zipcode AND country_code IN (:countries)
LIMIT 10; 
SQL;

            $myLocation = $connection->executeQuery($sql, [
                    'city' => '%' . $data->get('zipcode') . '%',
                    'zipcode' => $data->get('zipcode') . '%',
                    'countries' => implode(',', $filterCountries),
                ]
            )->fetchAll(FetchMode::ASSOCIATIVE);

            // No location found - Get them from OSM
            if (count($myLocation) == 0) {
                $queryString = implode(' ', [
                    $data->get('zipcode'),
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

                        $connection->executeQuery($sql, $placeholder);

                        $myLocation[] = $placeholder;
                    }
                }
            }
        }

        if (count($myLocation) > 0) {
            $sql = <<<SQL
SELECT 
    LOWER(HEX(`id`)) AS `id`,
    ACOS(
         SIN(RADIANS(:lat)) * SIN(RADIANS(`location_lat`)) 
         + COS(RADIANS(:lat)) * COS(RADIANS(`location_lat`))
         * COS(RADIANS(:lon) - RADIANS(`location_lon`))
    ) * 6380 AS distance
FROM `moorl_merchant`
WHERE `active` IS TRUE
HAVING `distance` < :distance
ORDER BY `distance`
LIMIT 500;
SQL;

            $resultData = $connection->executeQuery($sql, [
                    'lat' => $myLocation[0]['lat'],
                    'lon' => $myLocation[0]['lon'],
                    'distance' => $data->get('distance'),
                ]
            )->fetchAll(FetchMode::ASSOCIATIVE);

            $merchantIds = [];
            $distance = [];

            foreach ($resultData as $item) {
                $merchantIds[] = $item['id'];
                $distance[$item['id']] = $item['distance'];
            }

            $criteria = new Criteria($merchantIds);
        } else {
            $criteria = new Criteria();

            $criteria->addSorting(new FieldSorting('priority', FieldSorting::DESCENDING));
            $criteria->addSorting(new FieldSorting('highlight', FieldSorting::DESCENDING));
            $criteria->addSorting(new FieldSorting('company', FieldSorting::ASCENDING));
        }

        $criteria->setLimit($data->get('items'));
        $criteria->addAssociation('tags');
        $criteria->addAssociation('productManufacturers');
        $criteria->addAssociation('productManufacturers.media');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('media');
        $criteria->addAssociation('marker');
        $criteria->addAssociation('markerShadow');
        $criteria->addFilter(new EqualsFilter('active', true));

        if ($data->get('tags')) {
            $criteria->addFilter(new EqualsFilter('tags.id', $data->get('tags')));
        }
        if ($data->get('productManufacturers')) {
            $criteria->addFilter(new EqualsFilter('productManufacturers.id', $data->get('productManufacturers')));
        }
        if ($data->get('categories')) {
            $criteria->addFilter(new EqualsFilter('categories.id', $data->get('categories')));
        }
        
        if ($data->get('rules')) {
            $rules = $data->get('rules')->all();

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

        $criteria->setTerm($data->get('term'));

        $resultData = $this->repository->search($criteria, $context);

        foreach ($resultData->getEntities() as $entity) {
            if (isset($distance) && count($distance) > 0) {
                $entity->setDistance($distance[$entity->getId()]);
            }
            // TODO: Add SEO Url
        }

        return [
            'data' => $resultData->getEntities(),
            'loc' => $myLocation,
        ];
    }
}
