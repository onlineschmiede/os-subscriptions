<?php declare(strict_types=1);

namespace OsSubscriptions;

use Shopware\Core\Framework\Plugin;

class OsSubscriptions extends Plugin
{
    public function getTemplatePriority(): int
    {
        # as we don't want to modify the theme.json with template priority
        # we will assume that all other plugins have a lower priority than this one,
        # as modification to customer dashboard are depending on MolliePayments,
        # which has to be loaded beforehand, so we can inherit from MolliePayments.
        return 999;
    }
}