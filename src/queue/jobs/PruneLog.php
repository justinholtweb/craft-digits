<?php

declare(strict_types=1);

namespace justinholtweb\digits\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\digits\Plugin;

/**
 * Delete download log rows past their retention date.
 *
 * Queued rather than run inline during garbage collection, because a shop that has never pruned can
 * have millions of rows and nobody's page load should pay for that.
 */
class PruneLog extends BaseJob
{
    public ?int $days = null;

    public function execute($queue): void
    {
        Plugin::getInstance()->log->prune($this->days);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('digits', 'Pruning the Digits download log');
    }
}
