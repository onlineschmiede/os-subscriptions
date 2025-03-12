<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderConvertedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderConvertedSubscriber implements EventSubscriberInterface
{
    /**
     * @param AbstractStockStorage $stockStorage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AbstractStockStorage $stockStorage,
        private readonly LoggerInterface      $logger
    )
    { }

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
            $order = $event->getOrder();
            $context = $event->getContext();

            if ($this->shouldProcess($order)) {
                $this->increaseOrderProductStocks($order, $context);
            }

        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * On rent renewals we actually do not decrease the product stock, as nothing is sent.
     * But mollie will always reduce the stock accordingly, so we have to counterfeit by
     * increasing the product stock by the purchased quantity.
     * @param OrderEntity $orderEntity
     * @param Context $context
     * @return void
     */
    private function increaseOrderProductStocks(OrderEntity $orderEntity, Context $context): void
    {
        foreach ($orderEntity->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return;
            }

            $quantity = $lineItem->getQuantity();
            $productStock = $lineItem->getPayload()["stock"];

            # persist the stock storage, so no changes will take effect
            $this->stockStorage->alter(
                [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                $context
            );
        }
    }

    /**
     * The OrderConvertedEvent is actually called several times but we have to ensure
     * that the condition is only applied once within the given context.
     * Which in fact is the renewal of the subscriptions.
     * @param OrderEntity $orderEntity
     * @return bool
     */
    private function shouldProcess(OrderEntity $orderEntity): bool
    {
        $needStockAdjustment = false;
        $mollieSubscriptionId = $orderEntity->getCustomFields()["mollie_payments"]["swSubscriptionId"] ?? false;

        if (!$mollieSubscriptionId) {
            return false;
        }

        foreach ($orderEntity->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            # mollie is copying on renewal the initial order
            # the initial order is the only one who has shipping costs set
            # as this event gets triggered multiple times, its the only reliable
            # marker to know if this is the first iteration call
            $shippingCosts = $orderEntity->getShippingCosts();
            if($shippingCosts->getTotalPrice() > 0) {
                $needStockAdjustment = true;
            }
        }

        return $needStockAdjustment;
    }
}