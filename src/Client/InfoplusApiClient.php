<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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

        $user = $this->userLimiter->consume(1);
        if (!$user->isAccepted()) {
            $user->wait();
        }

        $domain = $this->domainLimiter->consume(1);
        if (!$domain->isAccepted()) {
            $domain->wait();
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|string
     */
    private function requestWithRetry(string $method, string $endpoint, array $options = [], int $maxAttempts = 3): array|string
    {
        $method = strtoupper($method);
        $attempt = 1;

        $baseDelayUs = 1_000_000; // 1s
        $maxDelayUs = 10_000_000; // 10s

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
                    'response' => $body,
                    'statusCode' => $response->getStatusCode(),
                ]);

                /** @var array<string,mixed>|null $decoded */
                $decoded = json_decode($body, true);

                return $decoded ?? [];
            } catch (Throwable $e) {
                $statusCode = null;
                $retryAfterUs = null;

                if ($e instanceof RequestException && $e->hasResponse()) {
                    $statusCode = $e->getResponse()->getStatusCode();

                    $retryAfterHeader = $e->getResponse()->getHeaderLine('Retry-After');
                    if ($retryAfterHeader !== '') {
                        if (preg_match('/^\d+$/', $retryAfterHeader) === 1) {
                            $retryAfterUs = (int) $retryAfterHeader * 1_000_000;
                        } else {
                            $retryAt = strtotime($retryAfterHeader);
                            if ($retryAt !== false) {
                                $seconds = max(0, $retryAt - time());
                                $retryAfterUs = (int) $seconds * 1_000_000;
                            }
                        }
                    }
                }

                $isRateLimit = ($statusCode === 429);
                $isServerError = ($statusCode !== null && $statusCode >= 500);
                $shouldRetry = $isRateLimit || $isServerError;

                if (!$shouldRetry || $attempt >= $maxAttempts) {
                    $this->logger->error('[Infoplus API] Request failed', [
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'attempt' => $attempt,
                        'maxAttempts' => $maxAttempts,
                        'statusCode' => $statusCode,
                        'message' => $e->getMessage(),
                    ]);

                    return $e->getMessage();
                }

                $expDelayUs = (int) min($maxDelayUs, $baseDelayUs * (2 ** ($attempt - 1)));
                $jitterUs = random_int(0, 250_000);

                $delayUs = $expDelayUs + $jitterUs;
                if ($retryAfterUs !== null) {
                    $delayUs = max($delayUs, $retryAfterUs);
                }

                $this->logger->warning('[Infoplus API] Transient error, retrying...', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'attempt' => $attempt,
                    'nextAttempt' => $attempt + 1,
                    'statusCode' => $statusCode,
                    'delaySeconds' => $delayUs / 1_000_000,
                    'message' => $e->getMessage(),
                ]);

                usleep($delayUs);
                $attempt++;
            }
        }

        return "Request failed after $maxAttempts attempts";
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
     * @return array<int, array<string, mixed>>
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
    /**
     * Looks a customer up by customerNo, distinguishing "not in InfoPlus" from "the call failed".
     *
     * getCustomerByCustomerNo() cannot tell those apart: get() turns any transport or API error
     * into null, fetchAllPages() turns that into [], and the caller sees the same empty result it
     * would get for a customer that genuinely does not exist. Acting on that ambiguity is how a
     * caller ends up creating a duplicate customer every time InfoPlus is briefly unreachable.
     *
     * This calls get() directly, where the distinction still survives: null means the request
     * failed, [] means InfoPlus answered and holds no such customer.
     *
     * @return array{ok: bool, customer: array<string,mixed>|null}
     *     ok=false  the lookup itself failed — the caller must not assume anything about
     *               whether the customer exists
     *     ok=true   InfoPlus answered; customer is the record, or null if there is none
     */
    public function findCustomerByCustomerNo(string $lobId, string $customerNo): array
    {
        $response = $this->get('v3.0/customer/search', [
            'filter' => "lobId eq $lobId and customerNo eq '$customerNo'",
            'page' => 1,
            'limit' => 1,
        ]);

        if ($response === null) {
            return ['ok' => false, 'customer' => null];
        }

        $rows = array_values($response);
        return ['ok' => true, 'customer' => $rows[0] ?? null];
    }

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
