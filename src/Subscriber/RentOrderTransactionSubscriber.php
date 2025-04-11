<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RentOrderTransactionSubscriber implements EventSubscriberInterface
{
    private $logger;
    private readonly EntityRepository $orderRepository;
    private readonly EntityRepository $productRepository;
    private readonly EntityRepository $stateMachineStateRepository;
    private readonly EntityRepository $transactionRepository;
    private readonly StateMachineRegistry $stateMachineRegistry;

    public function __construct(LoggerInterface $logger, EntityRepository $orderRepository, EntityRepository $productRepository, EntityRepository $stateMachineStateRepository, EntityRepository $transactionRepository, StateMachineRegistry $stateMachineRegistry)
    {
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->transactionRepository = $transactionRepository;
        $this->stateMachineRegistry = $stateMachineRegistry;
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
            // $this->logger->info('Event write result', [
            //     'result' => $result->getExistence(),
            //     'operation' => $result->getOperation(),
            //     'entityName' => $result->getEntityName(),
            //     'payload' => $result->getPayload(),
            // ]);
            // check if the event is the type of transaction and operation is update
            // INFO: this event is called several times but we have to ensure
            // that the condition is only applied once within the given context - so we are executing on update.
            if ('order_transaction' !== $result->getEntityName() or 'update' !== $result->getOperation()) {
                continue;
            }

            $payload = $result->getPayload();

            if (!$payload) {
                // $this->logger->error('Borrow stock: Transaction payload not found, can not continue');

                continue;
            }

            $transactionId = $payload['id'] ?? null;
            $transactionVersionId = $payload['versionId'] ?? null;

            if (!$transactionId) {
                // $this->logger->error('Borrow stock: Transaction ID not found in payload');

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
                // $this->logger->error('Borrow stock: Transaction not found', [
                //     'transactionId' => $transactionId,
                // ]);

                continue;
            }

            // Try again find the state id of the transaction
            $transactionStateId = $transaction->getStateId();

            if (!$transactionStateId) {
                // $this->logger->error('Borrow stock: Transaction state ID not found in payload 2nd time, aborting!');

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
                // $this->logger->error('ABORTED Borrow stock: Default transaction states not found');

                continue;
            }

            if (!in_array($transactionStateId, $defaultTransactionStatesIds)) {
                // $this->logger->info('ABORTED Borrow stock: Transaction state is not paid, paid_partially or authorized', [
                //     // 'defaultTransactionStatesIds' => $defaultTransactionStatesIds,
                //     'transactionStateId' => $transactionStateId,
                // ]);

                continue;
            }

            // get the order ID
            $orderId = $transaction->getOrderId();

            if (!$orderId) {
                // $this->logger->error('Borrow stock: Order ID not found in transaction', [
                //     'transactionId' => $transactionId,
                // ]);

                continue;
            }

            $borrowStock = $this->borrowStock($orderId, $context);

            if ($borrowStock) {
                $this->logger->info('Borrow stock: Stock borrowing performed successfully', [
                    'orderId' => $orderId,
                ]);
            }
        }
    }

    private function borrowStock($orderId, $context): bool
    {
        try {
            // $this->logger->info('INIT Borrowing stock: Borrowing stock was initiated', [
            //     'orderId' => $orderId,
            // ]);

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));
            $criteria->addAssociation('transactions.stateMachineState');
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('lineItems.product.customFields');
            $criteria->addAssociation('customFields');
            $criteria->addAssociation('deliveries');

            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order) {
                // $this->logger->info('ABORTED Borrowing stock: Order not found', [
                //     'orderId' => $orderId,
                // ]);

                return false;
            }

            if (!$order instanceof OrderEntity) {
                // $this->logger->info('ABORTED Borrowing stock: Order is not an instance of OrderEntity', [
                //     'orderId' => $orderId,
                // ]);

                return false;
            }

            $orderCustomFields = $order->getCustomFields();

            if (isset($orderCustomFields['os_subscriptions']['stock_borrowed']) && true === $orderCustomFields['os_subscriptions']['stock_borrowed']) {
                $this->logger->info('ABORTED Borrowing stock: Stock was already borrowed', [
                    'orderId' => $order->getId(),
                    // 'orderCustomFields' => $orderCustomFields,
                ]);

                return false;
            }

            if (isset($orderCustomFields['os_subscriptions']['stock_borrowed']) && false === $orderCustomFields['os_subscriptions']['stock_borrowed']) {
                // order doesn't need borrowing but
                return false;
            }

            if (!isset($orderCustomFields['os_subscriptions']['order_type'])
            or (isset($orderCustomFields['os_subscriptions']['order_type']) and 'initial' == $orderCustomFields['os_subscriptions']['order_type'])
            ) {
                $lineItems = $order->getLineItems();

                if (!$lineItems) {
                    // $this->logger->info('ABORTED Borrowing stock 1: No line items found in order', [
                    //     'orderId' => $order->getId(),
                    //     // 'orderCustomFields' => $orderCustomFields,
                    // ]);

                    return false;
                }

                foreach ($lineItems as $lineItem) {
                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsFilter('id', $lineItem->getProductId()));
                    $product = $this->productRepository->search($criteria, $context)->first();

                    $lineItemPayload = $lineItem->getPayload();

                    if ($product) {
                        // Perform actions with the product
                        $productCustomFields = $product->getCustomFields();

                        if (!$productCustomFields or !isset($productCustomFields['mollie_payments_product_subscription_enabled'])) {
                            return false;
                        }
                        // Check if the product is a subscription product
                        $isSubscriptionProduct = true === $productCustomFields['mollie_payments_product_subscription_enabled'] ? true : false;

                        if (!$isSubscriptionProduct) {
                            return false;
                        }

                        // Check if the product has a borrow product variant
                        if (!isset($productCustomFields['mollie_payments_product_parent_buy_variant'])) {
                            $this->logger->info('ABORTED Borrowing stock: Product has no borrowing variant', [
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

                        $lineItemStockAtTheTime = (int) $lineItemPayload['stock'];

                        // check the stock at the time of execution - this is the stock that was set in the order,
                        // but if fails screw it, then use the current stock
                        if ($lineItemStockAtTheTime) {
                            $stockValueToCalculateBorrow = $lineItemStockAtTheTime;
                        } else {
                            $stockValueToCalculateBorrow = (int) $product->getStock();
                        }

                        // check if the stock is below the required quantity
                        if ($stockValueToCalculateBorrow - $lineItem->getQuantity() < 0) {
                            // calculate the number of items to borrow to get the stock to the required quantity - zero in the end
                            // $numberOfItemsToBorrow = max(0, (int) $lineItem->getQuantity(), (int) $product->getStock()); // - should we use this - fill up the stock only to zero?
                            $numberOfItemsToBorrow = (int) $lineItem->getQuantity() - $stockValueToCalculateBorrow;
                        } else {
                            // there's enough stock

                            // when the order doesnt need borrowing then write the custom field to false so it doesnt repeat process multiple times
                            $orderCustomFields['os_subscriptions']['stock_borrowed'] = false;
                            $this->orderRepository->update([
                                [
                                    'id' => $orderId,
                                    'customFields' => $orderCustomFields,
                                ],
                            ], $context);

                            return false;
                        }

                        // if the number of items to borrow is above 0
                        if ($numberOfItemsToBorrow > 0) {
                            // Check if the borrow product variant is available on stock
                            if (!$productBorrowVariant or ($productBorrowVariant->getAvailableStock() < $numberOfItemsToBorrow)) {
                                $this->logger->info('ABORTED Borrowing stock: Product borrowing variant doesnt exist or has not enough items on stock to borrow', [
                                    'productBorrowVariant' => $productBorrowVariant->getId(),
                                    'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                                    'productBorrowVariantAvailableStock' => $productBorrowVariant->getAvailableStock(),
                                    'productBorrowVariantStock' => $productBorrowVariant->getStock(),
                                    // 'orderCustomFields' => $orderCustomFields,
                                ]);

                                return false;
                            }

                            // get context of the product borrow variant
                            $productRepositoryContext = Context::createDefaultContext();

                            $productNewAvailableStock = $product->getAvailableStock();

                            if ($product->getAvailableStock() - $lineItem->getQuantity() < 0) {
                                $productNewAvailableStock = 0;
                            } else {
                                $productNewAvailableStock = $product->getAvailableStock() - $lineItem->getQuantity();
                            }

                            $productNewStock = $product->getStock();

                            if ($product->getStock() - $lineItem->getQuantity() < 0) {
                                $productNewStock = 0;
                            } else {
                                $productNewStock = $product->getStock() - $lineItem->getQuantity();
                            }

                            // Update the products stock
                            $this->productRepository->update(
                                [
                                    // update the product borrow variant
                                    [
                                        'id' => $productBorrowVariant->getId(),
                                        'availableStock' => $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow,
                                        'stock' => $productBorrowVariant->getStock() - $numberOfItemsToBorrow,
                                    ],
                                    // update the product available stock since if it is ever below 0 it will cause problems for borrowing, renewals, and buyout
                                    [
                                        'id' => $product->getId(),
                                        'availableStock' => $productNewAvailableStock, // we need to set it to 0 so it doesnt become available for purchase before the order was marked as completed. //$product->getAvailableStock() + $numberOfItemsToBorrow
                                        'stock' => $productNewStock, // do we need to update this type of stock of the product so it doesnt becaome available for purchase before the order was marked as completed
                                    ],
                                ],
                                $productRepositoryContext
                            );

                            // Update the product stock
                            $this->productRepository->update(
                                [
                                    // update the product available stock since if it is ever below 0 it will cause problems for borrowing, renewals, and buyout
                                    [
                                        'id' => $product->getId(),
                                        'availableStock' => 0, // we need to set it to 0 so it doesnt become available for purchase before the order was marked as completed. //$product->getAvailableStock() + $numberOfItemsToBorrow
                                        // 'stock' => $product->getStock() + $numberOfItemsToBorrow, // do we need to update this type of stock of the product so it doesnt becaome available for purchase before the order was marked as completed
                                    ],
                                ],
                                $productRepositoryContext
                            );

                            $this->logger->info(
                                'PROCESSED Borrowing stock: STOCK UPDATES PERFORMED',
                                [
                                    'orderId' => $orderId,
                                    'productBorrowedFrom' => $productBorrowVariant->getId(),
                                    'numberOfItemsToBorrow' => $numberOfItemsToBorrow,
                                    'oldStockBorrowedFrom' => $productBorrowVariant->getStock(),
                                    'oldAvailableStockBorrowedFrom' => $productBorrowVariant->getAvailableStock(),
                                    'newAvailableStockBorrowedFrom' => $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow,
                                    'newStockBorrowedFrom' => $productBorrowVariant->getStock() - $numberOfItemsToBorrow,
                                    'oldStockBorrowedTo' => $product->getStock(),
                                    'oldAvailableStockBorrowedTo' => $product->getAvailableStock(),
                                    'newAvailableStockBorrowedTo' => $productNewAvailableStock,
                                    'newStockBorrowedTo' => $productNewStock,
                                ]
                            );

                            // write the borrowing data
                            $orderCustomFields['os_subscriptions']['stock_borrowed'] = true;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from'] = $productBorrowVariant->getId();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to'] = $product->getId();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_old_stock'] = $product->getStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_old_available_stock'] = $product->getAvailableStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_new_stock'] = $productNewStock;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to_new_available_stock'] = $productNewAvailableStock;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_old_stock'] = $productBorrowVariant->getStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_old_available_stock'] = $productBorrowVariant->getAvailableStock();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_new_stock'] = $productBorrowVariant->getStock() - $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from_new_available_stock'] = $productBorrowVariant->getAvailableStock() - $numberOfItemsToBorrow;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_amount'] = $numberOfItemsToBorrow;

                            $this->orderRepository->update([
                                [
                                    'id' => $orderId,
                                    'customFields' => $orderCustomFields,
                                ],
                            ], $context);

                            $this->logger->info(
                                'PROCESSED Borrowing stock: Added borrowed stock data to order custom fields',
                                [
                                    'orderCustomFields' => $orderCustomFields,
                                ]
                            );
                        }
                    } else {
                        continue;
                    }
                }

                return true;
            }

            if (isset($orderCustomFields['os_subscriptions']['order_type'])
                and 'initial' != $orderCustomFields['os_subscriptions']['order_type']
            ) {
                // we need to complete the order - mark as completed and ship the delivery
                $this->completeTheOrder($order, $context);
            } else {
                // $this->logger->info('ABORTED Borrowing stock 7: Order is not of subscription initial type', [
                //     'orderId' => $order->getId(),
                //     // 'orderCustomFields' => $orderCustomFields,
                // ]);

                return false;
            }
            $this->logger->info('ABORTED Borrowing stock: Order is not of subscription INITIAL type', [
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

    /*
    private function returnAvailableStockForRenewalsAndBuyouts($order, $context)
    {
        $lineItems = $order->getLineItems();

        if (!$lineItems) {
            $this->logger->info('ABORTED Borrowing stock 1: No line items found in order', [
                'orderId' => $order->getId(),
                // 'orderCustomFields' => $orderCustomFields,
            ]);

            return false;
        }

        foreach ($lineItems as $lineItem) {
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
            }
        }
    } */

    private function completeTheOrder($order, $context): void
    {
        // check if the order delivery is already shipped
        if ('open' == $order->getDeliveries()->first()->getStateMachineState()->getTechnicalName()) {
            $orderDeliveryId = $order->getDeliveries()->first()->getId();

            // transition the order delivery to shipped state
            $this->stateMachineRegistry->transition(new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $orderDeliveryId,
                'ship',
                'stateId'
            ), $context);

            $this->logger->info('Order delivery states updated to shipped', [
                'orderId' => $order->getId(),
                'deliveryId' => $order->getDeliveries()->first()->getId(),
            ]);
        }

        // check if the order is on open state and process
        if ('open' == $order->getStateMachineState()->getTechnicalName()) {
            // transition the order to process state
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $order->getId(),
                    'process',
                    'stateId'
                ),
                $context
            );

            $this->logger->info('Order state updated to processing', [
                'orderId' => $order->getId(),
            ]);
        }

        // check if the order is in progress state
        if ('in_progress' == $order->getStateMachineState()->getTechnicalName()) {
            // transition the order to completed state
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $order->getId(),
                    'complete',
                    'stateId'
                ),
                $context
            );

            $this->logger->info('Order state updated to completed', [
                'orderId' => $order->getId(),
            ]);

            // we need to return the available stock for renewals and buyouts because shopware somehone messes up available stock
            $this->returnStockForRenewalsAndBuyouts($order, $context);
        }
    }

    private function returnStockForRenewalsAndBuyouts($order, $context)
    {
        $lineItems = $order->getLineItems();

        if (!$lineItems) {
            return false;
        }

        foreach ($lineItems as $lineItem) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $lineItem->getProductId()));
            $product = $this->productRepository->search($criteria, $context)->first();

            if ($product) {
                // Perform actions with the product
                $productCustomFields = $product->getCustomFields();

                if (!$productCustomFields or !isset($productCustomFields['mollie_payments_product_subscription_enabled'])) {
                    return false;
                }
                // Check if the product is a subscription product
                $isSubscriptionProduct = true === $productCustomFields['mollie_payments_product_subscription_enabled'] ? true : false;

                if (!$isSubscriptionProduct) {
                    return false;
                }

                $productRepositoryContext = Context::createDefaultContext();
                // Update the product stock
                $this->productRepository->update(
                    [
                        // update the product available stock
                        [
                            'id' => $product->getId(),
                            'availableStock' => $product->getAvailableStock() + $lineItem->getQuantity(),
                        ],
                    ],
                    $productRepositoryContext
                );
            }
        }
    }
}
