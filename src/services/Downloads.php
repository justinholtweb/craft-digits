<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\elements\db\DownloadQuery;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\fields\DownloadsField;
use Throwable;

/**
 * Finding downloads, and finding the downloads attached to something else.
 *
 * The second half is the whole Commerce bridge, and it is two questions deep on purpose:
 *
 * 1. **Is there a Downloads field on this thing?** The intended way. A shop puts the field on its
 *    product or variant field layout, relates the files, and everything downstream follows the
 *    relation. It survives renames, it shows in the editor, and it is the same field a plain entry
 *    can use for a gated PDF with no Commerce anywhere.
 *
 * 2. **Does anything have this SKU?** The fallback, for the shop whose product field layout is
 *    owned by somebody else, or whose catalogue is imported nightly by a script that knows about
 *    SKUs and nothing else.
 *
 * A variant that answers neither sells nothing, which is a perfectly ordinary thing for a physical
 * product to do.
 */
class Downloads extends Component
{
    public function getDownloadById(int $id): ?Download
    {
        /** @var DownloadQuery $query */
        $query = Download::find();

        return $query->id($id)->status(null)->one();
    }

    public function getDownloadBySku(string $sku): ?Download
    {
        if (trim($sku) === '') {
            return null;
        }

        /** @var DownloadQuery $query */
        $query = Download::find();

        return $query->sku($sku)->status(null)->one();
    }

    /** @return Download[] */
    public function getAllDownloads(): array
    {
        /** @var DownloadQuery $query */
        $query = Download::find();

        return $query->status(null)->all();
    }

    /**
     * The downloads attached to any element — a variant, a product, an entry.
     *
     * Relations first, SKU second, and never both for the same source: a shop that has done the
     * relation properly should not have a stray matching SKU quietly add a fourth file to the
     * order.
     *
     * @return Download[]
     */
    public function getDownloadsFor(mixed $element, ?string $sku = null): array
    {
        $downloads = [];

        if ($element !== null) {
            $downloads = $this->getRelatedDownloads($element);
        }

        if ($downloads === [] && $sku !== null) {
            $download = $this->getDownloadBySku($sku);

            if ($download !== null) {
                $downloads = [$download];
            }
        }

        return $downloads;
    }

    /**
     * Downloads related through a Downloads field on the element's own layout.
     *
     * Wrapped, because this runs during checkout on sites where the element may be anything at all
     * — including something whose field layout is mid-migration. An exception here would fail an
     * order that has already been paid for, so a broken layout costs the customer their files and
     * a line in the log, not their money.
     *
     * @return Download[]
     */
    private function getRelatedDownloads(mixed $element): array
    {
        try {
            if (!$element instanceof \craft\base\ElementInterface) {
                return [];
            }

            $layout = $element->getFieldLayout();

            if ($layout === null) {
                return [];
            }

            $downloads = [];

            foreach ($layout->getCustomFields() as $field) {
                if (!$field instanceof DownloadsField) {
                    continue;
                }

                $value = $element->getFieldValue($field->handle);

                if ($value instanceof \craft\elements\db\ElementQueryInterface || $value instanceof \craft\elements\ElementCollection) {
                    foreach ($value->all() as $download) {
                        if ($download instanceof Download) {
                            $downloads[(int)$download->id] = $download;
                        }
                    }
                }
            }

            return array_values($downloads);
        } catch (Throwable $e) {
            Craft::warning('Could not read the downloads related to an element: ' . $e->getMessage(), \justinholtweb\digits\Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * Whether any Downloads field exists at all.
     *
     * Asked by the settings screen so that a shop which has installed Digits and wired nothing up
     * is told so, rather than left wondering why no licences are appearing.
     */
    public function hasAnyDownloadsField(): bool
    {
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof DownloadsField) {
                return true;
            }
        }

        return false;
    }

    /** How many licences each download has, keyed by download id — one query for the whole index. */
    public function getLicenseCounts(): array
    {
        $rows = (new Query())
            ->select(['downloadId', 'total' => 'COUNT(*)'])
            ->from([Table::LICENSE_DOWNLOADS])
            ->groupBy(['downloadId'])
            ->all();

        return array_map('intval', array_column($rows, 'total', 'downloadId'));
    }
}
