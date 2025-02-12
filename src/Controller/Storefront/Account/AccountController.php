<?php declare(strict_types=1);

namespace OsSubscriptions\Controller\Storefront\Account;

use Kiener\MolliePayments\Components\Subscription\Page\Account\SubscriptionPageLoader;
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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;

class AccountController extends AbstractStoreFrontController
{
    /**
     * @var SubscriptionPageLoader
     */
    private SubscriptionPageLoader $pageLoader;

    /**
     * @var SubscriptionManager
     */
    private SubscriptionManager $subscriptionManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SystemConfigService
     */
    private SystemConfigService $systemConfigService;

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /**
    * @var EntityRepository
    */
    private EntityRepository $productRepository;

    /**
    * @var CartService
    */
    private CartService $cartService;

    /**
    * @var LineItemFactoryRegistry
    */
    private LineItemFactoryRegistry $lineItemFactoryRegistry;

    /**
    * @var QuantityPriceCalculator
    */
    private QuantityPriceCalculator $quantityPriceCalculator;


    /**
     * @param SubscriptionPageLoader $pageLoader
     * @param SubscriptionManager $subscriptionManager
     * @param LoggerInterface $logger
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository $orderRepository
     * @param EntityRepository $productRepository
     * @param CartService $cartService
     * @param LineItemFactoryRegistry $lineItemFactoryRegistry
     * @param QuantityPriceCalculator $quantityPriceCalculator
     */
    public function __construct(
        SubscriptionPageLoader $pageLoader,
        SubscriptionManager $subscriptionManager,
        LoggerInterface $logger,
        SystemConfigService $systemConfigService,
        EntityRepository $orderRepository,
        EntityRepository $productRepository,
        CartService $cartService,
        LineItemFactoryRegistry $lineItemFactoryRegistry,
        QuantityPriceCalculator $quantityPriceCalculator,
    )
    {
        $this->pageLoader = $pageLoader;
        $this->subscriptionManager = $subscriptionManager;
        $this->logger = $logger;
        $this->systemConfigService = $systemConfigService;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->cartService = $cartService;
        $this->lineItemFactoryRegistry = $lineItemFactoryRegistry;
        $this->quantityPriceCalculator = $quantityPriceCalculator;
    }

    public function residualPurchase(string $subscriptionId, Request $request, SalesChannelContext $salesChannelContext, Cart $cart): Response
    {
        if (!$this->isLoggedIn($salesChannelContext) ||
            !$this->systemConfigService->get("OsSubscriptions.config.residualPurchaseActive")) {
            return $this->redirectToLoginPage();
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
                # TODO: handle error snippet key
                return $this->routeToErrorPage(
                    'molliePayments.subscriptions.account.errorUpdateAddress',
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

                $nonRentableLineItem = $this->getRelatedLineItemByOrderLineEntity($orderLineItem, $salesChannelContext);
                $this->cartService->add($cart, $nonRentableLineItem, $salesChannelContext);
            }

            $residualDiscountLineItem = $this->getResidualDiscountLineItem($orders, $salesChannelContext);
            $this->cartService->add($cart, $residualDiscountLineItem, $salesChannelContext);

            # $this->cartService->recalculate($cart, $salesChannelContext);

            return $this->redirectToRoute('frontend.checkout.confirm.page');

        } catch (\Throwable $exception) {
            # TODO: handle error snippet key
            return $this->routeToErrorPage(
                'molliePayments.subscriptions.account.errorUpdateAddress',
                'Error when updating billing address of subscription ' . $subscriptionId . ': ' . $exception->getMessage()
            );
        }
    }

    public function cancelSubscription(string $subscriptionId, Request $request, SalesChannelContext $salesChannelContext)
    {
        $this->subscriptionManager->cancelSubscription($subscriptionId, $salesChannelContext->getContext());

        $redirectUrl = $this->generateUrl(
            'frontend.account.mollie.subscriptions.payment.update-success',
            [
                'swSubscriptionId' => $subscriptionId
            ],
            UrlGenerator::ABSOLUTE_URL
        );

        // $checkoutUrl = $this->subscriptionManager->updatePaymentMethodStart($subscriptionId, $redirectUrl, $salesChannelContext->getContext());
    }

    private function getResidualDiscountLineItem(EntitySearchResult $orders, SalesChannelContext $salesChannelContext): LineItem
    {
        $discountLineItem = new LineItem('residual-discount', 'rent_residual_discount', null, 1);

        $discountLineItem->setLabel('Restkauf Rabatt auf Abonnement');
        $discountLineItem->setGood(false);
        $discountLineItem->setStackable(false);
        $discountLineItem->setRemovable(false);

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
     * @return LineItem
     */
    private function getRelatedLineItemByOrderLineEntity(OrderLineItemEntity $orderLineItemEntity, SalesChannelContext $salesChannelContext): LineItem
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
        ], $salesChannelContext);
    }


    /**
     * @param string $errorSnippetKey
     * @param string $logMessage
     * @return RedirectResponse
     */
    private function routeToErrorPage(string $errorSnippetKey, string $logMessage): RedirectResponse
    {
        $this->logger->error($logMessage);

        $this->addFlash(self::DANGER, $this->trans($errorSnippetKey));

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
