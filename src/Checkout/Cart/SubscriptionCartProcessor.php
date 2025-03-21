<?php

declare(strict_types=1);

namespace OsSubscriptions\Checkout\Cart;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SubscriptionCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private PercentagePriceCalculator $calculator;
    private SystemConfigService $systemConfigService;
    private EntityRepository $orderRepository;
    private readonly LoggerInterface $logger;

    public function __construct(PercentagePriceCalculator $calculator, SystemConfigService $systemConfigService, EntityRepository $orderRepository, LoggerInterface $logger)
    {
        $this->calculator = $calculator;
        $this->systemConfigService = $systemConfigService;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * Here we can safely fetch the database without impact on performance
     * and prepare the discounts for the process method.
     */
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // orders get copied from mollie subscriptions, so we can use the originalId extension to retrieve the original order
        // if there is no originalId extension, we can skip and assume its an initial storefront order
        $originalIdExtension = $original->getExtension('originalId');
        if (!$originalIdExtension) {
            return;
        }

        $originalOrderId = $originalIdExtension->getId();

        // as discount is global we can set it to CartDataCollection, so it is available in process method
        // this is important, as the process method is called many times (avoiding db exhaustion)
        if (empty($data->get('rental-discount-percentage'))) {
            $rentalDiscountPercentage = $this->getRentalDiscountPercentage($originalOrderId, $context->getContext());
            $data->set('rental-discount-percentage', $rentalDiscountPercentage);
        }
    }

    /**
     * Here we CAN NOT query the database without impact on performance.
     */
    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // verify that we have rental products in the cart
        $rentalProducts = $this->findRentalProducts($toCalculate);
        if (0 === $rentalProducts->count()) {
            return;
        }

        // retrieve discount percentage from cart data set in collect method
        $rentDiscountPercentage = $data->get('rental-discount-percentage');

        // initial orders do not have any discount, we can skip
        if (!$rentDiscountPercentage) {
            return;
        }

        $rentalProductDiscount = $this->createDiscount('rental-discount');

        $discountPercentageDefinition = new PercentagePriceDefinition(
            $rentDiscountPercentage * -1,
            new LineItemRule(LineItemRule::OPERATOR_EQ, $rentalProducts->getReferenceIds())
        );

        $rentalProductDiscount->setPriceDefinition($discountPercentageDefinition);

        $rentalProductDiscount->setPrice(
            $this->calculator->calculate($discountPercentageDefinition->getPercentage(), $rentalProducts->getPrices(), $context)
        );

        // add discount to new cart
        $toCalculate->add($rentalProductDiscount);

        $this->removeShippingCosts($original);
    }

    /**
     * Filters the cart for rental products.
     */
    private function findRentalProducts(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems()->filter(function (LineItem $item) {
            // Only consider products, not custom line items or promotional line items
            if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $item->getType()) {
                return false;
            }

            // Only consider products with options
            if (!$item->getPayloadValue('options') || empty($item->getPayloadValue('options'))) {
                return false;
            }

            // Only consider products that have "Mieten" option within options array
            $hasRentalOption = array_reduce(
                $item->getPayloadValue('options'),
                fn ($carry, $option) => $carry && 'Mieten' === $option['option'],
                true
            );
            if (false === $hasRentalOption) {
                return false;
            }

            return $item;
        });
    }

    private function createDiscount(string $name): LineItem
    {
        $discountLineItem = new LineItem($name, SubscriptionLineItem::DISCOUNT_SUBSCRIPTION_TYPE, null, 1);

        $discountLineItem->setLabel('Rabatt auf Abonnement');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

        return $discountLineItem;
    }

    /**
     * Obtaining the discount value set in config
     * which matches the repetition/interval of the order by
     * building a delta from the first order.
     */
    private function getRentalDiscountPercentage(string $originalOrderNumber, Context $context): float
    {
        try {
            $defaultDiscount = 0.0;
            $orderEntity = $this->orderRepository->search(new Criteria([$originalOrderNumber]), $context)->first();
            $orderCustomFields = $orderEntity->getCustomFields();

            // orders through storefront don't have custom fields,
            // so we can skip and return the default discount
            if (!$orderCustomFields) {
                return $defaultDiscount;
            }
            if (!isset($orderCustomFields['mollie_payments']['swSubscriptionId'])) {
                return $defaultDiscount;
            }

            $subscriptionId = $orderCustomFields['mollie_payments']['swSubscriptionId'];

            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $existingOrderCount = count($this->orderRepository->search($criteria, $context));

            // use the last interval value set by user config if the interval exceeds the max number of renewals
            $maxNumberOfDiscounts = $this->systemConfigService->get('OsSubscriptions.config.numberOfDiscounts', $orderEntity->getSalesChannelId());
            $interval = min($existingOrderCount, $maxNumberOfDiscounts);

            return $this->systemConfigService->get("OsSubscriptions.config.rentDiscountPercentageAtInterval{$interval}", $orderEntity->getSalesChannelId());
        } catch (\Exception $e) {
            $this->logger->error('ERROR: OrderSubscriber:', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
        }
    }

    /**
     * Sets all shipping cost to zero.
     */
    private function removeShippingCosts(Cart $cart): void
    {
        foreach ($cart->getDeliveries() as $delivery) {
            $costs = new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection());
            $delivery->setShippingCosts($costs);
        }
    }
}
