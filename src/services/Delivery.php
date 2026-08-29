<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use Craft;
use craft\base\Component;
use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\web\Response;
use justinholtweb\digits\models\DownloadFile;
use justinholtweb\digits\models\Settings;
use justinholtweb\digits\Plugin;
use Throwable;

/**
 * Getting the bytes to the customer.
 *
 * Everything that decided *whether* has already happened by the time anything here runs. This
 * class is only about doing it correctly, and correctly means four things a naïve
 * `readfile()` gets wrong:
 *
 * - **Range requests.** A 900 MB download is resumed, paused, and fetched by download managers
 *   that ask for byte ranges. Yii's own file sending implements `Range` completely — 206,
 *   `Content-Range`, and a 416 for a range that cannot be met. Doing it by hand produces a 200 with
 *   a truncated body, which every client treats as a corrupt file.
 *
 * - **Remote volumes.** A stream from S3 is not reliably seekable, and answering a range request by
 *   seeking a stream that cannot seek sends the *wrong bytes* under a 206 — silent corruption,
 *   which is worse than being slow. So a ranged request against a remote volume takes a local copy
 *   first, and everything else streams straight through.
 *
 * - **Cache headers.** A protected file that a shared proxy is allowed to cache is a protected file
 *   that will eventually be served to somebody who was refused.
 *
 * - **Inline display.** An HTML or SVG file served inline from the site's own origin is stored XSS,
 *   and "the shop uploaded it" is not a defence when Digits is the thing that gave it a URL.
 */
class Delivery extends Component
{
    /** Extensions that never render inline, whatever the settings say. */
    private const NEVER_INLINE = ['html', 'htm', 'svg', 'xml', 'xhtml', 'js', 'mjs', 'swf'];

    /**
     * Send a file.
     *
     * An external file is a redirect, and Digits says so plainly rather than pretending: it has
     * still checked the entitlement and still counted the download, and what happens to the URL
     * after that is the host's business.
     */
    public function send(DownloadFile $file, Response $response): Response
    {
        $this->setCommonHeaders($response);

        if ($file->getIsExternal()) {
            return $response->redirect($file->url, 302);
        }

        $asset = $file->getAsset();

        if ($asset === null) {
            throw new \yii\web\NotFoundHttpException(Craft::t('digits', 'This file is not available at the moment.'));
        }

        $settings = Plugin::getInstance()->getSettings();
        $filename = $file->getDeliveredFilename();
        $mimeType = $this->mimeType($asset);
        $inline = !$settings->forceDownload && $this->allowsInline($filename);
        $path = $this->localPath($asset);
        $wantsRange = Craft::$app->getRequest()->getHeaders()->get('Range') !== null;

        if ($path === null && $wantsRange) {
            $path = $this->localCopy($asset);
        }

        if ($path !== null) {
            if ($this->handOff($response, $path, $mimeType, $filename, $inline)) {
                return $response;
            }

            $response->getHeaders()->set('Accept-Ranges', 'bytes');

            return $response->sendFile($path, $filename, [
                'mimeType' => $mimeType,
                'inline' => $inline,
            ]);
        }

        return $response->sendStreamAsFile($asset->getStream(), $filename, [
            'mimeType' => $mimeType,
            'inline' => $inline,
            'fileSize' => $asset->size,
        ]);
    }

    /**
     * A readable local path for the asset, or null when it lives on a remote filesystem.
     *
     * Callers have to handle both. Assuming a path exists is what makes a downloads plugin work
     * perfectly until somebody moves a volume to S3.
     */
    public function localPath(Asset $asset): ?string
    {
        try {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();

            if (!$fs instanceof LocalFsInterface) {
                return null;
            }

            $path = FileHelper::normalizePath(
                $fs->getRootPath() . DIRECTORY_SEPARATOR . $volume->getSubpath() . $asset->getPath(),
            );

            return is_file($path) ? $path : null;
        } catch (Throwable $e) {
            Craft::warning("Could not resolve a local path for asset {$asset->id}: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * A local copy of a remote asset's file.
     *
     * Only used for a ranged request against a remote volume — see the note at the top. Craft
     * cleans these out of its temp directory during garbage collection, so nothing here has to.
     */
    public function localCopy(Asset $asset): ?string
    {
        try {
            $path = $asset->getCopyOfFile();
        } catch (Throwable $e) {
            Craft::warning("Could not copy asset {$asset->id} locally: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        }

        return is_file($path) ? $path : null;
    }

    public function mimeType(Asset $asset): string
    {
        try {
            return $asset->getMimeType() ?: 'application/octet-stream';
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }

    public function allowsInline(string $filename): bool
    {
        return !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::NEVER_INLINE, true);
    }

    /**
     * Headers every delivery gets.
     *
     * `private` and `no-store` matter more here than anywhere else in the plugin: the whole
     * apparatus of licences and limits is undone by one CDN that decided a 200 with a
     * `Content-Disposition` was cacheable.
     */
    public function setCommonHeaders(Response $response): void
    {
        $headers = $response->getHeaders();

        $headers->set('Cache-Control', 'private, max-age=0, no-store, must-revalidate');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * Hand the file to the web server instead of pushing it through PHP.
     *
     * Only ever an optimisation, and only when the path maps to a location the server has been told
     * about. If nothing matches this returns false and PHP does the work — silently emitting an
     * `X-Accel-Redirect` the server does not understand sends the customer an empty 200, which is
     * the worst possible failure for a download because it looks like success.
     */
    private function handOff(Response $response, string $path, string $mimeType, string $filename, bool $inline): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $method = $settings->fileDeliveryMethod;

        if ($method === Settings::DELIVERY_PHP) {
            return false;
        }

        $internal = null;

        foreach ($settings->getInternalPathMap() as $root => $location) {
            $root = FileHelper::normalizePath($root);

            if (str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                $internal = $location . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                break;
            }
        }

        if ($internal === null) {
            return false;
        }

        $headers = $response->getHeaders();
        $headers->set('Content-Type', $mimeType);
        $headers->set('Content-Disposition', $this->contentDisposition($filename, $inline));

        if ($method === Settings::DELIVERY_XACCEL) {
            $headers->set('X-Accel-Redirect', $internal);
        } else {
            $headers->set('X-Sendfile', $path);
        }

        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        return true;
    }

    /**
     * Both filename forms.
     *
     * The ASCII one for clients that predate RFC 5987 and the encoded one for everybody else. A
     * release called `Rapport annuel — 2026.pdf` arrives with its accents intact in one and as
     * underscores in the other, rather than as a broken header in both.
     */
    private function contentDisposition(string $filename, bool $inline): string
    {
        $disposition = $inline ? 'inline' : 'attachment';
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'file';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, addslashes($fallback), rawurlencode($filename));
    }
}
