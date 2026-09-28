<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Command;

use InfoPlusCommerce\Service\SyncService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Order\OrderCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'infoplus:sync',
    description: 'Sync all entities (customers, categories, products, orders) with InfoPlus in sequence'
)]
class SyncCommand extends Command
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly SyncService $syncService,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $orderRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('[InfoPlus] Full sync command triggered');
        $output->writeln('Starting full synchronization with InfoPlus...');
        $context = Context::createCLIContext();

        $this->syncService->getConfigService()->set('syncInProgress', true);
        // Step 1: Sync Customers
        $output->writeln('Synchronizing customers...');
        try {
            $customerResults = $this->syncService->syncCustomers($context);
            if (isset($customerResults['status']) && $customerResults['status'] === 'no customers found') {
                $output->writeln('No customers found for synchronization.');
                $this->logger->warning('[InfoPlus] No customers found for sync');
            } elseif (isset($customerResults['status']) && $customerResults['status'] === 'error') {
                // The service reports a failure it could not get past - a dead API, missing
                // carriers - as ['status' => 'error', 'error' => ...], NOT as a list of
                // per-record results. Falling through to the loop below iterates that array's
                // VALUES and prints one meaningless "Failed to sync customer -: unknown
                // error" line per key, naming no record and hiding the actual reason.
                $reason = is_string($customerResults['error'] ?? null) ? $customerResults['error'] : 'no reason given';
                $output->writeln(sprintf('Customer sync could not run: %s', $reason));
                $this->logger->error('[InfoPlus] Customer sync could not run', ['error' => $reason]);
            } else {
                /** @var array<string,mixed> $result */
                foreach ($customerResults as $result) {
                    if (isset($result['success']) && $result['success'] === true) {
                        $output->writeln(sprintf('Customer %s synced successfully.', $result['customerNo'] ?? '-'));
                    } else {
                        $output->writeln(sprintf('Failed to sync customer %s: %s', $result['customerNo'] ?? '-', $result['error'] ?? 'unknown error'));
                        $this->logger->error('[InfoPlus] Failed to sync customer', ['customerNo' => $result['customerNo'] ?? '-', 'error' => $result['error'] ?? 'unknown']);
                    }
                }
            }
            $output->writeln('Customer synchronization completed.');
        } catch (\Exception $e) {
            $this->syncService->getConfigService()->set('syncInProgress', false);
            $output->writeln(sprintf('Customer sync failed: %s', $e->getMessage()));
            $this->logger->error('[InfoPlus] Customer sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // Step 2: Sync Categories
        $output->writeln('Synchronizing categories...');
        try {
            $categoryResults = $this->syncService->syncCategories($context);
            if (isset($categoryResults['status']) && $categoryResults['status'] === 'no categories found') {
                $output->writeln('No categories found for synchronization.');
                $this->logger->warning('[InfoPlus] No categories found for sync');
            } elseif (isset($categoryResults['status']) && $categoryResults['status'] === 'error') {
                // The service reports a failure it could not get past - a dead API, missing
                // carriers - as ['status' => 'error', 'error' => ...], NOT as a list of
                // per-record results. Falling through to the loop below iterates that array's
                // VALUES and prints one meaningless "Failed to sync category -: unknown
                // error" line per key, naming no record and hiding the actual reason.
                $reason = is_string($categoryResults['error'] ?? null) ? $categoryResults['error'] : 'no reason given';
                $output->writeln(sprintf('Category sync could not run: %s', $reason));
                $this->logger->error('[InfoPlus] Category sync could not run', ['error' => $reason]);
            } else {
                /** @var array<string,mixed> $result */
                foreach ($categoryResults as $result) {
                    if (isset($result['success']) && $result['success'] === true) {
                        $output->writeln(sprintf('Category %s (%s) synced successfully.', $result['name'] ?? '-', $result['type'] ?? '-'));
                    } else {
                        $output->writeln(sprintf('Failed to sync category %s (%s): %s', $result['name'] ?? '-', $result['type'] ?? '-', $result['error'] ?? 'unknown error'));
                        $this->logger->error('[InfoPlus] Failed to sync category', ['name' => $result['name'] ?? '-', 'type' => $result['type'] ?? '-', 'error' => $result['error'] ?? 'unknown']);
                    }
                }
            }
            $output->writeln('Category synchronization completed.');
        } catch (\Exception $e) {
            $this->syncService->getConfigService()->set('syncInProgress', false);
            $output->writeln(sprintf('Category sync failed: %s', $e->getMessage()));
            $this->logger->error('[InfoPlus] Category sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // Step 3: Sync Products
        $output->writeln('Synchronizing products...');
        try {
            $productResults = $this->syncService->syncProducts($context);
            if (isset($productResults['status']) && $productResults['status'] === 'no products found') {
                $output->writeln('No products found for synchronization.');
                $this->logger->warning('[InfoPlus] No products found for sync');
            } elseif (isset($productResults['status']) && $productResults['status'] === 'error') {
                // The service reports a failure it could not get past - a dead API, missing
                // carriers - as ['status' => 'error', 'error' => ...], NOT as a list of
                // per-record results. Falling through to the loop below iterates that array's
                // VALUES and prints one meaningless "Failed to sync product -: unknown
                // error" line per key, naming no record and hiding the actual reason.
                $reason = is_string($productResults['error'] ?? null) ? $productResults['error'] : 'no reason given';
                $output->writeln(sprintf('Product sync could not run: %s', $reason));
                $this->logger->error('[InfoPlus] Product sync could not run', ['error' => $reason]);
            } else {
                /** @var array<string,mixed> $result */
                foreach ($productResults as $result) {
                    if (isset($result['success']) && $result['success'] === true) {
                        $output->writeln(sprintf('Product %s synced successfully.', $result['sku'] ?? '-'));
                    } else {
                        $output->writeln(sprintf('Failed to sync product %s: %s', $result['sku'] ?? '-', $result['error'] ?? 'unknown error'));
                        $this->logger->error('[InfoPlus] Failed to sync product', ['sku' => $result['sku'] ?? '-', 'error' => $result['error'] ?? 'unknown']);
                    }
                }
            }
            $output->writeln('Product synchronization completed.');
        } catch (\Exception $e) {
            $this->syncService->getConfigService()->set('syncInProgress', false);
            $output->writeln(sprintf('Product sync failed: %s', $e->getMessage()));
            $this->logger->error('[InfoPlus] Product sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        // Step 4: Sync Orders
        $output->writeln('Synchronizing orders...');
        try {
            $criteria = new Criteria();
            $orderIds = $this->orderRepository->searchIds($criteria, $context)->getIds();
            /** @var array<string> $orderIds */
            if (empty($orderIds)) {
                $output->writeln('No orders found for synchronization.');
                $this->logger->warning('[InfoPlus] No orders found for sync');
            } else {
                $orderResults = $this->syncService->syncOrders($orderIds, $context);
                if (isset($orderResults['status']) && $orderResults['status'] === 'no order found') {
                    $output->writeln('No order found for synchronization.');
                    $this->logger->warning('[InfoPlus] No orders found for sync');
                } elseif (isset($orderResults['status']) && $orderResults['status'] === 'error') {
                    // The service reports a failure it could not get past - a dead API, missing
                    // carriers - as ['status' => 'error', 'error' => ...], NOT as a list of
                    // per-record results. Falling through to the loop below iterates that array's
                    // VALUES and prints one meaningless "Failed to sync order -: unknown
                    // error" line per key, naming no record and hiding the actual reason.
                    $reason = is_string($orderResults['error'] ?? null) ? $orderResults['error'] : 'no reason given';
                    $output->writeln(sprintf('Order sync could not run: %s', $reason));
                    $this->logger->error('[InfoPlus] Order sync could not run', ['error' => $reason]);
                } else {
                    /** @var array<string,mixed> $result */
                    foreach ($orderResults as $result) {
                        if (isset($result['success']) && $result['success'] === true) {
                            $output->writeln(sprintf('Order %s synced successfully.', $result['orderNo'] ?? '-'));
                        } else {
                            $output->writeln(sprintf('Failed to sync order %s: %s', $result['orderNo'] ?? '-', $result['error'] ?? 'unknown error'));
                            $this->logger->error('[InfoPlus] Failed to sync order', ['orderNo' => $result['orderNo'] ?? '-', 'error' => $result['error'] ?? 'unknown']);
                        }
                    }
                }
            }
            $output->writeln('Order synchronization completed.');
        } catch (\Exception $e) {
            $this->syncService->getConfigService()->set('syncInProgress', false);
            $output->writeln(sprintf('Order sync failed: %s', $e->getMessage()));
            $this->logger->error('[InfoPlus] Order sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        $this->syncService->getConfigService()->set('syncInProgress', false);
        $this->syncService->getConfigService()->set('lastSyncTime', date('Y-m-d H:i:s'));
        $output->writeln('Full synchronization with InfoPlus completed.');
        $this->logger->info('[InfoPlus] Full sync command completed');
        return Command::SUCCESS;
    }
}
