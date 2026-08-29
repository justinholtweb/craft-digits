<?php

declare(strict_types=1);

namespace justinholtweb\digits\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\digits\elements\Download;
use justinholtweb\digits\Plugin;

/**
 * One release of a download.
 *
 * A version, not a file: a release of a desktop app is a Windows build and a macOS build and a
 * checksum file, and all three are the same release. The files hang off this.
 *
 * `dateReleased` is the load-bearing column. It is what "your licence covers updates until March"
 * is measured against, so a version with no release date is a version nobody can be told whether
 * they are entitled to — which is why publishing sets it and drafts do not have one.
 */
class Version extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_LIVE = 'live';

    public ?int $id = null;
    public ?int $downloadId = null;
    public string $version = '';
    public ?string $releaseNotes = null;
    public bool $isCurrent = false;
    public string $status = self::STATUS_DRAFT;
    public ?DateTime $dateReleased = null;
    public ?DateTime $dateNotified = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var DownloadFile[]|null */
    private ?array $_files = null;

    private ?Download $_download = null;

    /**
     * Columns Craft has to hydrate as dates rather than as strings.
     *
     * Missing one is not a type mismatch you notice — the model loads fine and every date
     * comparison against it silently becomes a string comparison.
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateReleased', 'dateNotified']);
    }

    public function defineRules(): array
    {
        return [
            [['downloadId', 'version'], 'required'],
            [['version'], 'string', 'max' => 64],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_LIVE]],
            [['version'], 'validateUniqueVersion'],
            // `skipOnEmpty => false`: a released version with no files is the case this rule is
            // for, and an empty array is exactly what Yii would otherwise skip.
            [['files'], 'validateFiles', 'skipOnEmpty' => false],
            [['id', 'sortOrder', 'isCurrent', 'releaseNotes', 'dateReleased', 'dateNotified', 'uid'], 'safe'],
        ];
    }

    public function validateUniqueVersion(): void
    {
        if ($this->downloadId === null || $this->version === '') {
            return;
        }

        $existing = Plugin::getInstance()->versions->getVersionByNumber((int)$this->downloadId, $this->version);

        if ($existing !== null && $existing->id !== $this->id) {
            $this->addError('version', Craft::t('digits', 'This download already has a version {version}.', [
                'version' => $this->version,
            ]));
        }
    }

    /**
     * A live version with nothing to hand over is worse than no version at all — it is a download
     * button that produces a 404 for a paying customer.
     */
    public function validateFiles(): void
    {
        if ($this->status === self::STATUS_LIVE && $this->getFiles() === []) {
            $this->addError('files', Craft::t('digits', 'A released version needs at least one file.'));
        }

        foreach ($this->getFiles() as $i => $file) {
            if (!$file->validate()) {
                foreach ($file->getFirstErrors() as $error) {
                    $this->addError('files', Craft::t('digits', 'File {number}: {error}', ['number' => $i + 1, 'error' => $error]));
                }
            }
        }
    }

    public function getDownload(): ?Download
    {
        if ($this->_download === null && $this->downloadId !== null) {
            $this->_download = Download::find()->id($this->downloadId)->status(null)->one();
        }

        return $this->_download;
    }

    /** @return DownloadFile[] */
    public function getFiles(): array
    {
        if ($this->_files === null) {
            $this->_files = $this->id !== null
                ? Plugin::getInstance()->versions->getFilesForVersion((int)$this->id)
                : [];
        }

        return $this->_files;
    }

    /** @param DownloadFile[]|array[] $files */
    public function setFiles(array $files): void
    {
        $this->_files = array_values(array_map(
            static fn($file) => $file instanceof DownloadFile ? $file : new DownloadFile($file),
            $files,
        ));
    }

    public function getFileById(int $id): ?DownloadFile
    {
        foreach ($this->getFiles() as $file) {
            if ((int)$file->id === $id) {
                return $file;
            }
        }

        return null;
    }

    public function getIsLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function getIsNotified(): bool
    {
        return $this->dateNotified !== null;
    }

    /** The total weight of the release, where it is known. */
    public function getSize(): int
    {
        $size = 0;

        foreach ($this->getFiles() as $file) {
            $size += $file->getSize() ?? 0;
        }

        return $size;
    }

    public function getSizeLabel(): string
    {
        $size = $this->getSize();

        return $size > 0 ? Craft::$app->getFormatter()->asShortSize($size, 1) : '';
    }

    public function getLabel(): string
    {
        return $this->version !== '' ? $this->version : Craft::t('digits', 'Untitled version');
    }

    /**
     * Sortable form of a version string.
     *
     * Not semver parsing — a shop is entitled to call its releases `2026-08` or `Spring` — but
     * every numeric run padded so `10` sorts after `9` and the common case comes out right.
     */
    public function getSortKey(): string
    {
        $parts = preg_split('/([0-9]+)/', mb_strtolower($this->version), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(
            static fn(string $part) => ctype_digit($part) ? str_pad($part, 10, '0', STR_PAD_LEFT) : $part,
            $parts,
        ));
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'isCurrent' => $this->isCurrent,
            'releaseNotes' => $this->releaseNotes,
            'dateReleased' => $this->dateReleased?->format(DATE_ATOM),
            'size' => $this->getSize(),
            'files' => array_map(static fn(DownloadFile $file) => $file->toArray(), $this->getFiles()),
        ];
    }

    public function getUid(): string
    {
        return $this->uid ??= StringHelper::UUID();
    }
}
