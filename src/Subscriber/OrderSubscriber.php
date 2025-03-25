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
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Tag\TagCollection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly EntityRepository $subscriptionRepository,
        private readonly LoggerInterface $logger,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $tagRepository
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
            $this->logger->error('ERROR: OrderSubscriber:', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
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
            $this->logger->info('RESIDUAL item not found in order. Skipping', [
                'order' => $order->getId(),
            ]);

            return;
        }

        // if the order is a residual purchase we have to cancel the subscription
        // but as the order is not a mollie clone we have to obtain the subscriptionId set in the payload
        // on any residualLineItems within the new order.
        $subscriptionId = array_reduce($residualOrderLineItems, function ($carry, OrderLineItemEntity $item) {
            $payload = $item->getPayload();

            return $payload['mollieSubscriptionId'] ?? $carry;
        });

        if (!$subscriptionId) {
            $this->logger->error('ERROR: OrderSubscriber: No subscriptionId found on residual purchase order', [
                'order' => $order->getId(),
            ]);
        }

        $this->logger->info('RESIDUAL CANCELLATION STARTED in OrderSubscriber', [
            'subscriptionId' => $subscriptionId,
        ]);

        $subscriptionEntity = $this->subscriptionManager->findSubscription($subscriptionId, $context);

        if (!$subscriptionEntity) {
            $this->logger->error('ERROR: OrderSubscriber: Subscription not found', [
                'orderId' => $order->getId(),
                'subscriptionId' => $subscriptionId,
            ]);

            return;
        }

        $this->logger->info('RESIDUAL CANCELLATION STARTED WITH DATA: OrderSubscriber: subscriptionId', [
            'subscriptionEntity' => $subscriptionEntity,
            'subscriptionId' => $subscriptionId,
        ]);

        try {
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

                $this->logger->info('Subscription canceled and tagged as residual purchase', [
                    'orderId' => $order->getId(),
                    'subscriptionId' => $subscriptionId,
                ]);
            } else {
                $this->logger->error('ERROR: OrderSubscriber: Subscription is not cancelable', [
                    'orderId' => $order->getId(),
                    'subscriptionId' => $subscriptionId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('ERROR: OrderSubscriber: Failed to cancel subscription', [
                'orderId' => $order->getId(),
                'subscriptionId' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
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
        $shouldAddShopwareTag = false;

        if (count($this->getResidualOrderLineItems($order)) > 0) {
            $shouldUpdate = !isset($customFields['os_subscriptions']['order_type']);
            if ($shouldUpdate) {
                $customFields['os_subscriptions']['order_type'] = 'residual';
                // obtain the subscriptionId from any residualLineItems within the new order
                // which are set within the AccountController. Otherwise we can't reference the subscription.
                $subscriptionId = array_reduce(
                    $this->getResidualOrderLineItems($order),
                    fn ($carry, OrderLineItemEntity $item) => $carry ?: ($item->getPayload()['mollieSubscriptionId'] ?? null),
                    null
                );
                $customFields['os_subscriptions']['subscription_id'] = $subscriptionId;
                $shouldAddShopwareTag = true;
            }
        } elseif (count($this->getRentOrderLineItems($order)) > 0) {
            $shouldUpdate = !isset($customFields['os_subscriptions']['order_type']);

            if ($shouldUpdate) {
                // here we can access safely the subscriptionId as a renewals is always copied
                // from the initial order.
                $subscriptionId = $customFields['mollie_payments']['swSubscriptionId'];

                $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
                $existingOrderCount = count($this->orderRepository->search($criteria, $context));
                $isRenewal = $existingOrderCount > 1;

                $customFields['os_subscriptions']['order_type'] = $isRenewal ? 'renewal' : 'initial';
                $customFields['os_subscriptions']['subscription_id'] = $subscriptionId;
                if ($isRenewal) {
                    $shouldAddShopwareTag = true;
                }
            }
        }

        if ($shouldUpdate) {
            // prepare data for update

            $this->logger->info('Order subscriber order should be updated', [
                'orderId' => $order->getId(),
                'subscriptionId' => $subscriptionId,
            ]);

            $updateData = [
                'id' => $order->getId(),
                'customFields' => $customFields,
            ];

            // add shopware tag to the order
            if ($shouldAddShopwareTag) {
                $this->logger->info('Order subscriber order should be tagged', [
                    'orderId' => $order->getId(),
                    'subscriptionId' => $subscriptionId,
                ]);
                // Get the tag ID from the system config
                $tagId = $this->systemConfigService->get('OsSubscriptions.config.subscriptionRenewalBuyoutTag');

                if (!$tagId) {
                    $this->logger->error('ERROR: OrderSubscriber: No tag selected in system config', [
                        'order' => $order->getId(),
                    ]);
                } else {
                    // Search for the tag
                    $tagCriteria = new Criteria();
                    $tagCriteria->addFilter(new EqualsFilter('id', $tagId));

                    /** @var TagEntity $tag */
                    $tag = $this->tagRepository->search($tagCriteria, Context::createDefaultContext())->first();

                    if ($tag) {
                        $tagCollection = new TagCollection();
                        $tagCollection->add($tag);

                        // Add the tag to the order's tags collection
                        $order->setTags($tagCollection);

                        // append the tag to the order update data
                        $this->logger->info('Order subscriber TAG found and added to updateData', [
                            'orderId' => $order->getId(),
                            'tag' => $tag,
                        ]);

                        $updateData['tags'] = [
                            ['id' => $tag->getId()],
                        ];
                    } else {
                        $this->logger->info('Order subscriber TAG not found', [
                            'orderId' => $order->getId(),
                        ]);
                    }
                }
            } else {
                $this->logger->info('Order subscriber TAG should not be applied', [
                    'orderId' => $order->getId(),
                ]);
            }

            try {
                $this->logger->info('TAG OrderSubscriber UPDATE starting', [
                    'order' => $order->getId(),
                    'updateData' => $updateData,
                ]);

                $this->orderRepository->update([$updateData], $context);
            } catch (\Exception $e) {
                $this->logger->error('ERROR: OrderSubscriber: Failed to update order', [
                    'order' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
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
}
