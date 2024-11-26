<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Event;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantDefinition;
use Moorl\MerchantFinder\Core\Content\Merchant\MerchantEntity;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Content\Flow\Events\BeforeLoadStorableFlowDataEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('business-ops')]
class MerchantStorer extends FlowStorer
{
    /**
     * @internal
     */
    public function __construct(
        private readonly EntityRepository $merchantRepository,
        private readonly EventDispatcherInterface $dispatcher
    ) {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function store(FlowEventAware $event, array $stored): array
    {
        if (!$event instanceof MerchantAware || isset($stored[MerchantAware::MERCHANT_ID])) {
            return $stored;
        }

        $stored[MerchantAware::MERCHANT_ID] = $event->getMerchantId();

        return $stored;
    }

    public function restore(StorableFlow $storable): void
    {
        if (!$storable->hasStore(MerchantAware::MERCHANT_ID)) {
            return;
        }

        $storable->setData(MerchantAware::MERCHANT_ID, $storable->getStore(MerchantAware::MERCHANT_ID));

        $storable->lazy(
            MerchantAware::MERCHANT,
            $this->lazyLoad(...)
        );
    }

    /**
     * @param array<int, mixed> $args
     *
     * @deprecated tag:v6.6.0 - Will be removed in v6.6.0.0
     */
    public function load(array $args): ?MerchantEntity
    {
        Feature::triggerDeprecationOrThrow(
            'v6_6_0_0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, '6.6.0.0')
        );

        [$id, $context] = $args;
        $criteria = new Criteria([$id]);

        return $this->loadMerchant($criteria, $context, $id);
    }

    private function lazyLoad(StorableFlow $storableFlow): ?MerchantEntity
    {
        $id = $storableFlow->getStore(MerchantAware::MERCHANT_ID);
        if ($id === null) {
            return null;
        }

        $criteria = new Criteria([$id]);

        return $this->loadMerchant($criteria, $storableFlow->getContext(), $id);
    }

    private function loadMerchant(Criteria $criteria, Context $context, string $id): ?MerchantEntity
    {
        $event = new BeforeLoadStorableFlowDataEvent(
            MerchantDefinition::ENTITY_NAME,
            $criteria,
            $context,
        );

        $this->dispatcher->dispatch($event, $event->getName());

        $merchant = $this->merchantRepository->search($criteria, $context)->get($id);
        if ($merchant) {
            /** @var MerchantEntity $merchant */
            return $merchant;
        }

        return null;
    }
}
