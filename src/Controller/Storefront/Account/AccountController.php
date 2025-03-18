<?php declare(strict_types=1);

namespace OsSubscriptions\Controller\Storefront\Account;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use Kiener\MolliePayments\Controller\Storefront\AbstractStoreFrontController;
use OsSubscriptions\Checkout\Cart\SubscriptionLineItem;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends AbstractStoreFrontController
{
    public function __construct(
        private readonly SubscriptionManager     $subscriptionManager,
        private readonly SystemConfigService     $systemConfigService,
        private readonly EntityRepository        $orderRepository,
        private readonly EntityRepository        $productRepository,
        private readonly CartService             $cartService,
        private readonly QuantityPriceCalculator $quantityPriceCalculator,
        private readonly MailService             $mailService,
        private readonly EntityRepository        $emailTemplateRepository,
        private readonly EntityRepository        $subscriptionRepository,
        private readonly LoggerInterface         $logger
    )
    {
    }

    /**
     * Mark the latest order within an subscription as initiated for cancellation.
     * @param string $subscriptionId
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return Response
     */
    public function initiateReturnOrderProcess(string $subscriptionId, Request $request, SalesChannelContext $salesChannelContext): Response
    {
        if (!$this->isLoggedIn($salesChannelContext)) {
            return $this->redirectToLoginPage();
        }

        try {
            $subscriptionEntity = $this->subscriptionRepository->search(new Criteria([$subscriptionId]), $salesChannelContext->getContext())->first();
            $subscriptionMetaData = $subscriptionEntity->getMetadata()->toArray() ?? [];

            $subscriptionMetaData['cancellation_initialized_at'] ??= (new \DateTime())->format('Y-m-d H:i:s T');
            $subscriptionMetaData['cancellation_reviewed_at'] ??= null;
            $subscriptionMetaData['cancellation_declined_at'] ??= null;
            $subscriptionMetaData['cancellation_accepted_at'] ??= null;
            $subscriptionMetaData['status'] ??= "cancellation_initialized";

            $this->subscriptionRepository->update([
                [
                    'id' => $subscriptionEntity->getId(),
                    'metadata' => $subscriptionMetaData,
                ]
            ], $salesChannelContext->getContext());

            $this->sendSubscriptionCancellationEmail($subscriptionId, $salesChannelContext);

            return $this->routeToSuccessPage('Deine Rücksendung wurde angelegt. Wir senden dir in Kürze alle weiteren Informationen per E-Mail zu.', 'Return process initiated for subscription ' . $subscriptionId);

        } catch (\Throwable $exception) {
            return $this->routeToErrorPage(
                'Unerwarteter Fehler beim starten des Rücksendungsprozesses.',
                'Error while attempting to initiate the return process for subscription ' . $subscriptionId . ': ' . $exception->getMessage()
            );
        }
    }

    /**
     * Buy a running subscription residual
     * @param string $subscriptionId
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @param Cart $cart
     * @return Response
     * @throws \Exception
     */
    public function residualPurchase(string $subscriptionId, Request $request, SalesChannelContext $salesChannelContext, Cart $cart): Response
    {
        if (!$this->isLoggedIn($salesChannelContext) ||
            !$this->systemConfigService->get("OsSubscriptions.config.residualPurchaseActive")) {
            return $this->redirectToLoginPage();
        }

        $subscriptionEntity = $this->subscriptionManager->findSubscription($subscriptionId, $salesChannelContext->getContext());
        if (!$this->subscriptionManager->isCancelable($subscriptionEntity, $salesChannelContext->getContext())) {
            return $this->routeToErrorPage(
                'Die Restkaufoption für dieses Abonnement ist nicht mehr verfügbar.',
                'Error while trying to purchase residually for subscription ' . $subscriptionId . ': tried to purchase residually for an already cancelled subscription'
            );
        }

        try {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('lineItems.product.parentProduct');
            $criteria->addAssociation('lineItems.price.taxRules');
            $criteria->addAssociation('deliveries');
            $criteria->addAggregation(new SumAggregation('sum-amountTotal', 'amountTotal'));
            $criteria->addAggregation(new SumAggregation('sum-amountNet', 'amountNet'));
            $criteria->addAggregation(new SumAggregation('sum-shippingTotal', 'shippingTotal'));
            $orders = $this->orderRepository->search($criteria, $salesChannelContext->getContext());

            $initialOrder = $orders->first();
            $orderCount = count($orders);
            $maxOrderCount = $this->systemConfigService->get("OsSubscriptions.config.residualPurchaseValidUntilInterval", $salesChannelContext->getSalesChannel()->getId());

            if ($orderCount > $maxOrderCount) {
                return $this->routeToErrorPage(
                    'Die Restkaufoption für dieses Abonnement ist nicht mehr verfügbar.',
                    'Error while trying to purchase residually for subscription ' . $subscriptionId . ': orderCount is greater then maxOrderCount'
                );
            }

            # to prevent any edge cases we will clear the cart first
            foreach ($cart->getLineItems() as $key => $cartLineItem) {
                if ($cart->get($key)) {
                    $this->cartService->remove($cart, $cartLineItem->getId(), $salesChannelContext);
                }
            }

            foreach ($initialOrder->getLineItems() as $orderLineItem) {
                $hasRentalOption = array_reduce(
                    $orderLineItem->getPayload()['options'],
                    fn($carry, $option) => $carry && $option['option'] === 'Mieten',
                    true
                );

                if (!$hasRentalOption) {
                    continue;
                }

//                $salesChannelContext->setPermissions([
//                    ProductCartProcessor::SKIP_PRODUCT_RECALCULATION,
//                    ProductCartProcessor::SKIP_PRODUCT_STOCK_VALIDATION,
//                    "skipDeliveryPriceRecalculation",
//                    "skipDeliveryRecalculation",
//                ]);

                $nonRentableLineItem = $this->getRelatedLineItemByOrderLineEntity($orderLineItem, $salesChannelContext, $subscriptionId);
                $this->cartService->add($cart, $nonRentableLineItem, $salesChannelContext);
            }

            $residualDiscountLineItem = $this->getResidualDiscountLineItem($orders, $salesChannelContext, $subscriptionId);
            $this->cartService->add($cart, $residualDiscountLineItem, $salesChannelContext);

            return $this->redirectToRoute('frontend.checkout.cart.page');

        } catch (\Throwable $exception) {
            return $this->routeToErrorPage(
                'Unerwarteter Fehler beim nutzen der Restkauf-Option.',
                'Error occured on residual purchase for ' . $subscriptionId . ': ' . $exception->getMessage()
            );
        }
    }

    /**
     * @param string $subscriptionId
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    private function sendSubscriptionCancellationEmail(string $subscriptionId, SalesChannelContext $salesChannelContext): void
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
        $criteria->addAssociation('customer');
        $latestOrder = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->last();

        $criteria = new Criteria();
        $criteria->addAssociation('mailTemplateType');
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'subscription.cancel'));
        $emailTemplate = $this->emailTemplateRepository->search($criteria, $salesChannelContext->getContext())->first();

        $mailData = [
            'recipients' => [
                $latestOrder->getOrderCustomer()->getEmail() => $latestOrder->getOrderCustomer()->getFirstName() . ' ' . $latestOrder->getOrderCustomer()->getLastName()
            ],
            'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
            'subject' => $emailTemplate->getTranslation('subject'),
            'senderName' => $emailTemplate->getTranslation('senderName'),
            'contentPlain' => $emailTemplate->getTranslation('contentPlain'),
            'contentHtml' => $emailTemplate->getTranslation('contentHtml'),
            'mediaIds' => [],
        ];

        $templateData = $emailTemplate->jsonSerialize();
        $templateData['customer'] = $latestOrder->getOrderCustomer();

        $this->mailService->send(
            $mailData,
            $salesChannelContext->getContext(),
            $templateData
        );
    }

    /**
     * @param EntitySearchResult $orders
     * @param SalesChannelContext $salesChannelContext
     * @param string $subscriptionId
     * @return LineItem
     */
    private function getResidualDiscountLineItem(EntitySearchResult $orders, SalesChannelContext $salesChannelContext, string $subscriptionId): LineItem
    {
        $discountLineItem = new LineItem(Uuid::randomHex(), SubscriptionLineItem::DISCOUNT_RESIDUAL_TYPE);

        $discountLineItem->setLabel('Restkauf Rabatt auf Abonnement');
        $discountLineItem->setDescription('Restkauf Rabatt auf Abonnement');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(true);
        $discountLineItem->setPayload([
            'residualPurchase' => true,
            'mollieSubscriptionId' => $subscriptionId
        ]);

        $acknowledgedPaymentPercentage = $this->systemConfigService
            ->get("OsSubscriptions.config.residualPurchaseAcknowledgedPaymentPercentage",
                $salesChannelContext->getSalesChannel()->getId());

        $sumAmountTotal = $orders->getAggregations()->get('sum-amountTotal')->getSum();
        $sumAmountNet = $orders->getAggregations()->get('sum-amountNet')->getSum();
        $sumShippingTotal = $orders->getAggregations()->get('sum-shippingTotal')->getSum();
        $acknowledgedPaymentSum = ($sumAmountTotal - $sumShippingTotal) * ($sumAmountNet / $sumAmountTotal);
        $discount = $acknowledgedPaymentSum * (0.01 * $acknowledgedPaymentPercentage);

        $taxRule = null;
        foreach($orders->first()->getPrice()->getTaxRules()->getElements() as $tax)
        {
            if(!$taxRule) {
                $taxRule = $tax;
            }
        }

        $definition = new QuantityPriceDefinition(
            ($discount * -1) * (($taxRule->getTaxRate() / $taxRule->getPercentage()) + 1),
            new TaxRuleCollection([new TaxRule($taxRule->getTaxRate(), $taxRule->getPercentage())]),
            1
        );

        $discountLineItem->setPriceDefinition($definition);
        $discountLineItem->setPrice(
            $this->quantityPriceCalculator->calculate($definition, $salesChannelContext)
        );

        return $discountLineItem;
    }

    /**
     * Returns related "non-rentable" LineItem that can be added to cart
     * @param OrderLineItemEntity $rentOrderLineItem
     * @param SalesChannelContext $salesChannelContext
     * @param string $subscriptionId
     * @return LineItem
     */
    private function getRelatedLineItemByOrderLineEntity(OrderLineItemEntity $rentOrderLineItem, SalesChannelContext $salesChannelContext, string $subscriptionId): LineItem
    {
        $orderLineItemProductParentId = $rentOrderLineItem->getProduct()->getParentId();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $orderLineItemProductParentId));
        $criteria->addFilter(new EqualsFilter('options.name', 'Kaufen'));
        $criteria->addAssociation('options');

        $productBuyVariant = $this->productRepository->search($criteria, $salesChannelContext->getContext())->first();

        $lineItem = new LineItem(Uuid::randomHex(), SubscriptionLineItem::PRODUCT_RESIDUAL_TYPE);
        $lineItem->setLabel($rentOrderLineItem->getProduct()->getTranslation('name') . ' (Restkauf)');
        $lineItem->setDescription($rentOrderLineItem->getProduct()->getTranslation('description'));

        $lineItem->setReferencedId($rentOrderLineItem->getProduct()->getId());
        $lineItem->setGood(false);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(true);
        $lineItem->setDeliveryInformation(null);

        $payload = [];
        $payload['residualPurchase'] = true;
        $payload['mollieSubscriptionId'] = $subscriptionId;
        $payload['parentId'] = $orderLineItemProductParentId;
        $payload['productNumber'] = $rentOrderLineItem->getProduct()->getProductNumber();
        $payload['manufacturerId'] = $rentOrderLineItem->getProduct()->getManufacturerId();
        $payload['options'] = $rentOrderLineItem->getPayload()['options'] ?? [];

        $lineItem->setPayload($payload);

        $lineItem->setQuantity($rentOrderLineItem->getQuantity());
        $definition = new QuantityPriceDefinition(
            $productBuyVariant->getPrice()->first()->getGross(),
            $rentOrderLineItem->getPrice()->getTaxRules(),
            $rentOrderLineItem->getQuantity()
        );
        $lineItem->setPriceDefinition($definition);
        $lineItem->setPrice(
            $this->quantityPriceCalculator->calculate($definition, $salesChannelContext)
        );

        return $lineItem;
    }

    /**
     * @param string $errorMessage
     * @param string $logMessage
     * @return RedirectResponse
     */
    private function routeToErrorPage(string $errorMessage, string $logMessage): RedirectResponse
    {
        $this->logger->error($logMessage);

        $this->addFlash(self::DANGER, $errorMessage);

        return $this->redirectToRoute('frontend.account.mollie.subscriptions.page');
    }

    /**
     * @param string $successMessage
     * @param string $logMessage
     * @return RedirectResponse
     */
    private function routeToSuccessPage(string $successMessage, string $logMessage): RedirectResponse
    {
        $this->logger->info($logMessage);

        $this->addFlash(self::SUCCESS, $successMessage);

        return $this->redirectToRoute('frontend.account.mollie.subscriptions.page');
    }

    /**
     * @return RedirectResponse
     */
    private function redirectToLoginPage(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('frontend.account.login'), 302);
    }

    /**
     * @param SalesChannelContext $context
     * @return bool
     */
    private function isLoggedIn(SalesChannelContext $context): bool
    {
        return ($context->getCustomer() instanceof CustomerEntity);
    }
}
