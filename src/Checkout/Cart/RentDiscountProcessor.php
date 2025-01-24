<?php declare(strict_types=1);

namespace OsSubscriptions\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\PercentagePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

// https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/change-price-of-item.html
// https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/customize-price-calculation.html

class RentDiscountProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private PercentagePriceCalculator $calculator;
    private SystemConfigService $systemConfigService;
    private EntityRepository $orderRepository;

    /**
     * @param PercentagePriceCalculator $calculator
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository $orderRepository
     */
    public function __construct(PercentagePriceCalculator $calculator, SystemConfigService $systemConfigService, EntityRepository $orderRepository)
    {
        $this->calculator = $calculator;
        $this->systemConfigService = $systemConfigService;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Here we can safely fetch the database without impact on performance
     * and prepare the discounts for the process method
     * @param CartDataCollection $data
     * @param Cart $original
     * @param SalesChannelContext $context
     * @param CartBehavior $behavior
     * @return void
     */
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        # orders get copied from mollie subscriptions, so we can use the originalId extension to retrieve the original order
        # if there is no originalId extension, we can skip and assume its an initial storefront order
        $originalIdExtension = $original->getExtension('originalId');
        if(!$originalIdExtension) {
            return;
        }

        $originalOrderId = $originalIdExtension->getId();
        $rentalDiscountPercentage = $this->getRentalDiscountPercentage($originalOrderId, $context->getContext());

        # as discount is global we can set it to CartDataCollection, so it is available in process method
        # this is important, as the process method is called many times (avoiding db exhaustion)
        $data->set('rental-discount-percentage', $rentalDiscountPercentage);
    }


    /**
     * Here we CAN NOT query the database without impact on performance
     * @param CartDataCollection $data
     * @param Cart $original
     * @param Cart $toCalculate
     * @param SalesChannelContext $context
     * @param CartBehavior $behavior
     * @return void
     */
    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        # verify that we have rental products in the cart
        $rentalProducts = $this->findRentalProducts($toCalculate);
        if ($rentalProducts->count() === 0) {
            return;
        }

        # retrieve discount percentage from cart data set in collect method
        $rentDiscountPercentage = $data->get("rental-discount-percentage");

        # initial orders do not have any discount, we can skip
        if(!$rentDiscountPercentage) {
            return;
        }

        $rentalProductDiscount = $this->createDiscount('rental-discount');

        $discountPercentageDefinition = new PercentagePriceDefinition(
            (100 - $rentDiscountPercentage) * -1,
            new LineItemRule(LineItemRule::OPERATOR_EQ, $rentalProducts->getReferenceIds())
        );

        $rentalProductDiscount->setPriceDefinition($discountPercentageDefinition);

        $rentalProductDiscount->setPrice(
            $this->calculator->calculate($discountPercentageDefinition->getPercentage(), $rentalProducts->getPrices(), $context)
        );

        # add discount to new cart
        $toCalculate->add($rentalProductDiscount);
    }

    /**
     * Filters the cart for rental products
     * @param Cart $cart
     * @return LineItemCollection
     */
    private function findRentalProducts(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems()->filter(function (LineItem $item) {
            # Only consider products, not custom line items or promotional line items
            if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return false;
            }

            # Only consider products with options
            if(!$item->getPayloadValue('options') || empty($item->getPayloadValue('options'))) {
                return false;
            }

            # Only consider products that have "Mieten" option within options array
            $hasRentalOption = array_reduce(
                $item->getPayloadValue('options'),
                fn($carry, $option) => $carry && $option['option'] === 'Mieten',
                true
            );
            if($hasRentalOption === false) {
                return false;
            }

            return $item;
        });
    }

    /**
     * @param string $name
     * @return LineItem
     */
    private function createDiscount(string $name): LineItem
    {
        $discountLineItem = new LineItem($name, 'example_discount', null, 1);

        $discountLineItem->setLabel('Rabatt auf Abonnement');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

        return $discountLineItem;
    }

    /**
     * Obtaining the discount value set in config
     * which matches the repetition/interval of the order by
     * building a delta from the first order
     * @param string $originalOrderNumber
     * @param Context $context
     * @return float
     */
    private function getRentalDiscountPercentage(string $originalOrderNumber, Context $context): float
    {
        $defaultDiscount = 0.0;
        $orderEntity = $this->orderRepository->search(new Criteria([$originalOrderNumber]), $context)->first();
        $orderCustomFields = $orderEntity->getCustomFields();
        if(!$orderCustomFields) {
            return $defaultDiscount;
        } else {
            $subscriptionId = $orderCustomFields['mollie_payments']['swSubscriptionId'];
        }
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
        $interval = count($this->orderRepository->search($criteria, $context));

        # use the last interval value (12) for continuous renewals that exceed the number of configured intervals
        $interval = min($interval + 1, 12);
        return $this->systemConfigService->get("OsSubscriptions.config.rentDiscountPercentageAtInterval{$interval}", $orderEntity->getSalesChannelId());
    }
}