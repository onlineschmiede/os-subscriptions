<?php

namespace OsSubscriptions\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RentProductLoadedSubscriber implements EventSubscriberInterface
{
    //  * @param EntityRepository $productRepository
    //  * @param LoggerInterface $logger

    public function __construct(
        private readonly EntityRepository $productRepository,
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

            $combinedAvailableStock = 0;

            // Check if the product stock is available
            $availableStock = $product->getAvailableStock();

            $combinedAvailableStock += $availableStock;

            if ($availableStock > 0) {
                return;
            }

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
                $combinedAvailableStock += $productVariant->getAvailableStock();
                // remove the stock notification email notification input field (from another plugin) if the product is purchaseable
                if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                    $customFields['acris_stock_notification_email_notification_inactive'] = true;
                }
            } else {
                // add a stock notification email notification input field (from another plugin) if the product is not purchaseable
                if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                    $customFields['acris_stock_notification_email_notification_inactive'] = false;
                }
            }

            // Edit the max purchase of the product (not sure if this is from element template or standard shopware field)
            $product->setCalculatedMaxPurchase($combinedAvailableStock);

            // Update the product custom fields
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
