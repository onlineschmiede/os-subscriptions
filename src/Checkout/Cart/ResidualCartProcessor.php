<?php declare(strict_types=1);

namespace OsSubscriptions\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ResidualCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    public function __construct()
    {
    }


    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
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
        $hasResidualProducts = false;

        foreach ($original->getLineItems() as $lineItem) {
            if ($lineItem->getType() === SubscriptionLineItem::PRODUCT_RESIDUAL_TYPE ||
                $lineItem->getType() === SubscriptionLineItem::DISCOUNT_RESIDUAL_TYPE) {
                $hasResidualProducts = true;

                $toCalculate->add($lineItem);
                $toCalculate->markModified();
            }
        }

        $this->removeInvalidResidualDiscounts($toCalculate);

        # disable loyalty points
        if ($hasResidualProducts) {
            $context->getCustomer()->removeExtension('loyaltyPoints');
        }
    }

    /**
     * Removes invalid residual discounts from the cart
     * @param Cart $cart
     * @return void
     */
    private function removeInvalidResidualDiscounts(Cart $cart): void
    {
        $residualDiscounts = $this->findResidualDiscounts($cart);
        $residualProducts = $this->findResidualProducts($cart);

        # if there are residual discounts, but no residual products,
        # we can remove all residual discounts.
        if ($residualProducts->count() !== $residualDiscounts->count()) {
            foreach ($residualDiscounts as $key => $residualDiscount) {
                $cart->remove($key);
            }
        }
    }

    /**
     * Get all products within the cart that are marked for residual purchase
     * @param Cart $cart
     * @return LineItemCollection
     */
    private function findResidualProducts(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems()->filter(function (LineItem $item) {
            if ($item->getType() === SubscriptionLineItem::PRODUCT_RESIDUAL_TYPE) {
                return true;
            }
            return false;
        });
    }

    /**
     * Get all discounts within the cart that are used for residual purchases
     * @param Cart $cart
     * @return LineItemCollection
     */
    private function findResidualDiscounts(Cart $cart): LineItemCollection
    {
        return $cart->getLineItems()->filter(function (LineItem $item) {
            if ($item->getType() === SubscriptionLineItem::DISCOUNT_RESIDUAL_TYPE) {
                return true;
            }
            return false;
        });
    }
}