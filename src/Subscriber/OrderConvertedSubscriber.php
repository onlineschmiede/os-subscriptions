<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConvertedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mollie clones orders and relies on product stock.
 * If the stock is zero renewed orders will not have
 * any lineItems except discounts.
 * At any circumstance we have to increase the stock
 * before mollie finishes processing.
 * Stock will be set again to starting value after.
 */
class OrderConvertedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AbstractStockStorage $stockStorage,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $subscriptionHistoryRepository,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderConvertedEvent::class => 'onOrderConverted',
        ];
    }

    public function onOrderConverted(OrderConvertedEvent $event): void
    {
        try {
            if ($this->shouldProcess($event)) {
                $this->logger->info('OrderConvertedSubscriber Called:', [
                    'event' => $event,
                ]);
                $this->refillEmptyProductStockByOrderQuantity($event);
            }
        } catch (\Exception $e) {
            $this->logger->error('ERROR: OrderConvertedSubscriber:', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
        }
    }

    /**
     * The OrderConvertedEvent is actually called several times but we have to ensure
     * that the condition is only applied once within the given context.
     * Which in fact is the renewal of the subscriptions.
     */
    private function shouldProcess(OrderConvertedEvent $event): bool
    {
        $order = $event->getOrder();
        $mollieSubscriptionId = $order->getCustomFields()['mollie_payments']['swSubscriptionId'] ?? false;

        $this->logger->info('OrderConvertedSubscriber shouldProcess 1:', [
            'order' => $order,
            'mollieSubscriptionId' => $mollieSubscriptionId,
        ]);

        // only process mollie subscriptions
        if (!$mollieSubscriptionId) {
            return false;
        }

        $order = $this->getOrderEntity($event, $mollieSubscriptionId);
        // prevent repeated execution
        $initialOrderWasCloned = $order->getCustomFields()['mollie_payments']['order_id'] ?? false;

        $this->logger->info('OrderConvertedSubscriber shouldProcess 2:', [
            'order' => $order,
            'initialOrderWasCloned' => $initialOrderWasCloned,
        ]);

        if ($initialOrderWasCloned) {
            return !$this->subscriptionAlreadyProcessed($mollieSubscriptionId, $event);
        }

        return false;
    }

    /**
     * Increases the stock by the product quantity so mollie can process safely.
     */
    private function refillEmptyProductStockByOrderQuantity(OrderConvertedEvent $event)
    {
        $order = $event->getOrder();
        $context = $event->getContext();

        $this->logger->info('OrderConvertedSubscriber refillEmptyProductStockByOrderQuantity started:', [
            'order' => $order,
        ]);

        foreach ($order->getLineItems() as $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $lineItem->getType()) {
                return;
            }

            $referenceProduct = $this->productRepository->search(new Criteria([$lineItem->getProductId()]), $context)->first();
            $realProductStock = $referenceProduct->getStock();

            $quantity = $lineItem->getQuantity();
            $productStock = $lineItem->getPayload()['stock'];

            if ($realProductStock < $quantity) {
                $this->logger->info('OrderConvertedSubscriber refill execute:', [
                    'quantity' => $quantity,
                    'productStock' => $productStock,
                ]);

                // persist the stock storage, so no changes will take effect
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                    $context
                );

                // mark the order that the stock was increased for MollieSubscriptionHistorySubscriber
                $customFields = $order->getCustomFields();
                $customFields['os_subscriptions']['stock_increased'] = true;
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ],
                ], $event->getContext());

                $this->logger->info('OrderConvertedSubscriber custom data written:', [
                    'orderId' => $order->getId(),
                    'customFields' => $customFields,
                ]);
            }
        }
    }

    /**
     * Retrieve the order manually to have all customFields present.
     *
     * @param mixed $mollieSubscriptionId
     */
    private function getOrderEntity(OrderConvertedEvent $event, $mollieSubscriptionId): OrderEntity
    {
        // $mollieSubscriptionId = $event->getOrder()->getCustomFields()['mollie_payments']['swSubscriptionId'];

        $criteria = new Criteria();
        $criteria->addAssociation('customFields');
        $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieSubscriptionId));

        return $this->orderRepository->search($criteria, $event->getContext())->first();
    }

    /**
     * Helper for several edge cases
     * ! includes time based logic -> 60 seconds.
     */
    private function subscriptionAlreadyProcessed(string $mollieSubscriptionId, OrderConvertedEvent $event): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('subscriptionId', $mollieSubscriptionId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);
        $subscriptions = $this->subscriptionHistoryRepository->search($criteria, $event->getContext());

        $this->logger->info('OrderConvertedSubscriber subscriptionAlreadyProcessed:', [
            'subscriptions' => $subscriptions,
        ]);

        if (count($subscriptions) < 1) {
            return false;
        }

        $latestHistory = $subscriptions->first();

        $now = new \DateTime();
        $created = $latestHistory->getCreatedAt();
        $diffInSeconds = $now->getTimestamp() - $created->getTimestamp();

        // note: might needs adjustement based on how mollie handles webhook retries
        $thresholdSeconds = 30;

        $this->logger->info('OrderConvertedSubscriber subscriptionAlreadyProcessed 2:', [
            'diffInSeconds' => $diffInSeconds,
            'latestHistory' => $latestHistory,
        ]);

        // hope and pray
        return $diffInSeconds < $thresholdSeconds;
    }
}
