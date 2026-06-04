import './page/zero-search-list';

Shopware.Module.register('topdata-es-zero-search', {
    type: 'plugin',
    name: 'ZeroSearch',
    title: 'topdata-es-zero-search.title',
    description: 'topdata-es-zero-search.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-zero-search-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-es-zero-search',
        label: 'topdata-es-zero-search.title',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-es-zero-search-list',
        label: 'topdata-es-zero-search.listTitle',
        color: '#189eff',
        path: 'topdata.es.zero.search.list',
        parent: 'topdata-es-zero-search',
    }],
});
