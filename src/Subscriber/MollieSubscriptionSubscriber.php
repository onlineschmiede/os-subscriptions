<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MollieSubscriptionSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository $subscriptionRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
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
            'mollie_subscription.written' => 'onSubscriptionWritten',
        ];
    }


    /**
     * @param EntityWrittenEvent $event
     * @return void
     */
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

                if(!isset($subscriptionMetaData['active'])) {
                    $subscriptionMetaData['active'] = true;
                    $this->subscriptionRepository->update([
                        [
                            'id' => $subscriptionId,
                            'metadata' => $subscriptionMetaData,
                        ]
                    ], $event->getContext());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}