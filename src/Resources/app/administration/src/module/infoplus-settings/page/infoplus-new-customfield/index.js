import template from './infoplus-new-customfield.html.twig';

const { Component, Mixin } = Shopware;

Component.register('infoplus-new-customfield', {
    template,
    mixins: [Mixin.getByName('notification')],
    data() {
        return {
            customField: {
                technicalName: '',
                label: '',
                type: 'text',
                isRequired: false,
                optionsString: '',
                position: 0,
                showInStorefront: false,
                active: true,
                staticPrice: null,
            },
            isLoading: false,
            typeOptions: [
                { value: 'text', label: 'Text' },
                { value: 'textarea', label: 'Textarea' },
                { value: 'number', label: 'Number' },
                { value: 'money', label: 'Money' },
                { value: 'boolean', label: 'Boolean' },
                { value: 'select', label: 'Select' }
            ]
        };
    },
    methods: {
        async saveCustomField() {
            this.isLoading = true;
            try {
                if (!this.customField.technicalName.trim()) {
                    this.createNotificationError({
                        title: this.$tc('infoplus.common.errorTitle'),
                        message: 'Technical name is required'
                    });
                    return;
                }
                if (!this.customField.label.trim()) {
                    this.createNotificationError({
                        title: this.$tc('infoplus.common.errorTitle'),
                        message: 'Label is required'
                    });
                    return;
                }
                if (this.customField.type === 'select') {
                    this.customField.options = this.customField.optionsString;
                } else {
                    this.customField.options = [];
                }
                const payload = { ...this.customField };
                delete payload.optionsString;
                const response = await fetch('/api/_action/infoplus/customfields', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify(payload)
                });
                if (!response.ok) throw new Error('Failed to create custom field');
                this.createNotificationSuccess({
                    title: this.$tc('infoplus.customfield.save'),
                    message: this.$tc('infoplus.customfield.saved')
                });
                this.$router.push({ name: 'infoplus.settings.index.customfields' });
            } catch (e) {
                this.createNotificationError({
                    title: this.$tc('infoplus.common.errorTitle'),
                    message: e.message
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
});
