import template from './sw-order-detail-customfields.html.twig';
import './sw-order-detail-customfields.css';
const { Criteria } = Shopware.Data;

Shopware.Component.register('sw-order-detail-customfields', {
    template,
    inject: ['repositoryFactory'],
    data() {
        return {
            order: null,
            lineItems: [],
            infoplusCustomFields: []
        };
    },
    metaInfo() {
        return {
            title: 'Custom Fields',
            meta: [{
                name: 'description',
                content: 'Custom Fields'
            }]
        };
    },
    created() {
        this.loadOrder();
        this.loadInfoplusCustomFields();
    },
    computed: {
        mappedLineItems() {
            return this.lineItems.map(item => {
                const customFields = item.payload && item.payload.infoplus_customfields ? item.payload.infoplus_customfields : {};
                const displayCustomFields = Object.entries(customFields).map(([key, value]) => {
                    const technicalName = key.startsWith('infoplus_') ? key.replace('infoplus_', '') : key;
                    const fieldDef = this.infoplusCustomFields.find(f => f.technicalName === technicalName);
                    const label = fieldDef ? fieldDef.label : technicalName;
                    const formatted = this.formatValueWithPrice(value, fieldDef);
                    return { label, value: formatted };
                });
                return {
                    ...item,
                    displayCustomFields
                };
            });
        }
    },
    methods: {
        loadOrder() {
            const orderId = this.$route.params.id;
            const orderRepository = this.repositoryFactory.create('order');
            const criteria = new Criteria();
            criteria.addAssociation('lineItems');

            criteria.addFilter(Criteria.equals('id', orderId));

            orderRepository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    if (result && result.length > 0) {
                        this.order = result[0];
                        this.lineItems = this.order.lineItems || [];
                    } else {
                        this.order = null;
                        this.lineItems = [];
                    }
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'Order fetch error',
                        message: error.message,
                    });
                });
        },
        loadInfoplusCustomFields() {
            const fieldRepository = this.repositoryFactory.create('infoplus_field_definition');
            const criteria = new Criteria();
            fieldRepository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.infoplusCustomFields = result || [];
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'Custom field fetch error',
                        message: error.message,
                    });
                });
        },
        formatValueWithPrice(value, def) {
            if (!def) return String(value ?? '');
            const type = def.type;
            const base = this.formatScalar(value, type);
            let suffix = '';
            if (type === 'select') {
                const opts = Array.isArray(def.options) ? def.options : [];
                const matched = opts.find(o => {
                    const label = (o && (o.label || o.name)) ? (o.label || o.name) : null;
                    const val = (o && (o.value || label)) ? (o.value || label) : null;
                    return val !== null && (String(val) === String(value) || String(label) === String(value));
                });
                if (matched) {
                    if (matched.price !== undefined && matched.price !== null && matched.price !== '') {
                        suffix = ` (+$${matched.price})`;
                    } else if (def.staticPrice !== undefined && def.staticPrice !== null) {
                        suffix = ` (+$${def.staticPrice})`;
                    }
                }
            } else {
                const staticPrice = def.staticPrice;
                if (staticPrice !== undefined && staticPrice !== null) {
                    let apply;
                    if (type === 'boolean') {
                        apply = !!value && String(value) !== '0';
                    } else {
                        apply = value !== null && value !== '' && String(value) !== '0';
                    }
                    if (apply) suffix = ` (+$${staticPrice})`;
                }
            }
            return `${base}${suffix}`.trim();
        },
        formatScalar(value, type) {
            if (type === 'boolean') {
                const truthy = (value === true) || String(value) === '1' || value === 1;
                return truthy ? 'Yes' : 'No';
            }
            return String(value ?? '');
        }
    }
});