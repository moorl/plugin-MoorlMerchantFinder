const { Module } = Shopware;
import './page/moorl-merchant-finder-list';
import './page/moorl-merchant-finder-detail';
import './page/moorl-merchant-finder-create';
import './style/main.scss';

Module.register('moorl-merchant-finder', {
    type: 'plugin',
    name: 'MerchantFinder',
    title: 'moorl-merchant-finder.general.mainMenuItemGeneral',
    color: '#ff3d58',
    icon: 'default-object-globe',
    routes: {
        list: {
            component: 'moorl-merchant-finder-list',
            path: 'list'
        },
        detail: {
            component: 'moorl-merchant-finder-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'moorl.merchant.finder.list'
            }
        },
        create: {
            component: 'moorl-merchant-finder-create',
            path: 'create',
            meta: {
                parentPath: 'moorl.merchant.finder.list'
            }
        }
    },
    navigation: [{
        label: 'moorl-merchant-finder.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'moorl.merchant.finder.list',
        icon: 'default-object-globe',
        position: 40
    }]
});