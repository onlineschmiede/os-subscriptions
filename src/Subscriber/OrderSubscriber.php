<?php

namespace OsSubscriptions\Subscriber;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use OsSubscriptions\Checkout\Cart\SubscriptionLineItem;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
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
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
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

    private function borrowStock(OrderEntity $order)
    {
        $customFields = $order->getCustomFields();

        if (isset($customFields['os_subscriptions']['order_type']) && 'initial' === $customFields['os_subscriptions']['order_type']) {
            $lineItems = $order->getLineItems();

            foreach ($lineItems as $lineItem) {
                $lineItemPayload = $lineItem->getPayload();
                $lineItemStock = $lineItemPayload['stock'];

                if ($lineItemStock < 1) {
                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsFilter('id', $lineItem->getProductId()));
                    $product = $this->productRepository->search($criteria, Context::createDefaultContext())->first();

                    if ($product) {
                        // Perform actions with the product

                        $customFields = $product->getCustomFields();

                        if (!$customFields or !isset($customFields['mollie_payments_product_subscription_enabled'])) {
                            return;
                        }
                        // Check if the product is a subscription product
                        $isSubscriptionProduct = true === $customFields['mollie_payments_product_subscription_enabled'] ? true : false;

                        if (!$isSubscriptionProduct) {
                            return;
                        }

                        // Check if the product has a borrow product variant
                        if (!isset($customFields['mollie_payments_product_parent_buy_variant'])) {
                            return;
                        }

                        $borrowProductVariantId = $customFields['mollie_payments_product_parent_buy_variant'];

                        if (!$borrowProductVariantId) {
                            return;
                        }

                        // search for the borrow product variant
                        $context = Context::createDefaultContext();
                        $criteria = new Criteria([$borrowProductVariantId]);
                        $criteria->addAssociation('customFields');

                        $productBorrowVariant = $this->productRepository->search($criteria, $context)->first();

                        // Check if the borrow product variant is available on stock
                        if ($productBorrowVariant and $productBorrowVariant->getAvailableStock() > 0) {
                            // make the product purchaseable
                            return true;
                        }

                        // now swap the product stock like this; substract sthe stock from productBorrowVariant by the line item quantity and add it to the product

                        $productBorrowVariant->setAvailableStock($productBorrowVariant->getAvailableStock() - $lineItem->getQuantity());
                        $product->setAvailableStock($product->getAvailableStock() + $lineItem->getQuantity());

                        // Update the product stock
                        $this->productRepository->update([
                            [
                                'id' => $productBorrowVariant->getId(),
                                'availableStock' => $productBorrowVariant->getAvailableStock(),
                            ],
                            [
                                'id' => $product->getId(),
                                'availableStock' => $product->getAvailableStock(),
                            ],
                        ], $context);
                    }
                }
            }
        }
    }
}
