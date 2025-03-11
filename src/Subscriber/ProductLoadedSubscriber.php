<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductLoadedSubscriber implements EventSubscriberInterface
{
    //  * @param EntityRepository $productRepository
    //  * @param AbstractStockStorage $stockStorage
    //  * @param LoggerInterface $logger
    public $purchaseableWhenOutOfStockProduct = false;

    public function __construct(
        private readonly EntityRepository $productRepository,
        // private readonly AbstractStockStorage $stockStorage,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        // Get the product from the event
        $product = $event->getPage()->getProduct();

        if (
            null !== $product
        ) {
            // Get the custom fields of the product
            $customFields = $product->getCustomFields();

            if (!$customFields or !isset($customFields['mollie_payments_product_subscription_enabled'])) {
                return;
            }

            // Check if the product is a subscription product
            $isSubscriptionProduct = true === $customFields['mollie_payments_product_subscription_enabled'] ? true : false;

            if (!$isSubscriptionProduct) {
                return;
            }

            // Check if the product stock is available
            $availableStock = $product->getAvailableStock();

            if ($availableStock > 0) {
                return;
            }

            // Check if the product has a borrow product variant
            // Check if the product has a borrow product variant
            if (!isset($customFields['mollie_payments_product_parent_buy_variant'])) {
                return;
            }

            $borrowProductVariantId = $customFields['mollie_payments_product_parent_buy_variant'];

            if (!$borrowProductVariantId) {
                return;
            }

            // search for the borrow product variant
            $context = $event->getContext();
            $criteria = new Criteria([$borrowProductVariantId]);
            $criteria->addAssociation('customFields');

            $productVariant = $this->productRepository->search($criteria, $context)->first();

            // Check if the borrow product variant is available on stock
            if ($productVariant and $productVariant->getAvailableStock() > 0) {
                // make the product purchaseable
                $this->purchaseableWhenOutOfStockProduct = true;
                $customFields['acris_stock_notification_email_notification_inactive'] = true;
                $product->setCustomFields($customFields);
                $this->productRepository->update([
                    [
                        'id' => $product->getId(),
                        'customFields' => $customFields,
                    ],
                ], $context);
            }
        }
    }
}
