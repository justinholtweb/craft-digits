<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use DateTime;

/**
 * One file inside a version.
 *
 * Either an asset or a URL, never both. An asset is the case Digits can actually protect — it can
 * live outside the web root and be handed over a request at a time. A URL is the escape hatch for
 * the 4 GB ISO nobody wants inside Craft's volumes, and Digits is honest about what it can promise
 * there: it will still check entitlement and still count the download, but the redirect it hands
 * over is only as private as the URL behind it.
 */
class DownloadFile extends Model
{
    public ?int $id = null;
    public ?int $versionId = null;
    public string $name = '';
    public ?int $assetId = null;
    public ?string $url = null;
    public ?string $filename = null;
    public ?int $size = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Asset $_asset = null;
    private bool $_assetLoaded = false;

    public function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['name', 'filename'], 'string', 'max' => 255],
            [['url'], 'url', 'defaultScheme' => 'https'],
            [['url'], 'string', 'max' => 500],
            [['assetId'], 'validateSource'],
            [['id', 'versionId', 'size', 'sortOrder', 'uid'], 'safe'],
        ];
    }

    public function validateSource(): void
    {
        if ($this->assetId === null && ($this->url === null || trim($this->url) === '')) {
            $this->addError('assetId', Craft::t('digits', 'Choose a file or give a URL.'));
        }

        if ($this->assetId !== null && $this->url !== null && trim($this->url) !== '') {
            $this->addError('assetId', Craft::t('digits', 'A file is either an asset or a URL, not both.'));
        }
    }

    public function getIsExternal(): bool
    {
        return $this->assetId === null && $this->url !== null && trim($this->url) !== '';
    }

    public function getAsset(): ?Asset
    {
        if (!$this->_assetLoaded) {
            $this->_assetLoaded = true;
            $this->_asset = $this->assetId !== null
                ? Craft::$app->getAssets()->getAssetById($this->assetId)
                : null;
        }

        return $this->_asset;
    }

    /** What the customer's browser should call it when it lands. */
    public function getDeliveredFilename(): string
    {
        if ($this->filename !== null && trim($this->filename) !== '') {
            return trim($this->filename);
        }

        $asset = $this->getAsset();

        if ($asset !== null) {
            return $asset->getFilename();
        }

        $path = parse_url((string)$this->url, PHP_URL_PATH);
        $basename = $path !== null ? basename($path) : '';

        return $basename !== '' ? rawurldecode($basename) : 'download';
    }

    /** Bytes, where they are knowable. An external URL is only as knowable as somebody typed in. */
    public function getSize(): ?int
    {
        $asset = $this->getAsset();

        if ($asset !== null) {
            return (int)$asset->size;
        }

        return $this->size;
    }

    public function getSizeLabel(): string
    {
        $size = $this->getSize();

        return $size ? Craft::$app->getFormatter()->asShortSize($size, 1) : '';
    }

    /** Whether the bytes are actually there. A file row pointing at a deleted asset is a 404 waiting to happen. */
    public function getIsAvailable(): bool
    {
        return $this->getIsExternal() || $this->getAsset() !== null;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'filename' => $this->getDeliveredFilename(),
            'size' => $this->getSize(),
            'external' => $this->getIsExternal(),
        ];
    }
}
