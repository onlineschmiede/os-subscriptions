<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConvertedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\Context;
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
            $mollieSubscriptionId = $event->getOrder()->getCustomFields()["mollie_payments"]["swSubscriptionId"] ?? false;
            if(!$mollieSubscriptionId) {
                return;
            }

            $initialMollieOrder = $this->getInitialMollieOrder($mollieSubscriptionId, $event->getContext());
            if($this->subscriptionAlreadyProcessed($initialMollieOrder, $event->getContext())) {
                return;
            }

            $this->refillEmptyProductStockByOrderQuantity($initialMollieOrder, $event->getContext());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Increases the stock by the product quantity so mollie can process safely.
     * This is mainly due to the fact that mollie will remove all orderLineItems
     * if the stock is below the quantity of the orderLineItem. So we have to
     * artificially bump it, mark the order and reduce it within another subscriber (MollieSubscriptionHistorySubscriber)
     * @param OrderEntity $order
     * @param Context $context
     * @return void
     */
    private function refillEmptyProductStockByOrderQuantity(OrderEntity $order, Context $context): void
    {
        $stockModified = false;

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return;
            }

            $referenceProduct = $this->productRepository->search(new Criteria([$lineItem->getProductId()]), $context)->first();
            $productStock = $referenceProduct->getStock();
            $quantity = $lineItem->getQuantity();

            if ($quantity <= $productStock) {
                # here we can safely delegate the stock bump for renewals to the MollieSubscriptionHistorySubscriber
                # as we only need to handle edge cases
                continue;
            }

            # if the stock is zero, we have to increase it to the quantity of the orderLineItem
            # which again get reduced in the MollieSubscriptionHistorySubscriber subscriber.
            if ($productStock === 0) {
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                    $context
                );
            }

            # if the stock is below zero, we have to set it to zero and increase it by the quantity of the orderLineItem
            if($productStock < 0) {
                $this->logger->error("Product stock is below zero. Product: {$referenceProduct->getName()} Stock: {$productStock} Quantity: {$quantity} OrderId: {$order->getId()}");
                $this->logger->error("Setting stock to from negative to 0 + {$quantity} for product {$referenceProduct->getName()} so mollie can process.");
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), ($productStock * -1), $quantity * -1)],
                    $context
                );
            }

            $stockModified = true;
        }

        if($stockModified) {
            $customFields = $order->getCustomFields();
            $customFields["os_subscriptions"]["stock_increased"] = true;
            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ]
            ], $context);
        }
    }

    /**
     * Retrieve the order manually to have all customFields present.
     * This is due the fact that the order obtained within the event does
     * get casted through a mollie based struct and omitting custom sets.
     * @param string $mollieSubscriptionId
     * @param Context $context
     * @return OrderEntity
     */
    private function getInitialMollieOrder(string $mollieSubscriptionId, Context $context): OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('customFields');
        $criteria->addAssociation('lineItems');
        $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieSubscriptionId));

        return $this->orderRepository->search($criteria,$context)->first();
    }


    /**
     * As this subscriber is called sever times, we have to ensure that
     * we can operate safely ONCE within repeated executions.
     * ! includes time based logic -> 30 seconds
     * @param OrderEntity $order
     * @param Context $context
     * @return bool
     */
    private function subscriptionAlreadyProcessed(OrderEntity $order, Context $context): bool
    {
        $mollieSubscriptionId = $order->getCustomFields()["mollie_payments"]["swSubscriptionId"];

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('subscriptionId', $mollieSubscriptionId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);
        $subscriptions = $this->subscriptionHistoryRepository->search($criteria, $context);

        if (count($subscriptions) < 1) {
            return false;
        }

        $latestHistory = $subscriptions->first();

        # initial purchases for subscriptions should not be processed / stock adjusted
        if($latestHistory->get('comment') === "created") {
            return true;
        }

        $now = new \DateTime();
        $created = $latestHistory->getCreatedAt();
        $diffInSeconds = $now->getTimestamp() - $created->getTimestamp();

        # note: might needs adjustement based on how mollie handles webhook retries
        $thresholdSeconds = 30;

        # hope and pray
        return $diffInSeconds < $thresholdSeconds;
    }
}