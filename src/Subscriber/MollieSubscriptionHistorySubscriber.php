<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Framework\Context;
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
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository
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
            $this->logger->error('ERROR: MollieSubscriptionHistorySubscriber:', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
        }
    }

    private function handleStockReductionForRenewals(EntityWrittenEvent $entityWrittenEvent)
    {
        $context = $entityWrittenEvent->getContext();

        foreach ($entityWrittenEvent->getWriteResults() as $writeResult) {
            if ('renewed' !== $writeResult->getPayload()['comment']) {
                continue;
            }
            // mollie subscription history is written
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

            $this->logger->info('MollieSubscriptionHistorySubscriber renewal started:', [
                'order' => $order->getId(),
                'subscriptionId' => $subscriptionId,
                'customFields' => $customFields,
                'orderType' => $customFields['os_subscriptions']['order_type'] ?? null,
            ]);

            // REVIEW: should I skip the order if its initial one since it's not a renewal?
            // skip initial and residual orders
            // if (!isset($customFields['os_subscriptions']['order_type'])
            // or (isset($customFields['os_subscriptions']['order_type']) && 'initial' == $customFields['os_subscriptions']['order_type'])
            // ) {
            //     continue;
            // }

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

                // this won't work if the product stock in the meantime has changed - sold more items or whatever,
                // so we have to get the product stock from the line item payload
                $referenceProduct = $this->productRepository->search(new Criteria([$lineItem->getProductId()]), $context)->first();
                $referenceProductCustomFields = $referenceProduct->getCustomFields();

                // check if the product is a subscription product
                if (!$referenceProduct or !isset($referenceProductCustomFields['mollie_payments_product_subscription_enabled'])
                or !$referenceProductCustomFields['mollie_payments_product_subscription_enabled']) {
                    continue;
                }
                $productStock = $referenceProduct->getStock();
                $productAvailableStock = $referenceProduct->getAvailableStock();

                $this->logger->info('MollieSubscriptionHistorySubscriber: stock increase IN PROCESS', [
                    'productId' => $lineItem->getProductId(),
                    'quantityBefore' => $productStock + $quantity,
                    'newQuantity' => $productStock,
                ]);

                // persist the stock storage, so no changes will take effect
                // removed just because reducing the request operations, updating both stock at once with product repository update
                // $this->stockStorage->alter(
                //     [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                //     $context
                // );

                // return the available stock value to the product
                $productRepositoryContext = Context::createDefaultContext();

                // Update the product stock
                $this->productRepository->update(
                    [
                        // update the product stock
                        [
                            'id' => $referenceProduct->getId(),
                            'availableStock' => $productAvailableStock + $quantity,
                            'stock' => $productStock + $quantity,
                        ],
                    ],
                    $productRepositoryContext
                );
            }
        }
    }
}
