<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\storage;

use Craft;
use craft\helpers\FileHelper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;
use yii\base\ErrorException;
use yii\base\InvalidArgumentException;

/**
 * @property mixed $settingsHtml
 */
class FileStorage extends BaseCacheStorage
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Blitz File Storage (recommended)');
    }

    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $folderPath = '@webroot/cache/blitz';

    /**
     * @var bool
     */
    public $createGzipFiles = false;

    /**
     * @var string|null
     */
    private $_cacheFolderPath;

    /**
     * @var array|null
     */
    private $_sitePaths;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (!empty($this->folderPath)) {
            $this->_cacheFolderPath = FileHelper::normalizePath(
                Craft::parseEnv($this->folderPath)
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['folderPath'], 'required'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function get(SiteUriModel $siteUri): string
    {
        $value = '';

        $filePath = $this->_getFilePath($siteUri);

        if (is_file($filePath)) {
            $value = file_get_contents($filePath);
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function save(string $value, SiteUriModel $siteUri)
    {
        $filePath = $this->_getFilePath($siteUri);

        if (empty($filePath)) {
            return;
        }

        try {
            FileHelper::writeToFile($filePath, $value);

            if ($this->createGzipFiles) {
                FileHelper::writeToFile($filePath.'.gz', gzencode($value));
            }
        }
        catch (ErrorException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
        catch (InvalidArgumentException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteUris(array $siteUris)
    {
        foreach ($siteUris as $siteUri) {
            $filePath = $this->_getFilePath($siteUri);

            // Delete file if it exists
            if (is_file($filePath)) {
                unlink($filePath);
            }

            if (is_file($filePath.'.gz')) {
                unlink($filePath.'.gz');
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteAll()
    {
        if (empty($this->_cacheFolderPath)) {
            return;
        }

        try {
            FileHelper::removeDirectory($this->_cacheFolderPath);
        }
        catch (ErrorException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
    }

    /**
     * @inheritdoc
     */
    public function getUtilityHtml(): string
    {
        $sites = [];

        $allSites = Craft::$app->getSites()->getAllSites();

        foreach ($allSites as $site) {
            $sitePath = $this->_getSitePath($site->id);

            $sites[$site->id] = [
                'name' => $site->name,
                'path' => $sitePath,
                'count' => is_dir($sitePath) ? count(FileHelper::findFiles($sitePath, ['only' => ['index.html']])) : 0,
            ];
        }

        foreach ($sites as $siteId => &$site) {
            // Check if other site counts should be reduced from this site
            foreach ($sites as $otherSiteId => $otherSite) {
                if ($otherSiteId == $siteId) {
                    continue;
                }

                if (strpos($otherSite['path'], $site['path']) === 0) {
                    $site['count'] -= is_dir($otherSite['path']) ? $sites[$otherSiteId]['count'] : 0;
                }
            }
        }

        return Craft::$app->getView()->renderTemplate('blitz/_drivers/storage/file/utility', [
            'sites' => $sites,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('blitz/_drivers/storage/file/settings', [
            'driver' => $this,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns file path from provided site ID and URI.
     *
     * @param SiteUriModel $siteUri
     *
     * @return string
     */
    private function _getFilePath(SiteUriModel $siteUri): string
    {
        $sitePath = $this->_getSitePath($siteUri->siteId);

        if ($sitePath == '') {
            return '';
        }

        // Replace ? with / in URI
        $uri = str_replace('?', '/', $siteUri->uri);

        // Create normalized file path
        $filePath = FileHelper::normalizePath($sitePath.'/'.$uri.'/index.html');

        // Ensure that file path is a sub path of the site path
        if (strpos($filePath, $sitePath) === false) {
            return '';
        }

        return $filePath;
    }

    /**
     * Returns site path from provided site ID.
     *
     * @param int $siteId
     *
     * @return string
     */
    private function _getSitePath(int $siteId): string
    {
        if (!empty($this->_sitePaths[$siteId])) {
            return $this->_sitePaths[$siteId];
        }

        if (empty($this->_cacheFolderPath)) {
            return '';
        }

        // Get the site host and path from the site's base URL
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $siteUrl = Craft::getAlias($site->getBaseUrl());
        $siteHostPath = preg_replace('/^(http|https):\/\//i', '', $siteUrl);

        $this->_sitePaths[$siteId] = FileHelper::normalizePath($this->_cacheFolderPath.'/'.$siteHostPath);

        return $this->_sitePaths[$siteId];
    }
}
