<?php

namespace Moorl\MerchantFinder\Core\Service;

use Moorl\MerchantFinder\GeoLocation\GeoPoint;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

class LocationService
{
    public const SEARCH_ENGINE = 'https://nominatim.openstreetmap.org/search';

    /**
     * @var Context|null
     */
    private $context;
    /**
     * @var DefinitionInstanceRegistry
     */
    private $definitionInstanceRegistry;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var ClientInterface
     */
    protected $client;

    public function __construct(
        DefinitionInstanceRegistry $definitionInstanceRegistry,
        SystemConfigService $systemConfigService
    )
    {
        $this->definitionInstanceRegistry = $definitionInstanceRegistry;
        $this->systemConfigService = $systemConfigService;

        $this->client = new Client([
            'timeout' => 200,
            'allow_redirects' => false,
        ]);

        $this->context = Context::createDefaultContext();
    }

    public function getCustomerLocation(CustomerEntity $customer): ?GeoPoint
    {
        $address = $customer->getActiveShippingAddress();

        return $this->getLocationByAddress([
            'street' => $address->getStreet(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'iso' => $address->getCountry()->getIso()
        ]);
    }

    public function getLocationByAddress(array $payload, $tries = 0): ?GeoPoint
    {
        $payload = array_merge([
            'street' => null,
            'streetNumber' => null,
            'zipcode' => null,
            'city' => null,
            'iso' => null
        ], $payload);

        try {
            $apiKey = $this->systemConfigService->get('MoorlMerchantFinder.config.googleMapsApiKey');

            if ($apiKey) {
                $address = sprintf('%s %s, %s %s, %s',
                    $payload['street'],
                    $payload['streetNumber'],
                    $payload['zipcode'],
                    $payload['city'],
                    $payload['iso']
                );

                return GeoPoint::fromAddress($address, $apiKey);
            }

            $params = [
                "format" => "json",
                "zipcode" => $payload['zipcode'],
                "city" => $payload['city'],
                "street" => trim(sprintf(
                    '%s %s',
                    $payload['street'],
                    $payload['streetNumber']
                )),
                "country" => $payload['iso']
            ];

            $response = $this->apiRequest('GET', self::SEARCH_ENGINE, null, $params);

            if ($response && isset($response[0])) {
                return new GeoPoint($response[0]['lat'], $response[0]['lon']);
            } else {
                sleep(1);

                $tries++;

                switch ($tries) {
                    case 1:
                        $payload['country'] = 'DE';
                        return $this->getLocationByAddress($payload, $tries);
                    case 2:
                        $payload['country'] = null;
                        return $this->getLocationByAddress($payload, $tries);
                    case 3:
                        $payload['street'] = null;
                        $payload['streetNumber'] = null;
                        return $this->getLocationByAddress($payload, $tries);
                    case 4:
                        $payload['zipcode'] = null;
                        return $this->getLocationByAddress($payload, $tries);
                }

                return null;
            }
        } catch (\Exception $exception) {}

        return null;
    }

    /**
     * @return Context|null
     */
    public function getContext(): ?Context
    {
        return $this->context;
    }

    /**
     * @param Context|null $context
     */
    public function setContext(?Context $context): void
    {
        $this->context = $context;
    }

    protected function apiRequest(string $method, ?string $endpoint = null, ?array $data = null, array $query = [])
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];

        $httpBody = json_encode($data);

        $query = \guzzlehttp\psr7\build_query($query);

        $request = new Request(
            $method,
            $endpoint . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );

        $response = $this->client->send($request);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode > 299) {
            throw new \Exception(
                sprintf('[%d] Error connecting to the API (%s)', $statusCode, $request->getUri()),
                $statusCode
            );
        }

        $contents = $response->getBody()->getContents();

        try {
            return json_decode($contents, true);
        } catch (\Exception $exception) {
            throw new \Exception(
                sprintf('[%d] Error decoding JSON: %s', $statusCode, $contents),
                $statusCode
            );
        }
    }
}