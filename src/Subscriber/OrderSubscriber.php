<?php

namespace OsSubscriptions\Subscriber;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository $orderRepository
     * @param AbstractStockStorage $stockStorage
     * @param SubscriptionManager $subscriptionManager
     * @param EntityRepository $subscriptionRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly AbstractStockStorage $stockStorage,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly EntityRepository $subscriptionRepository
    )
    { }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    /**
     * Alters the stock for rentable products.
     * Stock on products that are rentable and the order is a renewal will not get decreased.
     * @param EntityWrittenEvent $event
     * @return void
     * @throws \Exception
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        foreach ($event->getWriteResults() as $writeResult) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
            $criteria->addAssociation('lineItems');
            $currentOrder = $this->orderRepository->search($criteria, $event->getContext());

            # get all rentable product line items from the order
            $currentOrderRentLineItems = array_filter(
                $currentOrder->first()->getLineItems()->getElements(),
                function (OrderLineItemEntity $item) {
                    if (isset($item->getPayload()['options'])) {
                        foreach ($item->getPayload()['options'] as $option) {
                            if (isset($option['option']) && $option['option'] === 'Mieten') {
                                return true;
                            }
                        }
                    }
                    return false;
                }
            );

            # get all residual product line items from the order
            $currentOrderResidualLineItems = array_filter(
                $currentOrder->first()->getLineItems()->getElements(),
                function (OrderLineItemEntity $item) {
                    if (isset($item->getPayload()['residualPurchase']) &&
                        $item->getPayload()['residualPurchase'] === true &&
                        $item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                        return true;
                    }
                    return false;
                }
            );

            $lineItemsWithoutStockReduction = [];

            # skip if we don't have any custom fields ready
            if ($writeResult->getProperty('customFields') &&
                isset($writeResult->getProperty('customFields')['mollie_payments']))
            {
                $mollieData = $writeResult->getProperty('customFields')['mollie_payments'];
                $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieData['swSubscriptionId']));
                $subscriptionInterval = count($this->orderRepository->search($criteria, $event->getContext()));

                # skip if the order is not a mollie subscription renewal
                # meaning we will decrease the stock of the products in the order
                # if it is an initial order.
                if ($subscriptionInterval > 1) {
                    $lineItemsWithoutStockReduction = array_merge($lineItemsWithoutStockReduction, $currentOrderRentLineItems);
                }
            }

            if (count($currentOrderResidualLineItems) > 0) {
                $lineItemsWithoutStockReduction = array_merge($lineItemsWithoutStockReduction, $currentOrderResidualLineItems);

                # if the order is a residual purchase we have to cancel the subscription
                # but as the order is not a mollie clone we have to obtain the subscriptionId set in the payload
                # on any residualLineItems within the new order.
                $subscriptionId = array_reduce($currentOrderResidualLineItems, function ($carry, OrderLineItemEntity $item) {
                    $payload = $item->getPayload();
                    return $payload['mollieSubscriptionId'] ?? $carry;
                });

                $subscriptionEntity = $this->subscriptionManager->findSubscription($subscriptionId, $event->getContext());
                if($this->subscriptionManager->isCancelable($subscriptionEntity, $event->getContext())) {

                    # cancel the subscription through mollie API
                    $this->subscriptionManager->cancelSubscription($subscriptionId, $event->getContext());

                    # mark the subscription as initiated for cancellation for POS
                    $subscriptionEntity = $this->subscriptionRepository->search(new Criteria([$subscriptionId]), $event->getContext())->first();
                    $subscriptionMetaData = $subscriptionEntity->getMetadata()->toArray() ?? [];

                    if(!isset($subscriptionMetaData['residually_purchased_at'])) {
                        $subscriptionMetaData['residually_purchased_at'] = (new \DateTime())->format('Y-m-d H:i:s T');
                        $this->subscriptionRepository->update([
                            [
                                'id' => $subscriptionEntity->getId(),
                                'metaData' => $subscriptionMetaData,
                            ]
                        ], $event->getContext());
                    }
                }
            }

            if (count($lineItemsWithoutStockReduction) > 0) {
                $this->stockStorage->alter(
                    array_map(
                        fn(OrderLineItemEntity $item) => new StockAlteration($item->getId(), $item->getProductId(), $item->getQuantity(), 0),
                        $lineItemsWithoutStockReduction
                    ),
                    $event->getContext()
                );
            }
        }
    }
}