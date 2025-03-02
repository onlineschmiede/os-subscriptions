<?php declare(strict_types=1);

namespace OsSubscriptions\Controller\Storefront\Account;

use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use Kiener\MolliePayments\Controller\Storefront\AbstractStoreFrontController;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
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
use Symfony\Component\Routing\Generator\UrlGenerator;

class AccountController extends AbstractStoreFrontController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptionManager,
        private readonly LoggerInterface $logger,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productRepository,
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactoryRegistry,
        private readonly QuantityPriceCalculator $quantityPriceCalculator,
        private readonly MailService $mailService,
        private readonly EntityRepository $emailTemplateRepository
    )
    { }

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
            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('customer');
            $orders = $this->orderRepository->search($criteria, $salesChannelContext->getContext());

            $latestOrder = $orders->last();
            $customFields = $latestOrder->getCustomFields() ?? [];
            $customFields['subscription_cancellation_initialized_at'] = (new \DateTime())->format('Y-m-d H:i:s T');
            $latestOrder->setCustomFields($customFields);

            $this->orderRepository->update([
                [
                    'id' => $latestOrder->getId(),
                    'customFields' => $customFields,
                ]
            ], $salesChannelContext->getContext());

            $this->sendSubscriptionCancellationEmail($latestOrder, $salesChannelContext);

            return $this->routeToSuccessPage('Wir senden Ihnen in kürze alle Informationen bezüglich Ihrer Rücksendung zu. Bitte prüfen Sie Ihr E-Mail Postfach.', 'Return process initiated for subscription ' . $subscriptionId);

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
        if(!$this->subscriptionManager->isCancelable($subscriptionEntity, $salesChannelContext->getContext())) {
            return $this->routeToErrorPage(
                'Die Restkaufoption für dieses Abonnement ist nicht mehr verfügbar.',
                'Error while trying to purchase residually for subscription ' . $subscriptionId . ': tried to purchase residually for an already cancelled subscription'
            );
        }

        try {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('lineItems.product');
            $criteria->addAssociation('deliveries');
            $criteria->addAggregation(new SumAggregation('sum-amountNet', 'amountNet'));
            $orders = $this->orderRepository->search($criteria, $salesChannelContext->getContext());

            $initialOrder = $orders->first();
            $orderCount = count($orders);
            $maxOrderCount = $this->systemConfigService->get("OsSubscriptions.config.residualPurchaseValidUntilInterval", $salesChannelContext->getSalesChannel()->getId());

            if($orderCount > $maxOrderCount) {
                return $this->routeToErrorPage(
                    'Die Restkaufoption für dieses Abonnement ist nicht mehr verfügbar.',
                    'Error while trying to purchase residually for subscription ' . $subscriptionId . ': orderCount is greater then maxOrderCount'
                );
            }

            foreach($initialOrder->getLineItems() as $orderLineItem) {
                $hasRentalOption = array_reduce(
                    $orderLineItem->getPayload()['options'],
                    fn($carry, $option) => $carry && $option['option'] === 'Mieten',
                    true
                );

                if(!$hasRentalOption) {
                    continue;
                }

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
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    private function sendSubscriptionCancellationEmail(OrderEntity $orderEntity, SalesChannelContext $salesChannelContext): void
    {
        $criteria = new Criteria();
        $criteria->addAssociation('mailTemplateType');
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'subscription.cancel'));
        $emailTemplate = $this->emailTemplateRepository->search($criteria, $salesChannelContext->getContext())->first();

        $mailData = [
            'recipients' => [
                $orderEntity->getOrderCustomer()->getEmail()
            ],
            'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
            'subject' => $emailTemplate->getTranslation('subject'),
            'senderName' => $emailTemplate->getTranslation('senderName'),
            'contentPlain' => $emailTemplate->getTranslation('contentPlain'),
            'contentHtml' => $emailTemplate->getTranslation('contentHtml'),
            'mediaIds' => [],
        ];

        $templateData = $emailTemplate->jsonSerialize();
        $templateData['customer'] = $orderEntity->getOrderCustomer();

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
        $discountLineItem = new LineItem(Uuid::randomHex(), LineItem::CUSTOM_LINE_ITEM_TYPE);

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

        $discount = $orders->getAggregations()->get('sum-amountNet')->getSum() * (0.01 * $acknowledgedPaymentPercentage);

        $definition = new QuantityPriceDefinition(
            $discount * -1,
            $orders->getEntities()->first()->getPrice()->getTaxRules(),
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
     * @param OrderLineItemEntity $orderLineItemEntity
     * @param SalesChannelContext $salesChannelContext
     * @param string $subscriptionId
     * @return LineItem
     */
    private function getRelatedLineItemByOrderLineEntity(OrderLineItemEntity $orderLineItemEntity, SalesChannelContext $salesChannelContext, string $subscriptionId): LineItem
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $orderLineItemEntity->getProduct()->getParentId()));
        $criteria->addFilter(new EqualsFilter('options.name', 'Kaufen'));
        $criteria->addAssociation('options');

        $parentProduct = $this->productRepository->search($criteria, $salesChannelContext->getContext());

        return $this->lineItemFactoryRegistry->create([
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => $parentProduct->first()->getId(),
            'quantity' => $orderLineItemEntity->getQuantity(),
            'payload' => [
                'residualPurchase' => true,
                'mollieSubscriptionId' => $subscriptionId,
            ]
        ], $salesChannelContext);
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
