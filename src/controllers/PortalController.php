<?php

declare(strict_types=1);

namespace justinholtweb\digits\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\digits\models\Edition;
use justinholtweb\digits\models\LinkToken;
use justinholtweb\digits\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The customer's own account page.
 *
 * ## Two ways in, one identity
 *
 * A signed-in user is recognised by their session. A guest — which on a Commerce site is most
 * buyers — arrives with a token in the URL from an email. The first time a token is seen it is
 * exchanged for a **session flag holding the email address**, and every link on the page after that
 * is an ordinary URL.
 *
 * That exchange is worth the paragraph. Carrying the token through every link means it appears in
 * the `Referer` of every outbound click, in the browser history, and in any analytics the site
 * runs. Spending it once, at the door, keeps a credential that was sent by email from being sprayed
 * across the whole session.
 *
 * ## The templates are the site's
 *
 * Digits looks in the site's `templates/digits/` first and falls back to its own. Changing how the
 * account page looks never means forking the plugin — it means copying a file.
 */
class PortalController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    private const SESSION_KEY = 'digits.portal.email';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->enablePortal || !Edition::allowsPortal($plugin->isPro())) {
            throw new NotFoundHttpException();
        }

        $this->exchangeToken();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $identity = $this->identity();

        if ($identity === null) {
            return $this->renderPortal('portal/sign-in', [
                'canRequestLink' => $plugin->getSettings()->allowPortalRequests,
            ]);
        }

        [$user, $email] = $identity;

        $licenses = $user !== null
            ? $plugin->licenses->getLicensesForUser($user)
            : $plugin->licenses->getLicensesForEmail($email);

        $invoices = $plugin->getSettings()->enableInvoicing && Edition::allowsInvoicing($plugin->isPro())
            ? $plugin->invoices->getInvoicesForEmail($email)
            : [];

        return $this->renderPortal('portal/index', [
            'licenses' => $licenses,
            'invoices' => $invoices,
            'email' => $email,
            'user' => $user,
        ]);
    }

    /**
     * One licence, with a link per file of every version it is entitled to.
     *
     * The links are minted here, at render time, which is why they can be short-lived: the page is
     * the thing the customer comes back to, and the URLs on it are disposable.
     */
    public function actionLicense(int $licenseId): Response
    {
        $plugin = Plugin::getInstance();
        $license = $plugin->licenses->getLicenseById($licenseId);

        if ($license === null) {
            throw new NotFoundHttpException();
        }

        $this->requireOwnership($license->email, $license->ownerId);

        $groups = [];

        foreach ($license->getDownloads() as $download) {
            $versions = [];

            foreach ($plugin->versions->getVersionsAvailableTo($download, $license) as $version) {
                $files = [];

                foreach ($version->getFiles() as $file) {
                    $verdict = $plugin->access->checkFile($download, $version, $file, $license, static::currentUser());

                    $files[] = [
                        'file' => $file,
                        'verdict' => $verdict,
                        'url' => $verdict->allowed
                            ? $plugin->links->urlForFile($file, $version, $download, $license)
                            : null,
                    ];
                }

                $versions[] = ['version' => $version, 'files' => $files];
            }

            $groups[] = [
                'download' => $download,
                'versions' => $versions,
                // Shown even when it is empty, with the reason: a customer whose access lapsed
                // needs to be told that rather than shown a download with nothing under it.
                'verdict' => $plugin->access->check($download, $license, static::currentUser()),
            ];
        }

        return $this->renderPortal('portal/license', [
            'license' => $license,
            'groups' => $groups,
            'activations' => $license->getActivations(),
        ]);
    }

    /** Take a seat back from the portal, which is the only place most customers will look for it. */
    public function actionDeactivate(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $activationId = (int)$this->request->getRequiredBodyParam('activationId');
        $activation = null;

        foreach ($plugin->licenses->getLicenseById((int)$this->request->getRequiredBodyParam('licenseId'))?->getActivations() ?? [] as $candidate) {
            if ((int)$candidate->id === $activationId) {
                $activation = $candidate;
                break;
            }
        }

        if ($activation === null) {
            throw new NotFoundHttpException();
        }

        $license = $plugin->licenses->getLicenseById((int)$activation->licenseId);

        if ($license === null) {
            throw new NotFoundHttpException();
        }

        $this->requireOwnership($license->email, $license->ownerId);

        $plugin->activations->deactivateById($activationId);

        $this->setSuccessFlash(Craft::t('digits', 'Deactivated.'));

        return $this->redirectToPostedUrl();
    }

    public function actionInvoice(int $invoiceId): Response
    {
        $plugin = Plugin::getInstance();
        $invoice = $plugin->invoices->getInvoiceById($invoiceId);

        if ($invoice === null || $invoice->customerEmail === null) {
            throw new NotFoundHttpException();
        }

        $this->requireOwnership($invoice->customerEmail, $invoice->userId);

        return $this->renderPortal('portal/invoice', [
            'invoice' => $invoice,
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * Send a guest a way back in.
     *
     * Answers identically whether or not the address has ever bought anything. The alternative
     * turns this form into a way of asking a shop whether a given person is a customer, which is
     * not a question a stranger gets to ask.
     */
    public function actionRequestLink(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->allowPortalRequests) {
            throw new ForbiddenHttpException();
        }

        $email = trim((string)$this->request->getBodyParam('email'));

        if ($email !== '') {
            $plugin->notifications->sendPortalLink($email);
        }

        $this->setSuccessFlash(Craft::t('digits', 'If that address has any downloads, a link is on its way.'));

        return $this->redirectToPostedUrl();
    }

    public function actionSignOut(): Response
    {
        Craft::$app->getSession()->remove(self::SESSION_KEY);

        return $this->redirect(Plugin::getInstance()->getSettings()->getPortalUrl());
    }

    /**
     * Turn a token in the URL into a session, once.
     *
     * Redirects to the same page without the token, so that a customer who bookmarks the page or
     * shares a screenshot of the address bar is not handing over their access.
     */
    private function exchangeToken(): void
    {
        $token = $this->request->getIsConsoleRequest() ? null : $this->request->getParam('t');

        if ($token === null || $token === '') {
            return;
        }

        $row = Plugin::getInstance()->links->getTokenByValue((string)$token);

        if ($row === null
            || $row->type !== LinkToken::TYPE_PORTAL
            || $row->getIsExpired()
            || $row->getIsSpent()
            || $row->email === null) {
            return;
        }

        Craft::$app->getSession()->set(self::SESSION_KEY, $row->email);
    }

    /** @return array{0: \craft\elements\User|null, 1: string}|null */
    private function identity(): ?array
    {
        $user = static::currentUser();

        if ($user !== null) {
            return [$user, (string)$user->email];
        }

        $email = Craft::$app->getSession()->get(self::SESSION_KEY);

        return $email !== null ? [null, (string)$email] : null;
    }

    /**
     * Whether the visitor is the person this thing belongs to.
     *
     * By owner id *or* by email address, because the two get out of step in the ordinary course of
     * business: a guest buys, registers later, and their licences are claimed by the account only
     * when they next save. Requiring both would lock a customer out of what they bought.
     */
    private function requireOwnership(string $email, ?int $ownerId): void
    {
        $identity = $this->identity();

        if ($identity === null) {
            throw new ForbiddenHttpException(Craft::t('digits', 'Sign in to see this.'));
        }

        [$user, $sessionEmail] = $identity;

        if ($user !== null && $ownerId !== null && (int)$user->id === $ownerId) {
            return;
        }

        if (strcasecmp($sessionEmail, $email) === 0) {
            return;
        }

        throw new ForbiddenHttpException(Craft::t('digits', 'This is not yours to see.'));
    }

    /**
     * Render the site's copy of a template if it has one, and the plugin's otherwise.
     *
     * Not called `render()`, and not `currentUser()` either: both names are already taken on
     * Yii's and Craft's controllers — `render()` is public and `currentUser()` is static — and PHP
     * refuses to load the class at all if a subclass narrows either. The failure is a fatal error
     * on every request the controller serves, with nothing in it about the name that collided.
     *
     * The whole reason a shop can restyle its account page without forking anything.
     */
    private function renderPortal(string $template, array $variables = []): Response
    {
        $view = Craft::$app->getView();
        $siteTemplate = 'digits/' . $template;

        if ($view->doesTemplateExist($siteTemplate, View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate($siteTemplate, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate('digits/_' . $template, $variables, View::TEMPLATE_MODE_CP);
    }
}
