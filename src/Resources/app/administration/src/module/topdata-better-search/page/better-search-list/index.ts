const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-better-search-list', {
    template: `
<div class="topdata-better-search-list">
    <sw-page class="topdata-better-search-list-page">
        <template #smart-bar-header>
            <h2>{{ $tc('TopdataBetterSearchSW6.title') }}</h2>
        </template>

        <template #content>
            <sw-entity-listing
                v-if="items"
                :items="items"
                :columns="columns"
                :repository="repository"
                :criteria-limit="limit"
                :show-settings="true"
                :show-selection="false"
                :allow-view="false"
                :allow-edit="false"
                :allow-delete="true"
                :allow-inline-edit="false"
                :full-page="true"
                :sort-by="sortBy"
                :sort-direction="sortDirection"
                :is-loading="isLoading"
                @page-change="onPageChange"
                @column-sort="onSortColumn"
            >
                <template #column-lastSearchedAt="{ item }">
                    {{ item.lastSearchedAt | date(true) }}
                </template>

                <template #column-createdAt="{ item }">
                    {{ item.createdAt | date(true) }}
                </template>
            </sw-entity-listing>
        </template>
    </sw-page>
</div>
    `,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'count',
            sortDirection: 'DESC',
            limit: 25,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdbs_zero_search');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataBetterSearchSW6.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('TopdataBetterSearchSW6.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('TopdataBetterSearchSW6.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataBetterSearchSW6.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            this.repository.search(criteria).then((result) => {
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onPageChange(params) {
            this.page = params.page;
            this.limit = params.limit;
            this.getList();
        },

        onSortColumn(column) {
            this.sortBy = column.dataIndex ?? column.property;
            this.sortDirection = column.sortDirection ?? 'ASC';
            this.getList();
        },
    },
});
