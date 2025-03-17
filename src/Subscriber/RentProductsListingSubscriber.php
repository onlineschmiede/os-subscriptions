<?php

namespace OsSubscriptions\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RentProductsListingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {}

    public static function getSubscribedEvents(): array
    {
        // subscribe to listing page and search results page
        return [
            ProductListingResultEvent::class => [
                ['handleListingResult'],
            ],
            ProductSearchResultEvent::class => [
                ['handleSearchingResult'],
            ],
        ];
    }

    public function handleListingResult(ProductListingResultEvent $event): void
    {
        // get all products entities from listing page
        $entityElements = $event->getResult()->getEntities()->getElements();

        // if there are products proceed
        if (count($entityElements) > 0) {
            foreach ($entityElements as $key => $element) {
                $shouldUpdateCustomFields = false;
                // Check if the product stock is available
                $availableStock = $element->getAvailableStock();

                if ($availableStock > 0) {
                    continue;
                }

                // Get the custom fields of the product and check if the product is a subscription product if the stock is not available
                $customFields = $element->getCustomFields();
                if (isset($customFields['mollie_payments_product_subscription_enabled'])
                and true === $customFields['mollie_payments_product_subscription_enabled']
                and !empty($customFields['mollie_payments_product_parent_buy_variant'])
                ) {
                    // if the product is a subscription product with borrowing variant available
                    $isVariantToBorrowFromAvailable = $this->checkIfSubscriptionProductWithBorrowingVariantAvailable($customFields, $event->getContext());

                    if ($isVariantToBorrowFromAvailable) {
                        // set element stock to 1 so the product is purchaseable
                        $element->setStock(1);
                        $element->setAvailableStock(1);

                        // remove the stock notification email notification input field (from another plugin) if the product is purchaseable
                        if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                            $customFields['acris_stock_notification_email_notification_inactive'] = true;

                            $shouldUpdateCustomFields = true;
                        }
                    } else {
                        if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                            $customFields['acris_stock_notification_email_notification_inactive'] = false;

                            $shouldUpdateCustomFields = true;
                        }
                    }

                    if ($shouldUpdateCustomFields) {
                        // Update the product custom fields
                        $element->setCustomFields($customFields);
                        $this->productRepository->update([
                            [
                                'id' => $element->getId(),
                                'customFields' => $customFields,
                            ],
                        ], $event->getContext());
                    }
                } else {
                    continue;
                }
            }
        }
    }

    public function handleSearchingResult(ProductSearchResultEvent $event): void
    {
        // get all products entities from listing page
        $entityElements = $event->getResult()->getEntities()->getElements();

        // if there are products proceed
        if (count($entityElements) > 0) {
            foreach ($entityElements as $key => $element) {
                $shouldUpdateCustomFields = false;
                // Check if the product stock is available
                $availableStock = $element->getAvailableStock();

                if ($availableStock > 0) {
                    continue;
                }

                // Get the custom fields of the product and check if the product is a subscription product if the stock is not available
                $customFields = $element->getCustomFields();
                if (isset($customFields['mollie_payments_product_subscription_enabled'])
                and true === $customFields['mollie_payments_product_subscription_enabled']
                and !empty($customFields['mollie_payments_product_parent_buy_variant'])
                ) {
                    // if the product is a subscription product with borrowing variant available
                    $isVariantToBorrowFromAvailable = $this->checkIfSubscriptionProductWithBorrowingVariantAvailable($customFields, $event->getContext());

                    if ($isVariantToBorrowFromAvailable) {
                        // set element stock to 1 so the product is purchaseable
                        $element->setStock(1);
                        $element->setAvailableStock(1);

                        // remove the stock notification email notification input field (from another plugin) if the product is purchaseable
                        if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                            $customFields['acris_stock_notification_email_notification_inactive'] = true;

                            $shouldUpdateCustomFields = true;
                        }
                    } else {
                        if (isset($customFields['acris_stock_notification_email_notification_inactive'])) {
                            $customFields['acris_stock_notification_email_notification_inactive'] = false;

                            $shouldUpdateCustomFields = true;
                        }
                    }

                    if ($shouldUpdateCustomFields) {
                        // Update the product custom fields
                        $element->setCustomFields($customFields);
                        $this->productRepository->update([
                            [
                                'id' => $element->getId(),
                                'customFields' => $customFields,
                            ],
                        ], $event->getContext());
                    }
                } else {
                    continue;
                }
            }
        }
    }

    public function checkIfSubscriptionProductWithBorrowingVariantAvailable($customFields, $context): bool
    {
        if (!$customFields or !isset($customFields['mollie_payments_product_subscription_enabled'])) {
            return false;
        }

        // Check if the product is a subscription product
        $isSubscriptionProduct = true === $customFields['mollie_payments_product_subscription_enabled'] ? true : false;

        if (!$isSubscriptionProduct) {
            return false;
        }

        // Check if the product has a borrow product variant
        $borrowProductVariantId = $customFields['mollie_payments_product_parent_buy_variant'];

        if (!$borrowProductVariantId) {
            return false;
        }

        // search for the borrow product variant
        $criteria = new Criteria([$borrowProductVariantId]);
        $criteria->addAssociation('customFields');

        $productVariant = $this->productRepository->search($criteria, $context)->first();

        // Check if the borrow product variant is available on stock
        if ($productVariant and $productVariant->getAvailableStock() > 0) {
            // make the product purchaseable
            return true;
        }

        return false;
    }
}
