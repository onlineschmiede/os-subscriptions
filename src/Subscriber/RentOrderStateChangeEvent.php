<?php

namespace OsSubscriptions\Subscriber;

use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RentOrderStateChangeEvent implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OrderStateMachineStateChangeEvent::class => 'onOrderStateChange',
        ];
    }

    public function onOrderStateChange(OrderStateMachineStateChangeEvent $event): void
    {
        // Handle the event here
        // For example, you can log the order state change or perform some actions
        $order = $event->getOrder();
        $newState = $event->getNewState();

        // Log the order state change
        // You can use your logger service to log the information
        Example: $this->logger->info('Order state changed', ['orderId' => $order->getId(), 'newState' => $newState]);
    }
}
