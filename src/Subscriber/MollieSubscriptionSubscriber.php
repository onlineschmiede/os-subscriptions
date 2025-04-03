<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Write meta data property "active" on subscription start.
 */
class MollieSubscriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $subscriptionRepository,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'mollie_subscription.written' => 'onSubscriptionWritten',
        ];
    }

    public function onSubscriptionWritten(EntityWrittenEvent $event): void
    {
        try {
            foreach ($event->getWriteResults() as $writeResult) {
                $isNewSubscription = $writeResult->getExistence()->exists();
                if (!$isNewSubscription) {
                    continue;
                }

                $subscriptionId = $writeResult->getPrimaryKey();

                $subscriptionEntity = $this->subscriptionRepository->search(new Criteria([$subscriptionId]), $event->getContext())->first();
                $subscriptionMetaData = $subscriptionEntity->get('metadata') ?? [];

                if (!isset($subscriptionMetaData['active'])) {
                    $subscriptionMetaData['active'] = true;
                    $this->subscriptionRepository->update([
                        [
                            'id' => $subscriptionId,
                            'metadata' => $subscriptionMetaData,
                        ],
                    ], $event->getContext());

                    $this->logger->error('MollieSubscriptionSubscriber meta option active written:', [
                        'subscriptionId' => $subscriptionId,
                        'subscriptionMetaData' => $subscriptionMetaData,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
