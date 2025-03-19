<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Used to decrease the product stock for subscription renewals.
 */
class MollieSubscriptionHistorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly AbstractStockStorage $stockStorage,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'mollie_subscription_history.written' => 'onSubscriptionHistoryWritten',
        ];
    }

    public function onSubscriptionHistoryWritten(EntityWrittenEvent $event): void
    {
        try {
            $this->handleStockReductionForRenewals($event);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    private function handleStockReductionForRenewals(EntityWrittenEvent $entityWrittenEvent)
    {
        $context = $entityWrittenEvent->getContext();

        foreach ($entityWrittenEvent->getWriteResults() as $writeResult) {
            if ('renewed' !== $writeResult->getPayload()['comment']) {
                continue;
            }

            $subscriptionId = $writeResult->getPayload()['subscriptionId'];
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('lineItems');
            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order) {
                continue;
            }

            // we have to pay attention that if we have artificially increased the stock
            // in our OrderConvertedSubscriber, we have to skip this step - as it's already done.
            $customFields = $order->getCustomFields();

            // REVIEW: should I skip the order if its initial one since it's not a renewal?
            // skip initial and residual orders
            if (!isset($customFields['os_subscriptions']['order_type'])
            or (isset($customFields['os_subscriptions']['order_type']) && 'initial' == $customFields['os_subscriptions']['order_type'])
            or (isset($customFields['os_subscriptions']['order_type']) && 'residual' == $customFields['os_subscriptions']['order_type'])
            ) {
                continue;
            }

            // REVIEW: is this correct?
            $stockIncreasedBefore = $customFields['os_subscriptions']['stock_increased'] ?? false;
            if ($stockIncreasedBefore) {
                $customFields['os_subscriptions']['stock_increased'] = false;
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ],
                ], $context);

                // do not increase/persist the stock
                continue;
            }

            // we can safely increase/persist the stock
            foreach ($order->getLineItems() as $lineItem) {
                if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $lineItem->getType()) {
                    continue;
                }

                $quantity = $lineItem->getQuantity();
                $productStock = $lineItem->getPayload()['stock'];

                // persist the stock storage, so no changes will take effect
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                    $context
                );
            }
        }
    }
}
