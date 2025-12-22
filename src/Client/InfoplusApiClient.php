<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Client;

use GuzzleHttp\Client;
use InfoPlusCommerce\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

class InfoplusApiClient
{
    private Client $httpClient;
    private LimiterInterface $userLimiter;
    private LimiterInterface $domainLimiter;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly LoggerInterface $logger,
        RateLimiterFactory $userRateLimiterFactory,
        RateLimiterFactory $domainRateLimiterFactory
    ) {
        $this->httpClient = new Client([
            'base_uri' => rtrim((string) $this->configService->get('baseDomain'), '/') . '/infoplus-wms/api/',
            'timeout' => 10,
        ]);
        $this->userLimiter = $userRateLimiterFactory->create('infoplus_user');
        $this->domainLimiter = $domainRateLimiterFactory->create('infoplus_domain_' . (string) $this->configService->get('baseDomain'));
    }

    /**
     * @return array<string,string>
     */
    private function getHeaders(): array
    {
        return [
            'API-Key' => (string) $this->configService->get('apiKey'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>|null
     */
    public function get(string $endpoint, array $query = []): ?array
    {
        $this->consumeRateLimit();
        try {
            $headers = $this->getHeaders();
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => $headers,
                'query' => $query,
            ]);
            $body = $response->getBody()->getContents();
            $this->logger->info('[Infoplus GET]', [
                'endpoint' => $endpoint,
                'query' => $query,
                'response' => $body
            ]);
            return json_decode($body, true);
        } catch (Throwable $e) {
            $this->logger->error('[Infoplus GET Error]', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function consumeRateLimit(): void
    {
        $waited = false;
        if (!$this->userLimiter->consume(1)->isAccepted()) {
            $this->userLimiter->consume(1)->wait();
            $waited = true;
        }
        if (!$waited && !$this->domainLimiter->consume(1)->isAccepted()) {
            $this->domainLimiter->consume(1)->wait();
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|string
     */
    private function requestWithRetry(string $method, string $endpoint, array $options = [], int $maxAttempts = 3): array|string
    {
        $attempt = 1;
        $delay = 1000000; // 1 second in microseconds

        while ($attempt <= $maxAttempts) {
            try {
                $this->consumeRateLimit();
                $headers = $this->getHeaders();
                $options['headers'] = array_merge($options['headers'] ?? [], $headers);
                $response = $this->httpClient->request($method, $endpoint, $options);
                $body = $response->getBody()->getContents();
                $this->logger->info("[Infoplus $method]", [
                    'endpoint' => $endpoint,
                    'options' => $options,
                    'response' => $body
                ]);
                return json_decode($body, true);
            } catch (Throwable $e) {
                if ($attempt < $maxAttempts) {
                    $this->logger->warning('[Infoplus API] Rate limit exceeded, retrying...', [
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'attempt' => $attempt,
                        'message' => $e->getMessage(),
                        'delay' => $delay / 1000000 . ' seconds'
                    ]);
                    usleep((int) $delay);
                    $delay = (int) ($delay * 1.5); // Increase delay for next attempt
                    $attempt++;
                    continue;
                }
                if ($attempt === $maxAttempts) {
                    $this->logger->error('[Infoplus API] Max retry attempts reached', [
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'attempt' => $attempt,
                        'message' => $e->getMessage()
                    ]);
                    return $e->getMessage();
                }
                $this->logger->warning('[Infoplus API] Retry attempt ' . $attempt, [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'message' => $e->getMessage()
                ]);
                usleep((int) $delay);
                $delay = (int) ($delay * 1.5);
                $attempt++;
            }
        }
        return "Limit exceeded after $maxAttempts attempts";
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|string
     */
    public function request(string $method, string $endpoint, array $options = []): array|string
    {
        $attempts = (int) ($this->configService->get('maxRetryAttempts') ?? 3);
        return $this->requestWithRetry($method, $endpoint, $options, $attempts);
    }

    /**
     * Fetches all pages of a search endpoint until no more data is returned.
     *
     * @param string $endpoint The API endpoint (e.g., 'v3.0/itemCategory/search')
     * @param array<string,mixed> $initialQuery Initial query parameters
     * @param int $limit Maximum records per page (default 250)
     * @return array<int, array<string, mixed>> All records combined from all pages
     */
    public function fetchAllPages(string $endpoint, array $initialQuery = [], int $limit = 250): array
    {
        $allRecords = [];
        $page = 1;
        if (isset($initialQuery['filter'])) {
            $initialQuery['filter'] = $initialQuery['filter'] . " and lobId eq {$this->configService->get('lobId')}";
        } else {
            $initialQuery['filter'] = "lobId eq {$this->configService->get('lobId')}";
        }
        while (true) {
            $query = array_merge($initialQuery, [
                'page' => $page,
                'limit' => $limit,
            ]);

            $response = $this->get($endpoint, $query);
            if ($response === null || empty($response)) {
                break; // No more data or error
            }

            $items = array_values($response);
            $allRecords = array_merge($allRecords, $items);
            $page++;

            if (\count($response) < $limit) {
                break;
            }
        }

        $this->logger->info('[Infoplus fetchAllPages]', [
            'endpoint' => $endpoint,
            'totalRecords' => \count($allRecords),
            'pagesFetched' => $page - 1
        ]);
        return $allRecords;
    }

    /**
     * @return array<string,mixed>
     */
    public function getLineOfBusiness(): array
    {
        return $this->get('v3.0/lineOfBusiness/search') ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getWarehouses(): array
    {
        return $this->get('v3.0/warehouse/search') ?? [];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string,mixed>>
     */
    public function getCarriers(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/carrier/search', $query);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getItem(int $id): ?array
    {
        return $this->get('v3.0/item/' . $id);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/item/search', $query);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getBySKU(int $lobId, string $sku): ?array
    {
        $query = [
            'lobId' => $lobId,
            'sku' => $sku,
        ];
        return $this->get('v3.0/item/getBySKU', $query) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function createItem(array $data): array|string
    {
        return $this->request('POST', 'v3.0/item', ['json' => $data]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateItem(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/item', ['json' => $data]);
    }

    /**
     * @return array<string,mixed>|string
     */
    public function deleteItem(int $id): array|string
    {
        return $this->request('DELETE', 'v3.0/item/' . $id);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function searchItemCategories(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/itemCategory/search', $query);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function createItemCategory(array $data): array|string
    {
        return $this->request('POST', 'v3.0/itemCategory', ['json' => $data]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateItemCategory(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/itemCategory', ['json' => $data]);
    }

    /**
     * @return array<string,mixed>|string
     */
    public function deleteItemCategory(int $id): array|string
    {
        return $this->request('DELETE', 'v3.0/itemCategory/' . $id);
    }

    /**
     * @return array<string,mixed>|string
     */
    public function deleteItemSubCategory(int $id): array|string
    {
        return $this->request('DELETE', 'v3.0/itemSubCategory/' . $id);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function searchCustomers(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/customer/search', $query);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCustomerByCustomerNo(string $lobId, string $customerNo): ?array
    {
        $query = [
            'filter' => "lobId eq $lobId and customerNo eq '$customerNo'"
        ];
        $customers = $this->searchCustomers($query);
        return !empty($customers) ? array_values($customers)[0] : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function createCustomer(array $data): array|string
    {
        return $this->request('POST', 'v3.0/customer', ['json' => $data]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateCustomer(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/customer', ['json' => $data]);
    }

    /**
     * @return array<string,mixed>|string
     */
    public function deleteCustomer(int $id): array|string
    {
        return $this->request('DELETE', 'v3.0/customer/' . $id);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function searchOrders(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/order/search', $query);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getOrderByOrderNo(int $orderNo): ?array
    {
        $query = ['filter' => "orderNo eq $orderNo"];
        $orders = $this->searchOrders($query);
        return !empty($orders) ? array_values($orders)[0] : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function createOrder(array $data): array|string
    {
        return $this->request('POST', 'v3.0/order', ['json' => $data]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateOrder(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/order', ['json' => $data]);
    }

    /**
     * @return array<string,mixed>|string
     */
    public function deleteOrder(int $id): array|string
    {
        return $this->request('DELETE', 'v3.0/order/' . $id);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function searchInventoryAdjustments(array $query = []): array
    {
        return $this->fetchAllPages('v3.0/inventoryAdjustment/search', $query);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateInventory(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/inventory', ['json' => $data]);
    }

    public function setHttpClient(Client $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * @param array<string,mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function getItemCategories(array $filter = []): array
    {
        return $this->fetchAllPages('v3.0/itemCategory/search', $filter);
    }

    /**
     * @param array<string,mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function getItemSubCategories(array $filter = []): array
    {
        return $this->fetchAllPages('v3.0/itemSubCategory/search', $filter);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function createItemSubCategory(array $data): array|string
    {
        return $this->request('POST', 'v3.0/itemSubCategory', ['json' => $data]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|string
     */
    public function updateItemSubCategory(array $data): array|string
    {
        return $this->request('PUT', 'v3.0/itemSubCategory', ['json' => $data]);
    }
}
