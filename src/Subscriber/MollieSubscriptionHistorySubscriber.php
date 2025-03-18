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
 * Used to decrease the product stock for subscription renewals
 */
class MollieSubscriptionHistorySubscriber implements EventSubscriberInterface
{

    /**
     * @param EntityRepository $orderRepository
     * @param AbstractStockStorage $stockStorage
     * @param EntityRepository $productRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityRepository     $orderRepository,
        private readonly AbstractStockStorage $stockStorage,
        private readonly EntityRepository     $productRepository,
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
            'mollie_subscription_history.written' => 'onSubscriptionHistoryWritten',
        ];
    }


    /**
     * @param EntityWrittenEvent $event
     * @return void
     */
    public function onSubscriptionHistoryWritten(EntityWrittenEvent $event): void
    {
        try {
            $this->handleStockReductionForRenewals($event);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * @param EntityWrittenEvent $entityWrittenEvent
     * @return void
     */
    private function handleStockReductionForRenewals(EntityWrittenEvent $entityWrittenEvent)
    {
        $context = $entityWrittenEvent->getContext();

        foreach ($entityWrittenEvent->getWriteResults() as $writeResult) {
            if ($writeResult->getPayload()['comment'] !== 'renewed') {
                continue;
            }

            $subscriptionId = $writeResult->getPayload()["subscriptionId"];
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('lineItems');
            $order = $this->orderRepository->search($criteria, $context)->first();

            # we have to pay attention that if we have artificially increased the stock
            # in our OrderConvertedSubscriber, we have to skip this step - as it's already done.
            $customFields = $order->getCustomFields();
            $stockIncreasedBefore = $customFields["os_subscriptions"]["stock_increased"] ?? false;
            if ($stockIncreasedBefore) {
                $customFields["os_subscriptions"]["stock_increased"] = false;
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ]
                ], $context);

                # do not increase/persist the stock
                continue;
            }

            # we can safely increase/persist the stock
            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                    continue;
                }

                $referenceProduct = $this->productRepository->search(new Criteria([$lineItem->getProductId()]), $context)->first();
                $productStock = $referenceProduct->getStock();
                $quantity = $lineItem->getQuantity();

                # persist the stock storage, so no changes will take effect
                $this->stockStorage->alter(
                    [new StockAlteration($lineItem->getId(), $lineItem->getProductId(), $productStock + $quantity, $productStock)],
                    $context
                );
            }
        }
    }
}