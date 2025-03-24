<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RentOrderTransactionSubscriber implements EventSubscriberInterface
{
    private $logger;
    private readonly EntityRepository $orderRepository;
    private readonly EntityRepository $productRepository;
    private readonly EntityRepository $stateMachineStateRepository;
    private readonly EntityRepository $transactionRepository;

    public function __construct(LoggerInterface $logger, EntityRepository $orderRepository, EntityRepository $productRepository, EntityRepository $stateMachineStateRepository, EntityRepository $transactionRepository)
    {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->transactionRepository = $transactionRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'onOrderTransactionWritten',
        ];
    }

    public function onOrderTransactionWritten(EntityWrittenEvent $event): void
    {
        $results = $event->getWriteResults();
        $context = $event->getContext();

        foreach ($results as $result) {
            // check if the event is the type of transaction
            if ('order_transaction' !== $result->getEntityName()) {
                continue;
            }

            $payload = $result->getPayload();

            if (!$payload) {
                $this->logger->error('Borrow stock: Transaction payload not found, can not continue');

                continue;
            }

            // $this->logger->info('Transaction payload', ['payload' => $payload]);

            $transactionId = $payload['id'] ?? null;
            $transactionVersionId = $payload['versionId'] ?? null;

            if (!$transactionId) {
                $this->logger->error('Borrow stock: Transaction ID not found in payload');

                continue;
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $transactionId));

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $transactionId));
            $criteria->addFilter(new EqualsFilter('versionId', $transactionVersionId));
            $criteria->addAssociation('order');

            $transaction = $this->transactionRepository->search($criteria, $context)->first();

            if (!$transaction) {
                $this->logger->error('Borrow stock: Transaction not found', [
                    'transactionId' => $transactionId,
                ]);

                continue;
            }

            // Try again find the state id of the transaction
            $transactionStateId = $transaction->getStateId();

            if (!$transactionStateId) {
                $this->logger->error('Borrow stock: Transaction state ID not found in payload 2nd time, aborting!');

                continue;
            }

            // Add filter for OrderTransactionStateMachine
            $criteria = new Criteria();
            $criteria->addFilter(
                new EqualsFilter('stateMachine.technicalName', \sprintf('%s.state', OrderTransactionDefinition::ENTITY_NAME)),
                new EqualsAnyFilter('technicalName', [OrderTransactionStates::STATE_PAID, OrderTransactionStates::STATE_PARTIALLY_PAID, OrderTransactionStates::STATE_AUTHORIZED])
            );

            $defaultTransactionStatesIds = $this->stateMachineStateRepository->searchIds($criteria, $context)->getIds();

            if (!$defaultTransactionStatesIds) {
                $this->logger->error('ABORTED Borrow stock: Default transaction states not found');

                continue;
            }

            if (!in_array($transactionStateId, $defaultTransactionStatesIds)) {
                $this->logger->info('ABORTED Borrow stock: Transaction state is not paid, paid_partially or authorized', [
                    // 'defaultTransactionStatesIds' => $defaultTransactionStatesIds,
                    'transactionStateId' => $transactionStateId,
                ]);

                continue;
            }

            // get the order ID
            $orderId = $transaction->getOrderId();

            if (!$orderId) {
                $this->logger->error('Borrow stock: Order ID not found in transaction', [
                    'transactionId' => $transactionId,
                ]);

                continue;
            }

            $borrowStock = $this->borrowStock($orderId, $context);

            if ($borrowStock) {
                $this->logger->info('Borrow stock: Stock borrowing performed successfully', [
                    'orderId' => $orderId,
                ]);
            } else {
                $this->logger->info('Borrow stock: Stock borrowing not performed', [
                    'orderId' => $orderId,
                ]);
            }
        }
    }

    private function borrowStock($orderId, $context): bool
    {
        try {
            $this->logger->info('INIT Borrowing stock: Borrowing stock was initiated', [
                'orderId' => $orderId,
            ]);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));
            $criteria->addAssociation('transactions.stateMachineState');
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('customFields');
            $criteria->addAssociation('lineItems.payload');
            $criteria->addAssociation('lineItems.product.customFields');

            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order) {
                $this->logger->info('ABORTED Borrowing stock: Order not found', [
                    'orderId' => $orderId,
                ]);

                return false;
            }

            if (!$order instanceof OrderEntity) {
                $this->logger->info('ABORTED Borrowing stock: Order is not an instance of OrderEntity', [
                    'orderId' => $orderId,
                ]);

                return false;
            }

            $orderCustomFields = $order->getCustomFields();

            if (isset($orderCustomFields['os_subscriptions']['stock_borrowed']) && true === $orderCustomFields['os_subscriptions']['stock_borrowed']) {
                $this->logger->info('ABORTED Borrowing stock 0: Stock was already borrowed', [
                    'orderId' => $order->getId(),
                    // 'orderCustomFields' => $orderCustomFields,
                ]);

                return false;
            }

            if (!isset($orderCustomFields['os_subscriptions']['order_type'])
            or (isset($orderCustomFields['os_subscriptions']['order_type']) && 'initial' == $orderCustomFields['os_subscriptions']['order_type'])
            ) {
                $lineItems = $order->getLineItems();

                if (!$lineItems) {
                    $this->logger->info('ABORTED Borrowing stock 1: No line items found in order', [
                        'orderId' => $order->getId(),
                        // 'orderCustomFields' => $orderCustomFields,
                    ]);

                    return false;
                }

                foreach ($lineItems as $lineItem) {
                    $lineItemPayload = $lineItem->getPayload();

                    if (!isset($lineItemPayload['stock'])) {
                        $this->logger->info('ABORTED Borrowing stock 2: No line items payload found in order', [
                            'orderId' => $order->getId(),
                            // 'orderCustomFields' => $orderCustomFields,
                        ]);

                        return false;
                    }

                    // check if the stock is below the required quantity
                    if ($lineItemPayload['stock'] - $lineItem->getQuantity() < 0) {
                        // calculate the number of items to borrow to get the stock to the required quantity - zero in the end
                        // $numberOfItemsToBorrow = max(0, (int) $lineItem->getQuantity(), (int) $lineItemPayload['stock']); // - should we use this - fill up the stock only to zero?
                        $numberOfItemsToBorrow = (int) $lineItem->getQuantity() - (int) $lineItemPayload['stock'];
                    } else {
                        // there's enough stock
                        $this->logger->info('Borrowing stock NOT NEEDED', [
                            'orderId' => $order->getId(),
                            'lineItemQuantity' => (int) $lineItem->getQuantity(),
                            'lineItemPayloadStock' => (int) $lineItemPayload['stock'],
                        ]);

                        return false;
                    }

                    // if the number of items to borrow is above 0
                    if ($numberOfItemsToBorrow > 0) {
                        $this->logger->info('CONTINUED Borrowing stock: Line items was found in order, and the product stock was below the required purchased quantity', [
                            'orderId' => $order->getId(),
                            'lineItemQuantity' => (int) $lineItem->getQuantity(),
                            'lineItemPayloadStock' => (int) $lineItemPayload['stock'],
                            'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                        ]);

                        $criteria = new Criteria();
                        $criteria->addFilter(new EqualsFilter('id', $lineItem->getProductId()));
                        $product = $this->productRepository->search($criteria, $context)->first();

                        if ($product) {
                            // Perform actions with the product
                            $productCustomFields = $product->getCustomFields();

                            if (!$productCustomFields or !isset($productCustomFields['mollie_payments_product_subscription_enabled'])) {
                                $this->logger->info('ABORTED Borrowing stock 3: No product custom fields found', [
                                    'product' => $product->getId(),
                                    // 'productCustomFields' => $productCustomFields,
                                ]);

                                return false;
                            }
                            // Check if the product is a subscription product
                            $isSubscriptionProduct = true === $productCustomFields['mollie_payments_product_subscription_enabled'] ? true : false;

                            if (!$isSubscriptionProduct) {
                                $this->logger->info('ABORTED Borrowing stock 4: Product is not subscription type', [
                                    'product' => $product->getId(),
                                ]);

                                return false;
                            }

                            // Check if the product has a borrow product variant
                            if (!isset($productCustomFields['mollie_payments_product_parent_buy_variant'])) {
                                $this->logger->info('ABORTED Borrowing stock 5: Product has no borrowing variant', [
                                    'product' => $product->getId(),
                                    'orderCustomFields' => $orderCustomFields,
                                ]);

                                return false;
                            }

                            $borrowProductVariantId = $productCustomFields['mollie_payments_product_parent_buy_variant'];

                            if (!$borrowProductVariantId) {
                                return false;
                            }

                            // search for the borrow product variant
                            $criteria = new Criteria([$borrowProductVariantId]);
                            $criteria->addAssociation('customFields');

                            $productBorrowVariant = $this->productRepository->search($criteria, $context)->first();

                            // Check if the borrow product variant is available on stock
                            if (!$productBorrowVariant or ($productBorrowVariant->getAvailableStock() < $numberOfItemsToBorrow)) {
                                $this->logger->info('ABORTED Borrowing stock 6: Product borrowing variant doesnt exist or has not enough items on stock to borrow', [
                                    'productBorrowVariant' => $productBorrowVariant->getId(),
                                    'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                                    'productBorrowVariantAvailableStock' => $productBorrowVariant->getAvailableStock(),
                                    'productBorrowVariantStock' => $productBorrowVariant->getStock(),
                                    // 'orderCustomFields' => $orderCustomFields,
                                ]);

                                return false;
                            }

                            // now swap the product stock like this; substract sthe stock from productBorrowVariant by the line item quantity and add it to the product
                            // now swap the product stock like this; substract sthe stock from productBorrowVariant by the line item quantity and add it to the product
                            $this->logger->info('PROCESSING Borrowing stock: Reducing stock from borrow product variant', [
                                'orderId' => $orderId,
                                'lineItemId' => $lineItem->getId(),
                                'productId' => $lineItem->getProductId(),
                                'purchased' => $lineItem->getQuantity(),
                                'productBorrowVariant' => $productBorrowVariant->getId(),
                                'newStock' => $productBorrowVariant->getStock() - $numberOfItemsToBorrow,
                                'oldStock' => $productBorrowVariant->getStock(),
                                'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                            ]);

                            $this->logger->info('PROCCESING Borrowing stock: Adding stock to subscription variant', [
                                'orderId' => $orderId,
                                'lineItemId' => $lineItem->getId(),
                                'productId' => $lineItem->getProductId(),
                                'quantity' => $lineItem->getQuantity(),
                                'product' => $product->getId(),
                                'newStock' => $product->getStock() + $numberOfItemsToBorrow,
                                'oldStock' => $product->getStock(),
                                'numberOfItemsBorrowed' => $numberOfItemsToBorrow,
                            ]);

                            // get context of the product borrow variant
                            $productRepositoryContext = Context::createDefaultContext();

                            // Update the product stock
                            $this->productRepository->update(
                                [
                                    // update the product borrow variant
                                    [
                                        'id' => $productBorrowVariant->getId(),
                                        'availableStock' => $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow,
                                        'stock' => $productBorrowVariant->getStock() - $numberOfItemsToBorrow,
                                    ],
                                    // update the product
                                    [
                                        'id' => $product->getId(),
                                        'availableStock' => $product->getAvailableStock() + $numberOfItemsToBorrow,
                                        'stock' => $product->getStock() + $numberOfItemsToBorrow,
                                    ],
                                ],
                                $productRepositoryContext
                            );

                            $this->logger->info(
                                'PROCESSED Borrowing stock: Reduced stock from borrow product variant',
                                [
                                    'orderId' => $orderId,
                                    'productBorrowedFrom' => $productBorrowVariant->getId(),
                                    'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                                    'oldAvailableStock' => $productBorrowVariant->getAvailableStock(),
                                    'oldStock' => $productBorrowVariant->getStock(),
                                    'newAvailableStock' => $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow,
                                    'newStock' => $productBorrowVariant->getStock() - $numberOfItemsToBorrow,
                                ]
                            );

                            $this->logger->info(
                                'PROCESSED Borrowing stock: Added stock to subscription product variant',
                                [
                                    'orderId' => $orderId,
                                    'productBorrowedTo' => $product->getId(),
                                    'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                                    'oldAvailableStock' => $product->getAvailableStock(),
                                    'oldStock' => $product->getStock(),
                                    'newAvailableStock' => $product->getAvailableStock() - $numberOfItemsToBorrow,
                                    'newStock' => $product->getStock() - $numberOfItemsToBorrow,
                                ]
                            );

                            $orderCustomFields['os_subscriptions']['stock_borrowed'] = true;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from'] = $productBorrowVariant->getId();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_product_number'] = $productBorrowVariant->getProductNumber();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_product_title'] = $productBorrowVariant->getProductLabel();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_old_available_stock'] = $productBorrowVariant->getAvailableStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_old_stock'] = $productBorrowVariant->getStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_new_available_stock'] = $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_new_stock'] = $productBorrowVariant->getStock() - $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to'] = $product->getId();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_product_number'] = $product->getProductNumber();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_product_title'] = $product->getProductLabel();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_old_available_stock'] = $product->getAvailableStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_old_stock'] = $product->getStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_new_available_stock'] = $product->getAvailableStock() + $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_new_stock'] = $product->getStock() + $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_amount'] = $numberOfItemsToBorrow;

                            $this->orderRepository->update([
                                [
                                    'id' => $orderId,
                                    'customFields' => $orderCustomFields,
                                ],
                            ], $context);

                            $this->logger->info('PROCESSED Borrowing stock: Added borrowed stock data to order custom fields');
                        }
                    } else {
                        $this->logger->info('ABORTED Borrowing stock 7: No need to borrow anything', [
                            'orderId' => $order->getId(),
                            'lineItemQuantity' => (int) $lineItem->getQuantity(),
                            'lineItemPayloadStock' => (int) $lineItemPayload['stock'],
                            'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                            // 'orderCustomFields' => $orderCustomFields,
                        ]);
                    }
                }

                return true;
            }

            $this->logger->info('ABORTED Borrowing stock 8: Order is not of subscription initial type', [
                'orderId' => $orderId,
                // 'orderCustomFields' => $orderCustomFields,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->logger->error('ABORTED Borrowing stock EXCEPTION:', [
                'orderId' => $orderId ?? '',
                'order' => $order ?? '',
                'orderCustomFields' => $order->getCustomFields() ?? '',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }
}
