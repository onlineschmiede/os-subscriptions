<?php declare(strict_types=1);

namespace OsSubscriptions\Service;

use Shopware\Core\Content\Product\Stock\AbstractStockStorage;
use Shopware\Core\Content\Product\Stock\StockDataCollection;
use Shopware\Core\Content\Product\Stock\StockLoadRequest;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Content\Product\Stock\StockAlteration;

class StockStorageDecorator extends AbstractStockStorage
{
    private AbstractStockStorage $decorated;
    private EntityRepository $orderLineItemRepository;
    private EntityRepository $orderRepository;

    public function __construct(AbstractStockStorage $decorated, EntityRepository $orderRepository, EntityRepository $orderLineItemRepository)
    {
        $this->decorated = $decorated;
        $this->orderRepository = $orderRepository;
        $this->orderLineItemRepository = $orderLineItemRepository;
    }

    public function getDecorated(): AbstractStockStorage
    {
        return $this->decorated;
    }

    public function load(StockLoadRequest $stockRequest, SalesChannelContext $context): StockDataCollection
    {
        return $this->decorated->load($stockRequest, $context);
    }

    /**
     * @param list<StockAlteration> $changes
     */
    public function alter(array $changes, Context $context): void
    {
        $changes = $this->applyStockAlterationChangesConditionally($changes, $context);
        $this->decorated->alter($changes, $context);
    }

    public function index(array $productIds, Context $context): void
    {
        $this->decorated->index($productIds, $context);
    }

    /**
     * @param list<StockAlteration> $changes
     * @returns list<StockAlteration> $changes
     */
    private function applyStockAlterationChangesConditionally(array $changes, Context $context)
    {
        $newChanges = $changes;
        $lineItemIds = array_map(fn($change) => bin2hex($change->lineItemId), $changes);
        $criteria = new Criteria($lineItemIds);
        $criteria->addAssociation('order.customFields');
        $lineItems = $this->orderLineItemRepository->search($criteria, $context);

        foreach ($newChanges as $newChange)
        {
            $relatedLineItem = $lineItems->getEntities()->get(bin2hex($newChange->lineItemId));
            $relatedOrder = $relatedLineItem->getOrder();
        }

       return $newChanges;
    }
}