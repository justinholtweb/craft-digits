<?php

declare(strict_types=1);

namespace justinholtweb\digits\fields;

use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\fields\BaseRelationField;
use justinholtweb\digits\elements\Download;

/**
 * A relation field for downloads.
 *
 * This is the whole attachment mechanism. Put it on a Commerce variant's field layout and the
 * variant sells those files; put it on an entry and the entry gates them; put it on a category and
 * everything in the category shares a manual. Digits never asks "what kind of thing is this" — it
 * asks "what downloads are related to it", and the answer comes from Craft's own relation plumbing
 * with its eager loading, its selection modal and its per-site relations already in place.
 *
 * Which is why this file is short, and why it is the *first* place the Commerce bridge looks.
 */
class DownloadsField extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('digits', 'Downloads');
    }

    public static function icon(): string
    {
        return 'download';
    }

    public static function elementType(): string
    {
        return Download::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('digits', 'Add a download');
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|\\%s<\\%s>', ElementQueryInterface::class, ElementCollection::class, Download::class);
    }
}
