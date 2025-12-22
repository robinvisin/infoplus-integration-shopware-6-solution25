const {Criteria} = Shopware.Data;
import template from './sw-order-detail-general.html.twig';

Shopware.Component.override('sw-order-detail-general', {
    template,
    inject: ['repositoryFactory'],
    mixins: ['notification'],

    data() {
        return {
            isSynced: false,
            syncedDate: null,
            message: this.$tc('infoplus.sync.neverSynchronized'),
            isInProgress: false,
            infoPollInterval: null,
        };
    },
    created() {
        this.loadIInfoplusInformation();
        this.infoPollInterval = setInterval(() => {
            this.loadIInfoplusInformation();
        }, 30000); // every 30 seconds
    },

    beforeDestroy() {
        if (this.infoPollInterval) {
            clearInterval(this.infoPollInterval);
        }
    },
    methods: {
        resolveMessage(value) {
            if (typeof value === 'string' && value.startsWith('infoplus.')) {
                return this.$tc(value);
            }
            return value;
        },
        loadIInfoplusInformation() {
            const orderId = this.$route.params.id || this.order?.id;

            const repository = this.repositoryFactory.create('infoplus_order_sync');
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('shopwareOrderId', orderId));

            repository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    if (result && result.length > 0) {
                        const infoplusData = result[0];
                        this.isSynced = true;
                        this.syncedDate = new Date(infoplusData.syncDate);
                        this.message = `${this.$tc('infoplus.order.syncedAtPrefix')} ${this.syncedDate.toLocaleDateString()} ${this.syncedDate.toLocaleTimeString()}`;
                    } else {
                        this.isSynced = false;
                        this.syncedDate = null;
                        this.message = this.$tc('infoplus.sync.neverSynchronized');
                    }
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: this.$tc('infoplus.common.syncErrorTitle'),
                        message: `${this.$tc('infoplus.order.errors.failedToGetData')} ${error.message}`,
                    });
                });
        },
        async syncOrder() {
            const orderId = this.$route.params.id || this.order?.id;
            const transactions = this.order?.transactions || [];

            const systemConfigApiService = Shopware.Service('systemConfigApiService');
            const values = await systemConfigApiService.getValues('InfoPlusCommerce');
            const configuredStates = values['InfoPlusCommerce.config.syncablePaymentStates'];

            const syncablePaymentStates = Array.isArray(configuredStates) && configuredStates.length > 0
                ? configuredStates
                : ['open', 'paid', 'authorized'];

            const isSyncable = transactions.some(transaction => {
                return syncablePaymentStates.includes(transaction.stateMachineState?.technicalName);
            });

            if (!isSyncable) {
                this.createNotificationError({
                    title: this.$tc('infoplus.common.syncErrorTitle'),
                    message: this.$tc('infoplus.order.errors.paymentNotPaid'),
                });
                return;
            }
            if (!orderId) {
                this.createNotificationError({
                    title: this.$tc('infoplus.common.syncErrorTitle'),
                    message: this.$tc('infoplus.order.errors.missingId'),
                });
                return;
            }
            this.message = this.$tc('infoplus.order.syncing');
            this.isInProgress = true;
            fetch(`/api/_action/infoplus/sync/order/${orderId}`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": `Bearer ${Shopware.Context.api.authToken.access}`
                }
            })
                .then((response) => {
                    if (!response.ok) {
                        this.isInProgress = false;
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        this.isSynced = true;
                        this.syncedDate = new Date();
                        this.message = `${this.$tc('infoplus.order.syncedAtPrefix')} ${this.syncedDate.toLocaleDateString() + ' ' + this.syncedDate.toLocaleTimeString()}`;
                        this.createNotificationSuccess({
                            title: this.$tc('infoplus.common.syncSuccessTitle'),
                            message: this.message,
                        });
                    } else if (data && data.error) {
                        this.createNotificationError({
                            title: this.$tc('infoplus.common.syncErrorTitle'),
                            message: this.resolveMessage(data.error),
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc('infoplus.common.errorTitle'),
                            message: this.$tc('infoplus.order.errors.failedToRetrieveInfo'),
                        });
                    }

                })
                .catch(error => {
                    this.createNotificationError({
                        title: this.$tc('infoplus.common.syncErrorTitle'),
                        message: `${this.$tc('infoplus.order.errors.failedToSync')} ${error.message}`,
                    });
                })
                .finally(() => {
                    this.isInProgress = false;
                });
        }
    }
});
