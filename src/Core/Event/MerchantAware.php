<?php declare(strict_types=1);

namespace Moorl\MerchantFinder\Core\Event;

use Moorl\MerchantFinder\Core\Content\Merchant\MerchantEntity;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Log\Package;

#[Package('business-ops')]
interface MerchantAware extends FlowEventAware
{
    public const MERCHANT = 'merchant';
    public const MERCHANT_ID = 'merchantId';
    public function getMerchant(): MerchantEntity;
    public function getMerchantId(): string;
}
