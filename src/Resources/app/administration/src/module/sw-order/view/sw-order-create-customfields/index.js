import template from './sw-order-create-customfields.html.twig';
import './sw-order-create-customfields.css';
const { Criteria } = Shopware.Data;
const { Store, Mixin } = Shopware;

Shopware.Component.register('sw-order-create-customfields', {
    template,
    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],
    data() {
        return {
            infoplusCustomFields: [],
            lineItems: []
        };
    },
    computed: {
        assignableFieldsByLineItem() {
            const result = {};
            this.lineItems.forEach(item => {
                const product = item.payload && item.payload.product;
                const productCustomFields = product && product.customFields ? product.customFields : {};
                result[item.id] = this.infoplusCustomFields.filter(field => {
                    const val = productCustomFields['infoplus_' + field.technicalName];
                    return val === true || val === 1 || String(val) === '1' || Boolean(val) === true;
                });
            });
            return result;
        },
        cart() {
            return Store.get('swOrder').cart;
        },
        customer(){
            return Store.get('swOrder').customer;
        },
        salesChannelId() {
            return this.customer?.salesChannelId || (this.salesChannelContext && this.salesChannelContext.salesChannelId) || '';
        },
        contextToken() {
            return this.cart?.token || (this.salesChannelContext && this.salesChannelContext.token) || '';
        }
    },
    watch: {
        contextToken(newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.cleanupInfoplusLocalStorage();
                this.restoreLineItemCustomFieldsFromLocalStorage();
            }
        }
    },
    async created() {
        this.loadLineItems();
        this.loadInfoplusCustomFields();
        this.loadProductsForLineItems();
        this.cleanupInfoplusLocalStorage();
        this.restoreLineItemCustomFieldsFromLocalStorage();
    },
    methods: {
        loadLineItems() {
            const cart = Store.get('swOrder').cart;
            this.lineItems = cart && cart.lineItems ? cart.lineItems : [];
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
        loadProductsForLineItems() {
            const productRepository = this.repositoryFactory.create('product');
            const productIds = this.lineItems
                .map(item => item.referencedId || (item.payload && item.payload.product && item.payload.product.id))
                .filter(id => !!id);
            if (!productIds.length) return;
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equalsAny('id', productIds));
            try {
                productRepository.search(criteria, Shopware.Context.api)
                    .then(products => {
                        this.lineItems.forEach(item => {
                            const productId = item.referencedId || (item.payload && item.payload.product && item.payload.product.id);
                            if (productId && products.get(productId)) {
                                if (!item.payload) this.$set(item, 'payload', {});
                                this.$set(item.payload, 'product', products.get(productId));
                            }
                        });
                    });
            } catch (error) {
                this.createNotificationError({
                    title: 'Product fetch error',
                    message: error.message,
                });
            }
        },
        getLocalStorageKey() {
            return this.contextToken ? `infoplus_lineitems_${this.contextToken}` : null;
        },
        cleanupInfoplusLocalStorage() {
            try {
                const currentKey = this.getLocalStorageKey();
                const prefix = 'infoplus_lineitems_';
                const TTL = 60 * 60 * 1000; // 1 hour
                const now = Date.now();
                for (let i = localStorage.length - 1; i >= 0; i--) {
                    const k = localStorage.key(i);
                    if (!k || !k.startsWith(prefix)) continue;
                    if (k === currentKey) {
                        const raw = localStorage.getItem(k);
                        if (raw) {
                            try {
                                const parsed = JSON.parse(raw);
                                if (parsed.savedAt && (now - parsed.savedAt) > TTL) {
                                    localStorage.removeItem(k);
                                }
                            } catch {
                                localStorage.removeItem(k);
                            }
                        }
                        continue;
                    }
                    localStorage.removeItem(k);
                }
            } catch (e) {
                console.warn('Infoplus localStorage cleanup failed', e);
            }
        },
        persistLineItemsToLocalStorage() {
            this.cleanupInfoplusLocalStorage();
            const key = this.getLocalStorageKey();
            if (!key) return;
            try {
                const itemsForStorage = this.lineItems.map(item => {
                    const infoplus = {};
                    const all = (item.customFields || {});
                    Object.keys(all).forEach(k => {
                        if (k.startsWith('infoplus_')) {
                            infoplus[k] = all[k];
                        }
                    });
                    return { id: item.id, referencedId: item.referencedId, customFields: infoplus };
                });
                const payload = {
                    token: this.contextToken,
                    savedAt: Date.now(),
                    items: itemsForStorage
                };
                window.localStorage.setItem(key, JSON.stringify(payload));
            } catch (e) {
                console.warn('Infoplus localStorage persist failed', e);
            }
        },
        restoreLineItemCustomFieldsFromLocalStorage() {
            const key = this.getLocalStorageKey();
            if (!key) return;
            try {
                const raw = window.localStorage.getItem(key);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (!parsed || parsed.token !== this.contextToken) return;
                const ONE_HOUR = 60 * 60 * 1000;
                if ((Date.now() - parsed.savedAt) > ONE_HOUR) {
                    window.localStorage.removeItem(key);
                    return;
                }
                const byId = {};
                const byRef = {};
                (parsed.items || []).forEach(entry => {
                    if (entry.id) byId[entry.id] = entry.customFields || {};
                    if (entry.referencedId) byRef[entry.referencedId] = entry.customFields || {};
                });
                this.lineItems.forEach(item => {
                    const saved = byId[item.id] || (item.referencedId ? byRef[item.referencedId] : null);
                    if (!saved) return;
                    if (!item.customFields) {
                        this.$set(item, 'customFields', {});
                    }
                    if (!item.payload) {
                        this.$set(item, 'payload', {});
                    }
                    if (!item.payload.customFields) {
                        this.$set(item.payload, 'customFields', {});
                    }
                    if (!item.payload.infoplus_customfields) {
                        this.$set(item.payload, 'infoplus_customfields', {});
                    }
                    Object.keys(saved).forEach(k => {
                        this.$set(item.customFields, k, saved[k]);
                        this.$set(item.payload.customFields, k, saved[k]);
                        this.$set(item.payload.infoplus_customfields, k, saved[k]);
                    });
                });
            } catch (e) {
                console.warn('Infoplus localStorage restore failed', e);
            }
        },
        async updateCustomField(item, key, value) {
            const fieldName = key.replace('infoplus_', '');
            const fieldDef = this.infoplusCustomFields.find(f => f.technicalName === fieldName);
            let finalValue = value;
            if (fieldDef && fieldDef.type === 'boolean') {
                finalValue = value ? 1 : 0;
            }
            if (!item.customFields) {
                this.$set(item, 'customFields', {});
            }
            if (!item.payload) {
                this.$set(item, 'payload', {});
            }
            if (!item.payload.customFields) {
                this.$set(item.payload, 'customFields', {});
            }
            if (!item.payload.infoplus_customfields) {
                this.$set(item.payload, 'infoplus_customfields', {});
            }
            this.$set(item.customFields, key, finalValue);
            this.$set(item.payload.customFields, key, finalValue);
            this.$set(item.payload.infoplus_customfields, key, finalValue);
        },
        async pushInfoplusToCartPayload() {
            const salesChannelId = this.salesChannelId;
            const contextToken = this.contextToken;
            if (!salesChannelId || !contextToken) return;
            const service = Shopware.Service('cartStoreService');
            const url = `_proxy/store-api/${salesChannelId}/infoplus/cart/line-item/custom-fields`;
            const headers = { ...service.getBasicHeaders(), 'sw-context-token': contextToken, 'X-Infoplus-Admin': '1' };
            const items = this.lineItems.map(item => {
                const out = { id: item.id, customFields: {} };
                const src = item.payload?.customFields || item.customFields || {};
                Object.keys(src).forEach(k => {
                    if (k.startsWith('infoplus_')) {
                        const fieldName = k.replace('infoplus_', '');
                        const fieldDef = this.infoplusCustomFields.find(f => f.technicalName === fieldName);
                        let val = src[k];
                        if (fieldDef && fieldDef.type === 'boolean') {
                            val = val ? 1 : 0;
                        }
                        out.customFields[k] = val;
                    }
                });
                if (!item.payload) this.$set(item, 'payload', {});
                if (!item.payload.infoplus_customfields) this.$set(item.payload, 'infoplus_customfields', {});
                this.$set(item.payload, 'infoplus_customfields', out.customFields);
                return out;
            }).filter(x => Object.keys(x.customFields).length > 0);
            if (!items.length) return;
            await service.httpClient.post(url, { items }, { headers });
        },
        async saveCustomFields() {
            let valid = true;
            const salesChannelId = this.salesChannelId;
            const contextToken = this.cart?.token;
            if (!salesChannelId || typeof salesChannelId !== 'string' || salesChannelId.length !== 32) {
                this.createNotificationError({
                    title: 'Sales Channel Error',
                    message: 'Sales Channel ID is missing or invalid.'
                });
                return;
            }
            if (!contextToken || typeof contextToken !== 'string') {
                this.createNotificationError({
                    title: 'Cart Error',
                    message: 'Cart token is missing or invalid.'
                });
                return;
            }
            for (const item of this.lineItems) {
                const infoplusCustomFields = {};
                if (item.customFields) {
                    Object.keys(item.customFields).forEach(key => {
                        if (key.startsWith('infoplus_')) {
                            const fieldName = key.replace('infoplus_', '');
                            const fieldDef = this.infoplusCustomFields.find(f => f.technicalName === fieldName);
                            let val = item.customFields[key];
                            if (fieldDef && fieldDef.type === 'boolean') {
                                val = val ? 1 : 0;
                            }
                            infoplusCustomFields[key] = val;
                        }
                    });
                }
                const assignableFields = this.assignableFieldsByLineItem[item.id] || [];
                for (const field of assignableFields) {
                    if (field.isRequired && field.type !== 'boolean') {
                        const value = infoplusCustomFields['infoplus_' + field.technicalName] || '';
                        if (!value) {
                            valid = false;
                        }
                    }
                    if (field.type === 'boolean') {
                        let value = infoplusCustomFields['infoplus_' + field.technicalName];
                        if (value === null || value === undefined) {
                            value = 0;
                        }
                        infoplusCustomFields['infoplus_' + field.technicalName] = value ? 1 : 0;
                    }
                }
                if (!item.payload) {
                    this.$set(item, 'payload', {});
                }
                if (!item.payload.customFields) {
                    this.$set(item.payload, 'customFields', {});
                }
                Object.assign(item.payload.customFields, infoplusCustomFields);
                if (!item.customFields) {
                    this.$set(item, 'customFields', {});
                }
                Object.assign(item.customFields, infoplusCustomFields);
            }
            if (!valid) {
                this.createNotificationError({
                    title: 'Validation Error',
                    message: 'Please fill all required custom fields.'
                });
                return;
            }
            try {
                await this.pushInfoplusToCartPayload();
                this.persistLineItemsToLocalStorage();
                await Store.get('swOrder').getCart({ salesChannelId: this.salesChannelId, contextToken: this.contextToken });
                this.createNotificationSuccess({
                    title: 'Success',
                    message: 'Custom fields saved successfully.'
                });
            } catch (error) {
                this.createNotificationError({
                    title: 'Save Error',
                    message: error.message || 'Failed to save custom fields.'
                });
            }
        }
    }
});
