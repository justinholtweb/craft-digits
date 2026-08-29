<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\elements\License;
use justinholtweb\digits\models\Settings;
use justinholtweb\digits\Plugin;
use Throwable;

/**
 * The Commerce bridge.
 *
 * Everything that knows an order from a licence is in this one file, and that is the point:
 * Digits runs on sites with no Commerce at all, so the rest of the plugin must never mention it.
 * Delete this class and downloads, licences, links, limits and the portal all still work — what
 * stops working is buying one.
 *
 * ## When a licence appears
 *
 * On order completion by default. A shop that only wants to hand over files once the money has
 * actually arrived sets `issueOn` to `paid`, and then the licence still appears at completion but
 * as **pending** — visible to the customer, listed in their portal, and refusing to download until
 * payment lands. That is deliberately not the same as issuing nothing: a customer who has paid by
 * bank transfer and can see "waiting for payment" does not email support, and one who sees an empty
 * account does.
 *
 * ## Quantity
 *
 * One licence per line item, whatever the quantity, with the seats multiplied. Buying three of a
 * one-machine licence gives one key good for three machines, because that is what somebody buying
 * three of something for one company means. A shop selling gift copies flips
 * `licensePerQuantity` and gets three separate keys instead.
 *
 * ## Refunds
 *
 * Commerce's refund transactions are not line-scoped — a partial refund knows an amount and not
 * which product it was for — so Digits cannot honestly revoke "the refunded item". It offers the
 * two answers it can defend: revoke everything on the order when it is refunded **in full**
 * (the default), or revoke on **any** refund. It does not guess in between.
 */
class Orders extends Component
{
    /**
     * An order has completed.
     *
     * Wrapped, and this is not defensive habit. This runs inside Commerce's completion, after the
     * customer's money has moved; an exception escaping here would fail the completion of an order
     * that has already been paid for. A shop would rather have a paid order with a missing licence
     * — which support can issue by hand in ten seconds from the licence index — than a customer
     * charged for an order Commerce says never completed.
     *
     * @return License[]
     */
    public function handleOrderComplete(Order $order): array
    {
        try {
            return $this->issueForOrder($order);
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not issue licences for order %s: %s', $order->reference ?: $order->id, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            return [];
        }
    }

    /**
     * Create the licences an order earns.
     *
     * Idempotent on the line item: a line that already has a licence is skipped, so a re-run — a
     * retried webhook, a console repair, an event that fired twice — repairs rather than
     * duplicates.
     *
     * @return License[]
     */
    public function issueForOrder(Order $order, bool $force = false): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $existing = $force ? [] : $this->existingLineItemIds((int)$order->id);
        $issued = [];

        foreach ($order->getLineItems() as $lineItem) {
            if (in_array((int)$lineItem->id, $existing, true)) {
                continue;
            }

            $downloads = $this->downloadsForLineItem($lineItem);

            if ($downloads === []) {
                continue;
            }

            $downloadIds = array_map(static fn(Download $download) => (int)$download->id, $downloads);
            $quantity = max(1, (int)$lineItem->qty);
            $copies = $settings->licensePerQuantity ? $quantity : 1;
            $seatMultiplier = $settings->licensePerQuantity ? 1 : $quantity;

            for ($copy = 0; $copy < $copies; $copy++) {
                $license = $plugin->licenses->issue([
                    'downloadIds' => $downloadIds,
                    'email' => $order->getEmail(),
                    'customerName' => $this->customerName($order),
                    'ownerId' => $order->getCustomer()?->id,
                    'orderId' => (int)$order->id,
                    'lineItemId' => (int)$lineItem->id,
                    'orderReference' => $order->reference ?: $order->number,
                    'source' => License::SOURCE_ORDER,
                    'status' => $this->initialStatus($order),
                    'activationLimit' => $this->seatsFor($downloads, $seatMultiplier),
                ]);

                if ($license !== null) {
                    $issued[] = $license;
                }
            }
        }

        if ($issued !== [] && $settings->notifyOnIssue) {
            $plugin->notifications->sendLicenseIssued($order, $issued);
        }

        return $issued;
    }

    /**
     * Payment arrived.
     *
     * Turns the pending licences active, and raises the invoice if the shop waits for payment
     * before invoicing. Both are idempotent, because this event fires again on any subsequent
     * payment against the same order.
     */
    public function handleOrderPaid(Order $order): void
    {
        try {
            $plugin = Plugin::getInstance();
            $activated = 0;

            foreach ($plugin->licenses->getLicensesForOrder((int)$order->id) as $license) {
                if ($license->licenseStatus !== License::STATUS_PENDING) {
                    continue;
                }

                $license->licenseStatus = License::STATUS_ACTIVE;

                if (Craft::$app->getElements()->saveElement($license, false)) {
                    $activated++;
                }
            }

            if ($activated > 0 && $plugin->getSettings()->notifyOnIssue) {
                $plugin->notifications->sendLicenseIssued($order, $plugin->licenses->getLicensesForOrder((int)$order->id));
            }

            $this->maybeInvoice($order);
        } catch (Throwable $e) {
            Craft::error('Could not process payment for Digits: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * A refund was taken.
     *
     * Only successful refund transactions count. A pending or failed one is not money going back,
     * and revoking on it would take a customer's files away over a card network's retry.
     */
    public function handleTransaction(Transaction $transaction): void
    {
        if ($transaction->type !== TransactionRecord::TYPE_REFUND
            || $transaction->status !== TransactionRecord::STATUS_SUCCESS) {
            return;
        }

        $policy = Plugin::getInstance()->getSettings()->revokeOnRefund;

        if ($policy === Settings::REVOKE_NEVER) {
            return;
        }

        try {
            $order = $transaction->getOrder();

            if ($order === null) {
                return;
            }

            if ($policy === Settings::REVOKE_FULL && !$this->isFullyRefunded($order)) {
                return;
            }

            $this->revokeForOrder($order, Craft::t('digits', 'Order refunded'));
        } catch (Throwable $e) {
            Craft::error('Could not revoke licences after a refund: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /** An order reached a status the shop treats as "this sale is off". */
    public function handleStatusChange(Order $order): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = $order->getOrderStatus()?->handle;

        if ($handle === null || $settings->revokeOnStatuses === []) {
            return;
        }

        if (in_array($handle, $settings->revokeOnStatuses, true)) {
            $this->revokeForOrder($order, Craft::t('digits', 'Order marked “{status}”', ['status' => $handle]));
        }
    }

    /**
     * Withdraw everything an order granted.
     *
     * Each licence goes through {@see Licenses::revoke()}, which also deletes the unspent links —
     * the entire reason a download link is a database row rather than a signature. A refunded
     * customer's emailed link stops working immediately, not when it expires.
     */
    public function revokeForOrder(Order $order, ?string $reason = null): int
    {
        $plugin = Plugin::getInstance();
        $revoked = 0;

        foreach ($plugin->licenses->getLicensesForOrder((int)$order->id) as $license) {
            if ($license->getIsRevoked()) {
                continue;
            }

            if ($plugin->licenses->revoke($license, $reason)) {
                $revoked++;
            }
        }

        $plugin->links->revokeForOrder((int)$order->id);

        return $revoked;
    }

    /** Raise the invoice, if the shop is invoicing and the moment has arrived. */
    public function maybeInvoice(Order $order): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enableInvoicing) {
            return;
        }

        if ($settings->issueOn === Settings::ISSUE_ON_PAID && !$order->getIsPaid()) {
            return;
        }

        try {
            Plugin::getInstance()->invoices->issueForOrder($order);
        } catch (Throwable $e) {
            Craft::error('Could not raise an invoice: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * The downloads a line item sells.
     *
     * The purchasable first, then the line item's own SKU. A variant with a Downloads field wins;
     * a shop with no field on its product layout still gets the SKU match.
     *
     * @return Download[]
     */
    public function downloadsForLineItem(mixed $lineItem): array
    {
        $purchasable = null;

        try {
            $purchasable = $lineItem->getPurchasable();
        } catch (Throwable) {
            // A line item whose product has since been deleted. The SKU is still on the line item
            // itself, which is exactly the case the SKU fallback is for.
        }

        $downloads = Plugin::getInstance()->downloads->getDownloadsFor($purchasable, $lineItem->getSku());

        return array_values(array_filter(
            $downloads,
            static fn(Download $download) => $download->issuesLicense,
        ));
    }

    /** Whether the money has all gone back. */
    public function isFullyRefunded(Order $order): bool
    {
        $refunded = 0.0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === TransactionRecord::TYPE_REFUND
                && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
                $refunded += (float)$transaction->amount;
            }
        }

        // A hundredth of a currency unit of slack: a gateway that refunds in a different currency
        // and converts back can land a fraction short, and a customer who has had all their money
        // returned should not keep their files over a rounding error.
        return $refunded > 0 && $refunded >= ((float)$order->getTotalPrice() - 0.01);
    }

    private function initialStatus(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->issueOn === Settings::ISSUE_ON_PAID && !$order->getIsPaid()) {
            return License::STATUS_PENDING;
        }

        return License::STATUS_ACTIVE;
    }

    /**
     * Seats for a licence covering these downloads.
     *
     * Null — inherit — unless quantity is doing something, because a stored limit that happens to
     * equal today's default is a limit that stops following the default tomorrow.
     */
    private function seatsFor(array $downloads, int $multiplier): ?int
    {
        if ($multiplier <= 1) {
            return null;
        }

        $limits = array_map(
            static fn(Download $download) => $download->getEffectiveActivationLimit(),
            $downloads,
        );

        $limits = array_filter($limits, static fn(int $limit) => $limit > 0);

        // One unlimited part makes the whole licence unlimited, and multiplying unlimited is
        // meaningless.
        return $limits === [] ? 0 : min($limits) * $multiplier;
    }

    private function customerName(Order $order): ?string
    {
        $billing = $order->getBillingAddress();

        if ($billing === null) {
            return null;
        }

        $name = trim(sprintf('%s %s', $billing->firstName ?? '', $billing->lastName ?? ''));

        return $name !== '' ? $name : ($billing->fullName ?: null);
    }

    /** @return int[] */
    private function existingLineItemIds(int $orderId): array
    {
        $ids = [];

        foreach (Plugin::getInstance()->licenses->getLicensesForOrder($orderId) as $license) {
            if ($license->lineItemId !== null) {
                $ids[] = (int)$license->lineItemId;
            }
        }

        return $ids;
    }
}
