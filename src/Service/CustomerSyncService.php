<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use InfoPlusCommerce\Client\InfoplusApiClient;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

class CustomerSyncService
{
    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly InfoplusApiClient $infoplusApiClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $customerRepository,
        private readonly IdMappingService $idMappingService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param Context $context
     * @param array<int|string>|null $ids
     * @return array<mixed>
     */
    public function syncCustomers(Context $context, ?array $ids = null): array
    {
        if (!($this->configService->get('syncOrder') || $this->configService->get('syncCustomers'))) {
            $this->logger->info('[InfoPlus] Customer sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.customerSyncDisabled')];
        }
        $this->logger->info('[InfoPlus] Sync customers triggered', ['ids' => $ids]);
        $criteria = new Criteria($ids === null ? null : array_map('strval', $ids));
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultBillingAddress.countryState');
        $customers = $this->customerRepository->search($criteria, $context)->getEntities();

        if ($customers->count() === 0) {
            $this->logger->warning('[InfoPlus] No customers found for sync', ['ids' => $ids]);
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.noCustomersFound')];
        }

        $lobId = $this->configService->get('lobId');
        $allCarriers = $this->infoplusApiClient->getCarriers(['filter' => "lobId eq $lobId"]);
        if (!$allCarriers) {
            $this->logger->error('[InfoPlus] Failed to fetch carriers', ['error' => $allCarriers]);
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.failedToFetchCarriers')];
        }
        $truckCarriers = array_filter($allCarriers, fn($carrier) => strpos($carrier['label'], 'TRUCK') !== false || $carrier['carrier'] === 100);
        $packageCarriers = array_filter($allCarriers, fn($carrier) => strpos($carrier['label'], 'UPS') !== false || strpos($carrier['label'], 'USPS') !== false);

        $truckCarrierId = !empty($truckCarriers) ? (string)reset($truckCarriers)['carrier'] : '100';
        $packageCarrierId = !empty($packageCarriers) ? (string)reset($packageCarriers)['carrier'] : '0';

        if (!$truckCarrierId || !$packageCarrierId) {
            $this->logger->warning('[InfoPlus] No truck or package carriers found, using default values (may cause error)');
            $truckCarrierId = $truckCarrierId ?: '100';
            $packageCarrierId = $packageCarrierId ?: '0';
        }

        $results = [];
        foreach ($customers as $customer) {
            /** @var CustomerEntity $customer */
            $billingAddress = $customer->getDefaultBillingAddress();
            if (!$billingAddress) {
                $this->logger->warning('[InfoPlus] No billing address found for customer ' . $customer->getId());
                continue;
            }

            $countryIso = (string)($billingAddress->getCountry()?->getIso() ?? '');
            $mappedCountry = MappingService::mapIsoToInfoplusCountry($countryIso);
            if ($mappedCountry === null) {
                $message = $this->translator->trans('infoplus.service.errors.countryMappingNotFound', ['%iso%' => $countryIso, '%customerId%' => $customer->getId()]);
                $this->logger->error('[InfoPlus] ' . $message, ['iso' => $countryIso, 'customerId' => $customer->getId()]);
                $results[] = [
                    'customerNo' => $customer->getId(),
                    'success' => false,
                    'error' => $message,
                ];
                continue;
            }

            $data = [
                'lobId' => (string)$lobId,
                'customerNo' => ($customer->getCustomerNumber() ?: $customer->getId()),
                'name' => $customer->getFirstName() . ' ' . $customer->getLastName(),
                'attention' => '',
                'street' => $billingAddress->getStreet(),
                'street2' => $billingAddress->getAdditionalAddressLine1() ?: '',
                'street3Province' => '',
                'city' => $billingAddress->getCity(),
                'zipCode' => $billingAddress->getZipcode(),
                'country' => $mappedCountry,
                'phone' => $billingAddress->getPhoneNumber(),
                'email' => $customer->getEmail(),
                'truckCarrierId' => $truckCarrierId,
                'packageCarrierId' => $packageCarrierId,
                'weightBreak' => '0',
                'residential' => 'No',
                'customFields' => null
            ];

            if ($mappedCountry === 'UNITED STATES') {
                $stateShort = (string)($billingAddress->getCountryState()?->getShortCode() ?? '');
                $mappedState = MappingService::mapIsoToInfoplusUsState($stateShort);
                if ($mappedState === null) {
                    $message = $this->translator->trans('infoplus.service.errors.usStateMappingNotFound', ['%state%' => $stateShort, '%customerId%' => $customer->getId()]);
                    $this->logger->error('[InfoPlus] ' . $message, ['state' => $stateShort, 'customerId' => $customer->getId()]);
                    $results[] = [
                        'customerNo' => $data['customerNo'],
                        'success' => false,
                        'error' => $message
                    ];
                    continue;
                }
                $data['state'] = $mappedState;
            }

            $existingCustomer = $this->infoplusApiClient->getCustomerByCustomerNo($lobId, $data['customerNo']);
            if ($existingCustomer === null) {
                $this->logger->error('[InfoPlus] Failed to fetch customer by customerNo', ['customerNo' => $data['customerNo'], 'error' => $existingCustomer]);
                $results[] = [
                    'customerNo' => $data['customerNo'],
                    'success' => false,
                    'error' => $existingCustomer
                ];
                continue;
            }
            if ($existingCustomer) {
                $data['id'] = $existingCustomer['id'];
                $result = $this->infoplusApiClient->updateCustomer($data);
                if (is_array($result) && isset($result['id'])) {
                    $this->idMappingService->createInfoplusId('customer', $customer->getId(), $context, $result['id']);
                }
            } else {
                $data['id'] = null;
                $result = $this->infoplusApiClient->createCustomer($data);
                if (is_array($result) && isset($result['id'])) {
                    $this->idMappingService->createInfoplusId('customer', $customer->getId(), $context, $result['id']);
                }
            }

            $results[] = [
                'customerNo' => $data['customerNo'],
                'success' => is_array($result),
                'error' => is_string($result) ? $result : null
            ];
            if (is_string($result)) {
                $this->logger->error('[InfoPlus] Failed to sync customer', [
                    'customerNo' => $data['customerNo'],
                    'name' => $data['name'],
                    'truckCarrierId' => $data['truckCarrierId'],
                    'packageCarrierId' => $data['packageCarrierId'],
                    'weightBreak' => $data['weightBreak'],
                    'residential' => $data['residential'],
                    'error' => $result
                ]);
            }
            usleep(500000);
        }
        return $results;
    }
}
