# os-subscriptions
rental products through product variants using a custom cart processor


## dev: setup 

- ensure you have Mollie @ 4.13.0 installed
- add `MOLLIE_DEV_MODE=1` to your `.env` file and enable debugging `APP_ENV=dev`

## dev: test subscriptions

- Order an Item which has "Mieten" Variant as subscription through the storefront
- Use [Mollie Test Credit Cards](https://docs.mollie.com/reference/testing#testing-different-types-of-cards) at the checkout
- Copy the `swSubscriptionId` and `payment_id` from your new order in the orders table. These values are located within the `custom_fields` json column under the node `mollie_payments`.
- Mimic a webhook call from mollie using curl:
```bash
curl -X POST --location "https://test.babyrella.at/mollie/webhook/subscription/swSubscriptionId" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d 'id=payment_id'
```

The steps above should mark the subscription as active, which is required for renewals to work.

At this stage you might want to test the renewal process without waiting a whole month for the next billing cycle.
Therefore, you have to modify the code below so you can trigger a renewal for each webhook call you fire.

```php
# custom/plugins/MolliePayments/src/Controller/Storefront/Webhook/WebhookControllerBase.php

    public function onWebhookSubscriptionLegacyReceived(string $swSubscriptionId, Request $request, RequestDataBag $requestData, SalesChannelContext $context): JsonResponse
    {
// COMMENT THIS BELOW OUT IF YOU WANT TO TRIGGER RENEWALS    
//            if ($existingOrders->count() <= 0) {
//                $swOrder = $this->subscriptions->renewSubscription($swSubscriptionId, $molliePaymentId, $context->getContext());
//            } else {
//                $swOrder = $existingOrders->last();
//            }

// ADD THIS IF YOU WANT TO TRIGGER RENEWALS INSTEAD
            $swOrder = $this->subscriptions->renewSubscription($swSubscriptionId, $molliePaymentId, $context->getContext());
```

Afterward you can curl again:
```bash
curl -X POST --location "https://test.babyrella.at/mollie/webhook/subscription/swSubscriptionId" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d 'id=payment_id'
```

> [!IMPORTANT]
> If you are finished testing the renewal process, keep in mind that you have to revert those changes back, otherwise
> the subscription will not be activated.


## dev: test residual purchase

There is currently a bug with the system that requires you to set `APP_ENV=prod` on the initial load when visiting
the account page, which can be set again to `APP_ENV=dev` after the mentioned initial load. Just don't forget
to reload the page after those changes are applied.


