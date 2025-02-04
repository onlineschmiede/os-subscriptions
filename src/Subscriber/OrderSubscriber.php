<?php

namespace OsSubscriptions\Subscriber;

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
    private EntityRepository $orderRepository;
    private AbstractStockStorage $stockStorage;

    /**
     * @param EntityRepository $orderRepository
     * @param AbstractStockStorage $stockStorage
     */
    public function __construct(EntityRepository $orderRepository, AbstractStockStorage $stockStorage)
    {
        $this->orderRepository = $orderRepository;
        $this->stockStorage = $stockStorage;
    }

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
       foreach ($event->getWriteResults() as $writeResult) {

           # skip if we don't have any custom fields ready
           if(! $writeResult->getProperty('customFields') ||
               ! $writeResult->getProperty('customFields')['mollie_payments'])
           {
               return;
           }
           
           $mollieData = $writeResult->getProperty('customFields')['mollie_payments'];
           $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $mollieData['swSubscriptionId']));
           $subscriptionInterval = count($this->orderRepository->search($criteria, $event->getContext()));

           # skip if the order is not a mollie subscription renewal
           # meaning we will decrease the stock of the products in the order
           # if it is an initial order.
           if($subscriptionInterval < 2) {
               return;
           }

           $criteria = new Criteria();
           $criteria->addFilter(new EqualsFilter('id', $writeResult->getPrimaryKey()));
           $criteria->addAssociation('lineItems');

           # get all rentable product line items from the order
           $currentOrder = $this->orderRepository->search($criteria, $event->getContext());
           $currentOrderRentLineItems = array_filter(
               $currentOrder->first()->getLineItems()->getElements(),
               function(OrderLineItemEntity $item) {
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

           # alter the stock of rentable products in the order
           # meaning we will not decrease the stock for those products.
           $this->stockStorage->alter(
               array_map(
                   fn(OrderLineItemEntity $item) => new StockAlteration($item->getId(), $item->getProductId(), $item->getQuantity(), 0),
                   $currentOrderRentLineItems
               ),
               $event->getContext()
           );
       }
    }
}