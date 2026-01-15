import template from './infoplus-sync-settings.html.twig';
import  '../sync-button';
import '../infoplus-switch';

const { Component, Mixin } = Shopware;

Component.register('infoplus-sync-settings', {
    template,
    mixins: [Mixin.getByName('notification')],
    data() {
        return {
            isLoading: false,
            activeTab: 'sync-settings',
            config: {
                apiKey: '',
                baseDomain: '',
                warehouseId: ''
            },
            paymentStateOptions: [],
            orderStateOptions: [],
            syncablePaymentStates: [],
            syncableOrderStates: [],
        };
    },
    created() {
        this.systemConfigApiService = Shopware.Service('systemConfigApiService');
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.loadConfig();
        this.loadPaymentStates();
        this.loadOrderStates();
    },
    methods: {
        async loadPaymentStates() {
            try {
                const response = await this.httpClient.get('/_action/infoplus/payment-states', {
                    headers: {
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    }
                });

                const items = response?.data?.data || [];
                this.paymentStateOptions = items.map(i => ({ value: i.id, label: i.name }));
            } catch (e) {
                this.paymentStateOptions = [];
                this.createNotificationError({
                    title: this.$tc('infoplus.common.syncErrorTitle'),
                    message: `Failed to load payment states: ${e.message}`,
                });
            }
        },

        async loadOrderStates() {
            try {
                const response = await this.httpClient.get('/_action/infoplus/order-states', {
                    headers: {
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    }
                });

                const items = response?.data?.data || [];
                this.orderStateOptions = items.map(i => ({ value: i.id, label: i.name }));
            } catch (e) {
                this.orderStateOptions = [];
                this.createNotificationError({
                    title: this.$tc('infoplus.common.syncErrorTitle'),
                    message: `Failed to load order states: ${e.message}`,
                });
            }
        },

        loadConfig() {
            this.isLoading = true;
            this.systemConfigApiService.getValues('InfoPlusCommerce').then(values => {
                this.config.apiKey = values['InfoPlusCommerce.config.apiKey'] || '';
                this.config.baseDomain = values['InfoPlusCommerce.config.baseDomain'] || '';
                this.config.warehouseId = values['InfoPlusCommerce.config.warehouseId'] || '';
                this.syncablePaymentStates = values['InfoPlusCommerce.config.syncablePaymentStates'] || [];
                this.syncableOrderStates = values['InfoPlusCommerce.config.syncableOrderStates'] || [];
                this.isLoading = false;
            });
        },
        saveConfig() {
            this.isLoading = true;
            const configValues = {
                'InfoPlusCommerce.config.apiKey': this.config.apiKey,
                'InfoPlusCommerce.config.baseDomain': this.config.baseDomain,
                'InfoPlusCommerce.config.warehouseId': this.config.warehouseId,
                'InfoPlusCommerce.config.syncablePaymentStates': this.syncablePaymentStates,
                'InfoPlusCommerce.config.syncableOrderStates': this.syncableOrderStates,
            };
            this.systemConfigApiService.saveValues(configValues).finally(() => {
                this.isLoading = false;
                this.createNotificationSuccess({
                    title: this.$tc('infoplus.sync.success'),
                    message: this.$tc('infoplus.sync.configurationSaved')
                });
            });
        }
    }
});