<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\helpers;

use Craft;
use craft\elements\User;
use craft\errors\SiteNotFoundException;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;

/**
 * @property bool $isCacheableRequest
 * @property SiteUriModel $requestedSiteUri
 */
class RequestHelper
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the request is cacheable.
     *
     * @return bool
     */
    public static function getIsCacheableRequest(): bool
    {
        if (!Blitz::$plugin->settings->cachingEnabled) {
            return false;
        }

        // Ensure this is a cacheable site request
        if (!self::_getIsCacheableSiteRequest()) {
            return false;
        }

        // Ensure the response is not an error
        if (!Craft::$app->getResponse()->getIsOk()) {
            return false;
        }

        /** @var User|null $user */
        $user = Craft::$app->getUser()->getIdentity();

        if ($user !== null) {
            // Ensure that if the site is not live that the user has permission to access it
            if (!Craft::$app->getIsLive() && !$user->can('accessSiteWhenSystemIsOff')) {
                return false;
            }

            // Ensure that if user is logged in then debug toolbar is not enabled
            if ($user->getPreference('enableDebugToolbarForSite')) {
                return false;
            }
        }

        $request = Craft::$app->getRequest();

        if (!empty($request->getParam('no-cache'))) {
            return false;
        }

        if (!empty($request->getParam('token'))) {
            return false;
        }

        if (Blitz::$plugin->settings->queryStringCaching == 0 && !empty($request->getQueryStringWithoutPath())) {
            return false;
        }

        return true;
    }

    /**
     * Returns the requested site URI.
     *
     * @return SiteUriModel|null
     */
    public static function getRequestedSiteUri()
    {
        try {
            $site = Craft::$app->getSites()->getCurrentSite();
        }
        catch (SiteNotFoundException $e) {
            return null;
        }

        $url = Craft::$app->getRequest()->getAbsoluteUrl();

        // Remove the query string if unique query strings should be cached as the same page
        if (Blitz::$plugin->settings->queryStringCaching == 2) {
            $url = preg_replace('/\?.*/', '', $url);
        }

        // Remove site base URL
        $baseUrl = trim(Craft::getAlias($site->getBaseUrl()), '/');
        $uri = str_replace($baseUrl, '', $url);

        // Trim slashes from the beginning and end of the URI
        $uri = trim($uri, '/');

        return new SiteUriModel([
            'siteId' => $site->id,
            'uri' => $uri,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether the request is cacheable site request.
     *
     * @return bool
     */
    private static function _getIsCacheableSiteRequest(): bool
    {
        $request = Craft::$app->getRequest();

        return ($request->getIsSiteRequest()
            && $request->getIsGet()
            && !$request->getIsConsoleRequest()
            && !$request->getIsActionRequest()
            && !$request->getIsPreview()
            // TODO: remove in 3.0.0
            // Keep this even if deprecated to support plugins that still use it
            && !$request->getIsLivePreview()
        );
    }
}
