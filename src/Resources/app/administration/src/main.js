import './module/moorl-merchant-finder';
import './module/sw-cms';
import './extension';

const CustomFieldService = Shopware.Service('customFieldDataProviderService');

CustomFieldService.addEntityName('moorl_merchant');
