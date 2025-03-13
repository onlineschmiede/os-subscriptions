<?php

namespace OsSubscriptions\Checkout\Cart;

class SubscriptionLineItem
{
    final public const PRODUCT_RESIDUAL_TYPE = 'residual';
    final public const DISCOUNT_RESIDUAL_TYPE = 'residual-discount';
    final public const PRODUCT_SUBSCRIPTION_TYPE = 'subscription';
    final public const DISCOUNT_SUBSCRIPTION_TYPE = 'rent_interval_discount';
}