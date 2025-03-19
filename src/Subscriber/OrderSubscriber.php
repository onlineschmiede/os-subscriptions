<?php

namespace OsSubscriptions\Subscriber;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use OsSubscriptions\Checkout\Cart\SubscriptionLineItem;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly EntityRepository $subscriptionRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository,
        private readonly AbstractStockStorage $stockStorage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    /**
     * Alters the stock for rentable products.
     * Stock on products that are rentable and the order is a renewal will not get decreased.
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        try {
            foreach ($event->getWriteResults() as $writeResult) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
                $criteria->addAssociation('lineItems');
                $criteria->addAssociation('customFields');
                $currentOrder = $this->orderRepository->search($criteria, $event->getContext())->first();

                $this->tagOrder($currentOrder, $event->getContext());
                $this->cancelAndTagSubscriptionOnResidualPurchase($currentOrder, $event->getContext());

                // check if the ordered products are rentable and out of stock, then borrow stock from the borrow product variant
                $this->borrowStock($currentOrder, $event->getContext());
            }
        } catch (\Exception $e) {
            $this->logger->error('ABORTED OrderSubscriber:', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Cancels and tags the subscription for POS if the order is a residual purchase.
     *
     * @throws \Exception
     */
    private function cancelAndTagSubscriptionOnResidualPurchase(OrderEntity $order, Context $context)
    {
        $residualOrderLineItems = $this->getResidualOrderLineItems($order);
        if (count($residualOrderLineItems) < 1) {
            return;
        }

        // if the order is a residual purchase we have to cancel the subscription
        // but as the order is not a mollie clone we have to obtain the subscriptionId set in the payload
        // on any residualLineItems within the new order.
        $subscriptionId = array_reduce($residualOrderLineItems, function ($carry, OrderLineItemEntity $item) {
            $payload = $item->getPayload();

            return $payload['mollieSubscriptionId'] ?? $carry;
        });

        $subscriptionEntity = $this->subscriptionManager->findSubscription($subscriptionId, $context);
        if ($this->subscriptionManager->isCancelable($subscriptionEntity, $context)) {
            // cancel the subscription through mollie API
            $this->subscriptionManager->cancelSubscription($subscriptionId, $context);

            // mark the subscription as initiated for cancellation for POS
            $subscriptionEntity = $this->subscriptionRepository->search(new Criteria([$subscriptionId]), $context)->first();
            $subscriptionMetaData = $subscriptionEntity->getMetadata()->toArray() ?? [];

            $subscriptionMetaData['residually_purchased_at'] ??= (new \DateTime())->format('Y-m-d H:i:s T');
            $subscriptionMetaData['status'] ??= 'residual_purchase';

            $this->subscriptionRepository->update([
                [
                    'id' => $subscriptionEntity->getId(),
                    'metaData' => $subscriptionMetaData,
                ],
            ], $context);
        }
    }

    /**
     * Tags the order by type for POS.
     */
    private function tagOrder(OrderEntity $order, Context $context)
    {
        $customFields = $order->getCustomFields();
        $hasMolliePayments = $customFields['mollie_payments'] ?? false;

        // ensure we only process if mollie_payments are set
        if (!$hasMolliePayments) {
            return;
        }

        $shouldUpdate = false;

        if (count($this->getResidualOrderLineItems($order)) > 0) {
            $shouldUpdate = !isset($customFields['os_subscriptions']['order_type']);
            $customFields['os_subscriptions']['order_type'] = 'residual';

            // obtain the subscriptionId from any residualLineItems within the new order
            // which are set within the AccountController. Otherwise we can't reference the subscription.
            $subscriptionId = array_reduce(
                $this->getResidualOrderLineItems($order),
                fn ($carry, OrderLineItemEntity $item) => $carry ?: ($item->getPayload()['mollieSubscriptionId'] ?? null),
                null
            );
            $customFields['os_subscriptions']['subscription_id'] = $subscriptionId;
        } elseif (count($this->getRentOrderLineItems($order)) > 0) {
            $shouldUpdate = !isset($customFields['os_subscriptions']['order_type']);

            // here we can access safely the subscriptionId as a renewals is always copied
            // from the initial order.
            $subscriptionId = $customFields['mollie_payments']['swSubscriptionId'];

            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $existingOrderCount = count($this->orderRepository->search($criteria, $context));
            $isRenewal = $existingOrderCount > 1;

            $customFields['os_subscriptions']['order_type'] = $isRenewal ? 'renewal' : 'initial';
            $customFields['os_subscriptions']['subscription_id'] = $subscriptionId;
        }

        if ($shouldUpdate) {
            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ],
            ], $context);
        }
    }

    /**
     * Get residual OrderLineItems.
     */
    private function getResidualOrderLineItems(OrderEntity $order): array
    {
        return array_filter(
            $order->getLineItems()->getElements(),
            function (OrderLineItemEntity $item) {
                return SubscriptionLineItem::PRODUCT_RESIDUAL_TYPE === $item->getType();
            }
        );
    }

    /**
     * Get rent OrderLineItems.
     */
    private function getRentOrderLineItems(OrderEntity $order): array
    {
        return array_filter(
            $order->getLineItems()->getElements(),
            function (OrderLineItemEntity $item) {
                return array_reduce(
                    $item->getPayload()['options'] ?? [],
                    fn ($carry, $option) => $carry && 'Mieten' === $option['option'],
                    true
                );
            }
        );
    }

    private function borrowStock(OrderEntity $order, $context)
    {
        try {
            $this->logger->info('STARTED Borrowing stock: Borrowing stock was called', [
                'orderId' => $order->getId(),
            ]);

            $orderTransactions = $order->getTransactions();

            if (empty($orderTransactions)) {
                $this->logger->info('ABORTED Borrowing stock: No transactions found in order', [
                    'orderId' => $order->getId(),
                ]);

                return;
            }

            $orderTransactionState = $order->getTransactions()->first()->getStateMachineState()->getTechnicalName();

            if ('paid' !== $orderTransactionState) {
                $this->logger->info('ABORTED Borrowing stock: Order not paid yet', [
                    'orderId' => $order->getId(),
                    'orderTransactionState' => $orderTransactionState,
                ]);

                return;
            }

            $orderCustomFields = $order->getCustomFields();

            if (isset($orderCustomFields['os_subscriptions']['stock_borrowed'])) {
                $this->logger->info('ABORTED Borrowing stock 0: Stock was already borrowed', [
                    'orderId' => $order->getId(),
                    'orderCustomFields' => $orderCustomFields,
                ]);

                return;
            }

            if (isset($orderCustomFields['os_subscriptions']['order_type']) && 'initial' == $orderCustomFields['os_subscriptions']['order_type']) {
                $lineItems = $order->getLineItems();

                if (!$lineItems) {
                    $this->logger->info('ABORTED Borrowing stock 1: No line items found in order', [
                        'orderId' => $order->getId(),
                        'orderCustomFields' => $orderCustomFields,
                    ]);

                    return;
                }

                foreach ($lineItems as $lineItem) {
                    $lineItemPayload = $lineItem->getPayload();

                    if (!isset($lineItemPayload['stock'])) {
                        $this->logger->info('ABORTED Borrowing stock 2: No line items payload found in order', [
                            'orderId' => $order->getId(),
                            'orderCustomFields' => $orderCustomFields,
                        ]);

                        return;
                    }

                    $lineItemStock = $lineItemPayload['stock'];

                    if ($lineItemStock < 1) {
                        $this->logger->info('CONTINUED Borrowing stock: Line items stock found in order was below 1', [
                            'orderId' => $order->getId(),
                            'orderCustomFields' => $orderCustomFields,
                        ]);

                        $criteria = new Criteria();
                        $criteria->addFilter(new EqualsFilter('id', $lineItem->getProductId()));
                        $product = $this->productRepository->search($criteria, $context)->first();

                        if ($product) {
                            // Perform actions with the product

                            $productCustomFields = $product->getCustomFields();

                            if (!$productCustomFields or !isset($productCustomFields['mollie_payments_product_subscription_enabled'])) {
                                $this->logger->info('ABORTED Borrowing stock 3: No product custom fields found', [
                                    'orderId' => $order->getId(),
                                    'orderCustomFields' => $orderCustomFields,
                                ]);

                                return;
                            }
                            // Check if the product is a subscription product
                            $isSubscriptionProduct = true === $productCustomFields['mollie_payments_product_subscription_enabled'] ? true : false;

                            if (!$isSubscriptionProduct) {
                                $this->logger->info('ABORTED Borrowing stock 4: Product is not subscription type', [
                                    'orderId' => $order->getId(),
                                    'orderCustomFields' => $orderCustomFields,
                                ]);

                                return;
                            }

                            // Check if the product has a borrow product variant
                            if (!isset($productCustomFields['mollie_payments_product_parent_buy_variant'])) {
                                $this->logger->info('ABORTED Borrowing stock 5: Product has no borrowing variant', [
                                    'orderId' => $order->getId(),
                                    'orderCustomFields' => $orderCustomFields,
                                ]);

                                return;
                            }

                            $borrowProductVariantId = $productCustomFields['mollie_payments_product_parent_buy_variant'];

                            if (!$borrowProductVariantId) {
                                return;
                            }

                            // search for the borrow product variant
                            // $context = Context::createDefaultContext();
                            $criteria = new Criteria([$borrowProductVariantId]);
                            $criteria->addAssociation('customFields');

                            $productBorrowVariant = $this->productRepository->search($criteria, $context)->first();

                            // Check if the borrow product variant is available on stock
                            if (!$productBorrowVariant and $productBorrowVariant->getAvailableStock() < 1) {
                                $this->logger->info('ABORTED Borrowing stock 6: Product borrowing variant doesnt exist or has not enough stock to borrow', [
                                    'orderId' => $order->getId(),
                                    'orderCustomFields' => $orderCustomFields,
                                ]);

                                return false;
                            }

                            // now swap the product stock like this; substract sthe stock from productBorrowVariant by the line item quantity and add it to the product

                            // $productBorrowVariant->setAvailableStock($productBorrowVariant->getAvailableStock() - $lineItem->getQuantity());
                            // $productBorrowVariant->setStock($productBorrowVariant->getStock() - $lineItem->getQuantity());
                            // $product->setAvailableStock($product->getAvailableStock() + $lineItem->getQuantity());
                            // $product->setStock($product->getStock() + $lineItem->getQuantity());

                            $this->logger->info('PROCESSING Borrowing stock: Reducing stock from borrow product variant', [
                                'lineItemId' => $lineItem->getId(),
                                'productId' => $lineItem->getProductId(),
                                'quantity' => $lineItem->getQuantity(),
                                'productBorrowVariant' => $productBorrowVariant->getId(),
                                'newStock' => $productBorrowVariant->getStock() - $lineItem->getQuantity(),
                                'oldStock' => $productBorrowVariant->getStock(),
                            ]);

                            $this->logger->info('PROCCESING Borrowing stock: Adding stock to subscription variant', [
                                'lineItemId' => $lineItem->getId(),
                                'productId' => $lineItem->getProductId(),
                                'quantity' => $lineItem->getQuantity(),
                                'product' => $product->getId(),
                                'newStock' => $product->getStock() + $lineItem->getQuantity(),
                                'oldStock' => $product->getStock(),
                            ]);

                            // Update the product stock
                            $this->productRepository->update([
                                [
                                    'id' => $productBorrowVariant->getId(),
                                    'availableStock' => $productBorrowVariant->getAvailableStock(),
                                    'stock' => $productBorrowVariant->getStock(),
                                ],
                                [
                                    'id' => $product->getId(),
                                    'availableStock' => $product->getAvailableStock(),
                                    'stock' => $product->getStock(),
                                ],
                            ], $context);

                            // borrow from the borrow product variant and add to the product
                            // $this->stockStorage->alter(
                            //     [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productBorrowVariant->getStock() - $lineItem->getQuantity(), $productBorrowVariant->getStock())],
                            //     $context
                            // );

                            $this->logger->info('PROCESSED Borrowing stock: Borrowed stock from borrow product variant');

                            // Update the product stock
                            // $this->stockStorage->alter(
                            //     [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $product->getStock() + $lineItem->getQuantity(), $product->getStock())],
                            //     $context
                            // );

                            $this->logger->info('PROCESSED Borrowing stock: Added stock to subscription product variant');

                            $orderCustomFields['os_subscriptions']['stock_borrowed'] = true;
                            $orderCustomFields['os_subscriptions']['stock_borrowed_from'] = $productBorrowVariant->getId();
                            $orderCustomFields['os_subscriptions']['stock_borrowed_to'] = $product->getId();

                            $this->orderRepository->update([
                                [
                                    'id' => $order->getId(),
                                    'customFields' => $orderCustomFields,
                                ],
                            ], $context);

                            $this->logger->info('PROCESSED Borrowing stock: Added borrowed stock data to order custom fields');
                        }
                    } else {
                        $this->logger->info('ABORTED Borrowing stock 7: Line items stock was above 1', [
                            'orderId' => $order->getId(),
                            'orderCustomFields' => $orderCustomFields,
                        ]);

                        return;
                    }
                }
            }

            $this->logger->info('ABORTED Borrowing stock 8: Order is not of subscription initial type', [
                'orderId' => $order,
                'orderCustomFields' => $orderCustomFields,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->logger->error('ABORTED Borrowing stock 9:', [
                'orderId' => $order,
                'orderCustomFields' => $order->getCustomFields(),
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
