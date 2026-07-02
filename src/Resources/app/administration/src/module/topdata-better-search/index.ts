import './page/better-search-list';

Shopware.Module.register('topdata-better-search', {
    type: 'plugin',
    name: 'BetterSearch',
    title: 'TopdataBetterSearchSW6.title',
    description: 'TopdataBetterSearchSW6.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-better-search-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-better-search',
        label: 'TopdataBetterSearchSW6.title',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-better-search-list',
        label: 'TopdataBetterSearchSW6.listTitle',
        color: '#189eff',
        path: 'topdata.better.search.list',
        parent: 'topdata-better-search',
    }],
});
