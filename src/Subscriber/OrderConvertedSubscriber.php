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
    /**
     * @param AbstractStockStorage $stockStorage
     * @param EntityRepository $orderRepository
     * @param EntityRepository $productRepository
     * @param EntityRepository $subscriptionHistoryRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AbstractStockStorage $stockStorage,
        private readonly EntityRepository     $orderRepository,
        private readonly EntityRepository     $productRepository,
        private readonly EntityRepository     $subscriptionHistoryRepository,
        private readonly LoggerInterface      $logger
    )
    {
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderConvertedEvent::class => 'onOrderConverted',
        ];
    }


    /**
     * @param OrderConvertedEvent $event
     * @return void
     */
    public function onOrderConverted(OrderConvertedEvent $event): void
    {
        try {
            if ($this->shouldProcess($event)) {
                $this->refillEmptyProductStockByOrderQuantity($event);
            }

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * The OrderConvertedEvent is actually called several times but we have to ensure
     * that the condition is only applied once within the given context.
     * Which in fact is the renewal of the subscriptions.
     * @param OrderConvertedEvent $event
     * @return bool
     */
    private function shouldProcess(OrderConvertedEvent $event): bool
    {
        $order = $this->getOrderEntity($event);
        $mollieSubscriptionId = $order->getCustomFields()["mollie_payments"]["swSubscriptionId"] ?? false;

        # only process mollie subscriptions
        if (!$mollieSubscriptionId) {
            return false;
        }

        # prevent repeated execution
        $initialOrderWasCloned = $order->getCustomFields()["mollie_payments"]["order_id"] ?? false;
        if ($initialOrderWasCloned) {
            return !$this->subscriptionAlreadyProcessed($mollieSubscriptionId, $event);
        }

        return false;
    }

    /**
     * Increases the stock by the product quantity so mollie can process safely
     * @param OrderConvertedEvent $event
     * @return void
     */
    private function refillEmptyProductStockByOrderQuantity(OrderConvertedEvent $event)
    {
        $order = $event->getOrder();
        $context = $event->getContext();

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return;
            }

            $referenceProduct = $this->productRepository->search(new Criteria([$lineItem->getProductId()]), $context)->first();
            $realProductStock = $referenceProduct->getStock();

            $quantity = $lineItem->getQuantity();
            $productStock = $lineItem->getPayload()["stock"];

            if ($realProductStock < $quantity) {
                # persist the stock storage, so no changes will take effect
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                    $context
                );

                # mark the order that the stock was increased for MollieSubscriptionHistorySubscriber
                $customFields = $order->getCustomFields();
                $customFields["os_subscriptions"]["stock_increased"] = true;
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ]
                ], $event->getContext());
            }
        }
    }

    /**
     * Retrieve the order manually to have all customFields present
     * @param OrderConvertedEvent $event
     * @return OrderEntity
     */
    private function getOrderEntity(OrderConvertedEvent $event): OrderEntity
    {
        $mollieSubscriptionId = $event->getOrder()->getCustomFields()["mollie_payments"]["swSubscriptionId"];

        $criteria = new Criteria();
        $criteria->addAssociation('customFields');
        $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieSubscriptionId));
        return $this->orderRepository->search($criteria, $event->getContext())->first();
    }

    /**
     * Helper for several edge cases
     * ! includes time based logic -> 60 seconds
     * @param string $mollieSubscriptionId
     * @param OrderConvertedEvent $event
     * @return bool
     */
    private function subscriptionAlreadyProcessed(string $mollieSubscriptionId, OrderConvertedEvent $event): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('subscriptionId', $mollieSubscriptionId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);
        $subscriptions = $this->subscriptionHistoryRepository->search($criteria, $event->getContext());

        if (count($subscriptions) < 1) {
            return false;
        }

        $latestHistory = $subscriptions->first();

        $now = new \DateTime();
        $created = $latestHistory->getCreatedAt();
        $diffInSeconds = $now->getTimestamp() - $created->getTimestamp();

        # note: might needs adjustement based on how mollie handles webhook retries
        $thresholdSeconds = 30;

        # hope and pray
        return $diffInSeconds < $thresholdSeconds;
    }

}