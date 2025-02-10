<?php declare(strict_types=1);

namespace OsSubscriptions\Controller\Storefront\Account;

use Kiener\MolliePayments\Components\Subscription\Page\Account\SubscriptionPageLoader;
use Kiener\MolliePayments\Components\Subscription\SubscriptionManager;
use Kiener\MolliePayments\Controller\Storefront\AbstractStoreFrontController;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Order\Transformer\LineItemTransformer;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
     * @param SubscriptionPageLoader $pageLoader
     * @param SubscriptionManager $subscriptionManager
     * @param LoggerInterface $logger
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository $orderRepository
     */
    public function __construct(
        SubscriptionPageLoader $pageLoader,
        SubscriptionManager $subscriptionManager,
        LoggerInterface $logger,
        SystemConfigService $systemConfigService,
        EntityRepository $orderRepository)
    {
        $this->pageLoader = $pageLoader;
        $this->subscriptionManager = $subscriptionManager;
        $this->logger = $logger;
        $this->systemConfigService = $systemConfigService;
        $this->orderRepository = $orderRepository;
    }

    public function residualPurchase(string $subscriptionId, Request $request, SalesChannelContext $salesChannelContext): Response
    {
        if (!$this->isLoggedIn($salesChannelContext) ||
            !$this->systemConfigService->get("OsSubscriptions.config.residualPurchaseActive")) {
            return $this->redirectToLoginPage();
        }

        try {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('customFields.mollie_payments.swSubscriptionId', $subscriptionId));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('deliveries');
            $order = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();

            if ($order->getLineItems() === null) {
                throw OrderException::missingAssociation('lineItems.product');
            }

            if ($order->getDeliveries() === null) {
                throw OrderException::missingAssociation('deliveries');
            }

            $cart = new Cart(Uuid::randomHex());
            # $cart->setPrice($order->getPrice());
            $cart->setCustomerComment($order->getCustomerComment());
            $cart->setAffiliateCode($order->getAffiliateCode());
            $cart->setCampaignCode($order->getCampaignCode());
            $cart->setSource($order->getSource());
            # $cart->addExtension(self::ORIGINAL_ID, new IdStruct($order->getId()));
            #$cart->addExtension(self::ORIGINAL_ORDER_NUMBER, new IdStruct($orderNumber));
            /* NEXT-708 support:
                - transactions
            */

            foreach($order->getLineItems() as $lineItem) {

            }

            $lineItems = LineItemTransformer::transformFlatToNested($order->getLineItems());

            $cart->addLineItems($lineItems);
//            $cart->setDeliveries(
//                $this->convertDeliveries($order->getDeliveries(), $lineItems)
//            );


            return $this->redirectToRoute('frontend.checkout.confirm.page');

        } catch (\Throwable $exception) {

            # TODO: HANDLE AND DISPLAY CORRECTLY
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
