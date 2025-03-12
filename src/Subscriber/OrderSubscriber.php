<?php

namespace OsSubscriptions\Subscriber;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use Psr\Log\LoggerInterface;
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
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly AbstractStockStorage $stockStorage,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly EntityRepository $subscriptionRepository,
        private readonly LoggerInterface $logger
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
     */
    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        try {
            foreach ($event->getWriteResults() as $writeResult) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
                $criteria->addAssociation('lineItems');
                $currentOrder = $this->orderRepository->search($criteria, $event->getContext());

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


                if (count($currentOrderResidualLineItems) > 0) {
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

                        $subscriptionMetaData['residually_purchased_at'] ??= (new \DateTime())->format('Y-m-d H:i:s T');
                        $subscriptionMetaData['status'] ??= "residual_purchase";

                        $this->subscriptionRepository->update([
                            [
                                'id' => $subscriptionEntity->getId(),
                                'metaData' => $subscriptionMetaData,
                            ]
                        ], $event->getContext());

                        # persist the stock for the residual products
                        # by increasing the stock by the quantity of the residual products
                        # as the order was already shipped
                        foreach ($currentOrderResidualLineItems as $lineItem) {
                            $quantity = $lineItem->getQuantity();
                            $productStock = $lineItem->getPayload()["stock"];

                            $this->stockStorage->alter(
                                [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                                $event->getContext()
                            );
                        }
                    }
                }
            }
        }
        catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}