<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Backend\Controller;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Backend\Authentication\PasswordReset;
use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\View\AuthenticationStyleInformation;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\BackendFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Information\Typo3Information;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Controller responsible for rendering the TYPO3 Backend login form
 * @internal This class is a specific Backend controller implementation and is not considered part of the Public TYPO3 API.
 */
class LoginController
{
    /**
     * The URL to redirect to after login.
     *
     * @var string
     */
    protected $redirectUrl;

    /**
     * Set to the redirect URL of the form (may be redirect_url or "index.php?M=main")
     *
     * @var string
     */
    protected $redirectToURL;

    /**
     * the active login provider identifier
     *
     * @var string
     */
    protected $loginProviderIdentifier;

    /**
     * List of registered and sorted login providers
     *
     * @var array
     */
    protected $loginProviders = [];

    /**
     * Login-refresh bool; The backend will call this script
     * with this value set when the login is close to being expired
     * and the form needs to be redrawn.
     *
     * @var bool
     */
    protected $loginRefresh;

    /**
     * Value of forms submit button for login.
     *
     * @var string
     */
    protected $submitValue;

    /**
     * @var StandaloneView
     */
    protected $view;

    /**
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    protected EventDispatcherInterface $eventDispatcher;
    protected Typo3Information $typo3Information;
    protected PageRenderer $pageRenderer;
    protected UriBuilder $uriBuilder;
    protected Features $features;
    protected Context $context;
    protected ModuleTemplateFactory $moduleTemplateFactory;

    public function __construct(
        Typo3Information $typo3Information,
        EventDispatcherInterface $eventDispatcher,
        PageRenderer $pageRenderer,
        UriBuilder $uriBuilder,
        Features $features,
        Context $context,
        ModuleTemplateFactory $moduleTemplateFactory
    ) {
        $this->typo3Information = $typo3Information;
        $this->eventDispatcher = $eventDispatcher;
        $this->uriBuilder = $uriBuilder;
        $this->pageRenderer = $pageRenderer;
        $this->features = $features;
        $this->context = $context;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * Injects the request and response objects for the current request or subrequest
     * As this controller goes only through the main() method, it is rather simple for now
     *
     * @param ServerRequestInterface $request the current request
     * @return ResponseInterface the finished response with the content
     */
    public function formAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->init($request);
        return new HtmlResponse($this->createLoginLogoutForm($request));
    }

    /**
     * Calls the main function but with loginRefresh enabled at any time
     *
     * @param ServerRequestInterface $request the current request
     * @return ResponseInterface the finished response with the content
     */
    public function refreshAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->init($request);
        $this->loginRefresh = true;
        return new HtmlResponse($this->createLoginLogoutForm($request));
    }

    /**
     * Show a form to enter an email address to request an email.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function forgetPasswordFormAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only allow to execute this if not logged in as a user right now
        if ($this->context->getAspect('backend.user')->isLoggedIn()) {
            return $this->formAction($request);
        }
        $this->init($request);
        // Enable the switch in the template
        $this->view->assign('enablePasswordReset', GeneralUtility::makeInstance(PasswordReset::class)->isEnabled());
        $this->view->setTemplate('Login/ForgetPasswordForm');
        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * Validate the email address.
     *
     * Restricted to POST method in Configuration/Backend/Routes.php
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function initiatePasswordResetAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only allow to execute this if not logged in as a user right now
        if ($this->context->getAspect('backend.user')->isLoggedIn()) {
            return $this->formAction($request);
        }
        $this->init($request);
        $passwordReset = GeneralUtility::makeInstance(PasswordReset::class);
        $this->view->assign('enablePasswordReset', $passwordReset->isEnabled());
        $this->view->setTemplate('Login/ForgetPasswordForm');

        $emailAddress = $request->getParsedBody()['email'] ?? '';
        $this->view->assign('email', $emailAddress);
        if (!GeneralUtility::validEmail($emailAddress)) {
            $this->view->assign('invalidEmail', true);
        } else {
            $passwordReset->initiateReset($request, $this->context, $emailAddress);
            $this->view->assign('resetInitiated', true);
        }
        $this->moduleTemplate->setContent($this->view->render());
        // Prevent time based information disclosure by waiting a random time
        // before sending a response. This prevents that the reponse time
        // can be an indicator if the used email exists or not.
        // wait a random time between 200 milliseconds and 3 seconds.
        usleep(random_int(200000, 3000000));
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * Validates the link and show a form to enter the new password.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function passwordResetAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only allow to execute this if not logged in as a user right now
        if ($this->context->getAspect('backend.user')->isLoggedIn()) {
            return $this->formAction($request);
        }
        $this->init($request);
        $passwordReset = GeneralUtility::makeInstance(PasswordReset::class);
        $this->view->setTemplate('Login/ResetPasswordForm');
        $this->view->assign('enablePasswordReset', $passwordReset->isEnabled());
        if (!$passwordReset->isValidResetTokenFromRequest($request)) {
            $this->view->assign('invalidToken', true);
        }
        $this->view->assign('token', $request->getQueryParams()['t'] ?? '');
        $this->view->assign('identity', $request->getQueryParams()['i'] ?? '');
        $this->view->assign('expirationDate', $request->getQueryParams()['e'] ?? '');
        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * Updates the password in the database.
     *
     * Restricted to POST method in Configuration/Backend/Routes.php
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function passwordResetFinishAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only allow to execute this if not logged in as a user right now
        if ($this->context->getAspect('backend.user')->isLoggedIn()) {
            return $this->formAction($request);
        }
        $passwordReset = GeneralUtility::makeInstance(PasswordReset::class);
        // Token is invalid
        if (!$passwordReset->isValidResetTokenFromRequest($request)) {
            return $this->passwordResetAction($request);
        }
        $this->init($request);
        $this->view->setTemplate('Login/ResetPasswordForm');
        $this->view->assign('enablePasswordReset', $passwordReset->isEnabled());
        $this->view->assign('token', $request->getQueryParams()['t'] ?? '');
        $this->view->assign('identity', $request->getQueryParams()['i'] ?? '');
        $this->view->assign('expirationDate', $request->getQueryParams()['e'] ?? '');
        if ($passwordReset->resetPassword($request, $this->context)) {
            $this->view->assign('resetExecuted', true);
        } else {
            $this->view->assign('error', true);
        }
        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * This can be called by single login providers, they receive an instance of $this
     *
     * @return string
     */
    public function getLoginProviderIdentifier()
    {
        return $this->loginProviderIdentifier;
    }

    /**
     * Initialize the login box. Will also react on a &L=OUT flag and exit.
     *
     * @param ServerRequestInterface $request the current request
     */
    protected function init(ServerRequestInterface $request): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);
        $this->moduleTemplate->setTitle('TYPO3 CMS Login: ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $this->validateAndSortLoginProviders();

        $this->redirectUrl = GeneralUtility::sanitizeLocalUrl($parsedBody['redirect_url'] ?? $queryParams['redirect_url'] ?? null);
        $this->loginProviderIdentifier = $this->detectLoginProvider($request);

        $this->loginRefresh = (bool)($parsedBody['loginRefresh'] ?? $queryParams['loginRefresh'] ?? false);
        // Value of "Login" button. If set, the login button was pressed.
        $this->submitValue = $parsedBody['commandLI'] ?? $queryParams['commandLI'] ?? null;
        // Try to get the preferred browser language
        $httpAcceptLanguage = $request->getServerParams()['HTTP_ACCEPT_LANGUAGE'];
        $preferredBrowserLanguage = GeneralUtility::makeInstance(Locales::class)->getPreferredClientLanguage($httpAcceptLanguage);

        // If we found a $preferredBrowserLanguage and it is not the default language and no be_user is logged in
        // initialize $this->getLanguageService() again with $preferredBrowserLanguage
        if ($preferredBrowserLanguage !== 'default' && empty($this->getBackendUserAuthentication()->user['uid'])) {
            $this->getLanguageService()->init($preferredBrowserLanguage);
            $this->pageRenderer->setLanguage($preferredBrowserLanguage);
        }

        $this->getLanguageService()->includeLLFile('EXT:backend/Resources/Private/Language/locallang_login.xlf');

        // Setting the redirect URL to "index.php?M=main" if no alternative input is given
        if ($this->redirectUrl) {
            $this->redirectToURL = $this->redirectUrl;
        } else {
            // (consolidate RouteDispatcher::evaluateReferrer() when changing 'main' to something different)
            $this->redirectToURL = (string)$this->uriBuilder->buildUriWithRedirectFromRequest('main', [], $request);
        }

        // If "L" is "OUT", then any logged in is logged out. If redirect_url is given, we redirect to it
        if (($parsedBody['L'] ?? $queryParams['L'] ?? null) === 'OUT' && is_object($this->getBackendUserAuthentication())) {
            $this->getBackendUserAuthentication()->logoff();
            $this->redirectToUrl();
        }

        $this->view = $this->moduleTemplate->getView();
        $this->view->getRequest()->setControllerExtensionName('Backend');
        $this->provideCustomLoginStyling();
        $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/Login');
        $this->view->assign('referrerCheckEnabled', $this->features->isFeatureEnabled('security.backend.enforceReferrer'));
        $this->view->assign('loginUrl', (string)$request->getUri());
        $this->view->assign('loginProviderIdentifier', $this->loginProviderIdentifier);
    }

    protected function provideCustomLoginStyling(): void
    {
        $authenticationStyleInformation = GeneralUtility::makeInstance(AuthenticationStyleInformation::class);
        if (($backgroundImageStyles = $authenticationStyleInformation->getBackgroundImageStyles()) !== '') {
            $this->pageRenderer->addCssInlineBlock('loginBackgroundImage', $backgroundImageStyles);
        }
        if (($footerNote = $authenticationStyleInformation->getFooterNote()) !== '') {
            $this->view->assign('loginFootnote', $footerNote);
        }
        if (($highlightColorStyles = $authenticationStyleInformation->getHighlightColorStyles()) !== '') {
            $this->pageRenderer->addCssInlineBlock('loginHighlightColor', $highlightColorStyles);
        }
        if (($logo = $authenticationStyleInformation->getLogo()) !== '') {
            $logoAlt = $authenticationStyleInformation->getLogoAlt();
        } else {
            $logo = $authenticationStyleInformation->getDefaultLogo();
            $logoAlt = $this->getLanguageService()->getLL('typo3.altText');
            $this->pageRenderer->addCssInlineBlock('loginLogo', $authenticationStyleInformation->getDefaultLogoStyles());
        }
        $this->view->assignMultiple([
            'logo' => $logo,
            'logoAlt' => $logoAlt,
            'images' => $authenticationStyleInformation->getSupportingImages(),
            'copyright' => $this->typo3Information->getCopyrightNotice(),
        ]);
    }

    /**
     * Main function - creating the login/logout form
     *
     * @param ServerRequestInterface $request
     * @return string $content
     */
    protected function createLoginLogoutForm(ServerRequestInterface $request): string
    {
        // Checking, if we should make a redirect.
        // Might set JavaScript in the header to close window.
        $this->checkRedirect($request);

        // Show login form
        if (empty($this->getBackendUserAuthentication()->user['uid'])) {
            $action = 'login';
            $formActionUrl = $this->uriBuilder->buildUriWithRedirectFromRequest(
                'login',
                [
                    'loginProvider' => $this->loginProviderIdentifier
                ],
                $request
            );
        } else {
            // Show logout form
            $action = 'logout';
            $formActionUrl = $this->uriBuilder->buildUriFromRoute('logout');
        }
        $this->view->assignMultiple([
            'backendUser' => $this->getBackendUserAuthentication()->user,
            'hasLoginError' => $this->isLoginInProgress($request),
            'action' => $action,
            'formActionUrl' => $formActionUrl,
            'redirectUrl' => $this->redirectUrl,
            'loginRefresh' => $this->loginRefresh,
            'loginProviders' => $this->loginProviders,
            'loginNewsItems' => $this->getSystemNews(),
        ]);

        // Initialize interface selectors:
        $this->makeInterfaceSelector($request);
        $this->renderHtmlViaLoginProvider();

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    protected function renderHtmlViaLoginProvider(): void
    {
        /** @var LoginProviderInterface $loginProvider */
        $loginProvider = GeneralUtility::makeInstance($this->loginProviders[$this->loginProviderIdentifier]['provider']);
        $this->eventDispatcher->dispatch(
            new ModifyPageLayoutOnLoginProviderSelectionEvent(
                $this,
                $this->view,
                $this->pageRenderer
            )
        );
        $loginProvider->render($this->view, $this->pageRenderer, $this);
    }

    /**
     * Checking, if we should perform some sort of redirection OR closing of windows.
     *
     * Do a redirect if a user is logged in
     *
     * @param ServerRequestInterface $request
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function checkRedirect(ServerRequestInterface $request): void
    {
        $backendUser = $this->getBackendUserAuthentication();
        if (empty($backendUser->user['uid'])) {
            return;
        }

        /*
         * If no cookie has been set previously, we tell people that this is a problem.
         * This assumes that a cookie-setting script (like this one) has been hit at
         * least once prior to this instance.
         */
        if (!isset($_COOKIE[BackendUserAuthentication::getCookieName()])) {
            if ($this->submitValue === 'setCookie') {
                // we tried it a second time but still no cookie
                throw new \RuntimeException('Login-error: Yeah, that\'s a classic. No cookies, no TYPO3. ' .
                    'Please accept cookies from TYPO3 - otherwise you\'ll not be able to use the system.', 1294586846);
            }
            // try it once again - that might be needed for auto login
            $this->redirectToURL = 'index.php?commandLI=setCookie';
        }
        $redirectToUrl = (string)($backendUser->getTSConfig()['auth.']['BE.']['redirectToURL'] ?? '');
        if (empty($redirectToUrl)) {
            // Based on the interface we set the redirect script
            $parsedBody = $request->getParsedBody();
            $queryParams = $request->getQueryParams();
            $interface = $parsedBody['interface'] ?? $queryParams['interface'] ?? '';
            switch ($interface) {
                case 'frontend':
                    $this->redirectToURL = '../';
                    break;
                case 'backend':
                    // (consolidate RouteDispatcher::evaluateReferrer() when changing 'main' to something different)
                    $this->redirectToURL = (string)$this->uriBuilder->buildUriWithRedirectFromRequest('main', [], $request);
                    break;
            }
        } else {
            $this->redirectToURL = $redirectToUrl;
            $interface = '';
        }
        // store interface
        $backendUser->uc['interfaceSetup'] = $interface;
        $backendUser->writeUC();

        $formProtection = FormProtectionFactory::get();
        if (!$formProtection instanceof BackendFormProtection) {
            throw new \RuntimeException('The Form Protection retrieved does not match the expected one.', 1432080411);
        }
        if ($this->loginRefresh) {
            $formProtection->setSessionTokenFromRegistry();
            $formProtection->persistSessionToken();
            $this->pageRenderer->addJsInlineCode('loginRefresh', '
				if (window.opener && window.opener.TYPO3 && window.opener.TYPO3.LoginRefresh) {
					window.opener.TYPO3.LoginRefresh.startTask();
					window.close();
				}
			');
        } else {
            $formProtection->storeSessionTokenInRegistry();
            $this->redirectToUrl();
        }
    }

    /**
     * Making interface selector
     * @param ServerRequestInterface $request
     */
    protected function makeInterfaceSelector(ServerRequestInterface $request): void
    {
        // If interfaces are defined AND no input redirect URL in GET vars:
        if ($GLOBALS['TYPO3_CONF_VARS']['BE']['interfaces'] && ($this->isLoginInProgress($request) || !$this->redirectUrl)) {
            $parts = GeneralUtility::trimExplode(',', $GLOBALS['TYPO3_CONF_VARS']['BE']['interfaces']);
            if (count($parts) > 1) {
                // Only if more than one interface is defined we will show the selector
                $interfaces = [
                    'backend' => [
                        'label' => $this->getLanguageService()->getLL('interface.backend'),
                        'jumpScript' => (string)$this->uriBuilder->buildUriFromRoute('main'),
                        'interface' => 'backend'
                    ],
                    'frontend' => [
                        'label' => $this->getLanguageService()->getLL('interface.frontend'),
                        'jumpScript' => '../',
                        'interface' => 'frontend'
                    ]
                ];

                $this->view->assign('showInterfaceSelector', true);
                $this->view->assign('interfaces', $interfaces);
            } elseif (!$this->redirectUrl) {
                // If there is only ONE interface value set and no redirect_url is present
                $this->view->assign('showInterfaceSelector', false);
                $this->view->assign('interface', $parts[0]);
            }
        }
    }

    /**
     * Gets news from sys_news and converts them into a format suitable for
     * showing them at the login screen.
     *
     * @return array An array of login news.
     */
    protected function getSystemNews(): array
    {
        $systemNewsTable = 'sys_news';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($systemNewsTable);
        $systemNews = [];
        $systemNewsRecords = $queryBuilder
            ->select('uid', 'title', 'content', 'crdate')
            ->from($systemNewsTable)
            ->orderBy('crdate', 'DESC')
            ->execute()
            ->fetchAll();
        foreach ($systemNewsRecords as $systemNewsRecord) {
            $systemNews[] = [
                'uid' => $systemNewsRecord['uid'],
                'date' => date($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'], (int)$systemNewsRecord['crdate']),
                'header' => $systemNewsRecord['title'],
                'content' => $systemNewsRecord['content']
            ];
        }
        return $systemNews;
    }

    /**
     * Checks if login credentials are currently submitted
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    protected function isLoginInProgress(ServerRequestInterface $request): bool
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $username = $parsedBody['username'] ?? $queryParams['username'] ?? null;
        return !empty($username) || !empty($this->submitValue);
    }

    /**
     * Wrapper method to redirect to configured redirect URL
     */
    protected function redirectToUrl(): void
    {
        throw new PropagateResponseException(new RedirectResponse($this->redirectToURL, 303), 1607271511);
    }

    /**
     * Validates the registered login providers
     *
     * @throws \RuntimeException
     */
    protected function validateAndSortLoginProviders()
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'] ?? [];
        if (empty($providers) || !is_array($providers)) {
            throw new \RuntimeException('No login providers are registered in $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'backend\'][\'loginProviders\'].', 1433417281);
        }
        foreach ($providers as $identifier => $configuration) {
            if (empty($configuration) || !is_array($configuration)) {
                throw new \RuntimeException('Missing configuration for login provider "' . $identifier . '".', 1433416043);
            }
            if (!is_string($configuration['provider']) || empty($configuration['provider']) || !class_exists($configuration['provider']) || !is_subclass_of($configuration['provider'], LoginProviderInterface::class)) {
                throw new \RuntimeException('The login provider "' . $identifier . '" defines an invalid provider. Ensure the class exists and implements the "' . LoginProviderInterface::class . '".', 1460977275);
            }
            if (empty($configuration['label'])) {
                throw new \RuntimeException('Missing label definition for login provider "' . $identifier . '".', 1433416044);
            }
            if (empty($configuration['icon-class'])) {
                throw new \RuntimeException('Missing icon definition for login provider "' . $identifier . '".', 1433416045);
            }
            if (!isset($configuration['sorting'])) {
                throw new \RuntimeException('Missing sorting definition for login provider "' . $identifier . '".', 1433416046);
            }
        }
        // sort providers
        uasort($providers, function ($a, $b) {
            return $b['sorting'] - $a['sorting'];
        });
        $this->loginProviders = $providers;
    }

    /**
     * Detect the login provider, get from request or choose the
     * first one as default
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function detectLoginProvider(ServerRequestInterface $request): string
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $loginProvider = $parsedBody['loginProvider'] ?? $queryParams['loginProvider'] ?? '';
        if ((empty($loginProvider) || !isset($this->loginProviders[$loginProvider])) && !empty($_COOKIE['be_lastLoginProvider'])) {
            $loginProvider = $_COOKIE['be_lastLoginProvider'];
        }
        reset($this->loginProviders);
        $primaryLoginProvider = (string)key($this->loginProviders);
        if (empty($loginProvider) || !isset($this->loginProviders[$loginProvider])) {
            $loginProvider = $primaryLoginProvider;
        }

        if ($loginProvider !== $primaryLoginProvider) {
            // Use the secure option when the current request is served by a secure connection
            /** @var NormalizedParams $normalizedParams */
            $normalizedParams = $request->getAttribute('normalizedParams');
            $cookie = new Cookie(
                'be_lastLoginProvider',
                $loginProvider,
                $GLOBALS['EXEC_TIME'] + 7776000, // 90 days
                $normalizedParams->getSitePath() . TYPO3_mainDir,
                '',
                $normalizedParams->isHttps(),
                true,
                false,
                Cookie::SAMESITE_STRICT
            );
            header('Set-Cookie: ' . $cookie->__toString(), false);
        }
        return (string)$loginProvider;
    }

    /**
     * Returns LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
