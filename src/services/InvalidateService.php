<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\GlobalSet;
use craft\helpers\Db;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\jobs\RefreshCacheJob;
use putyourlightson\blitz\jobs\WarmCacheJob;
use putyourlightson\blitz\models\SettingsModel;
use putyourlightson\blitz\records\CacheRecord;
use putyourlightson\blitz\records\ElementCacheRecord;
use putyourlightson\blitz\records\ElementExpiryDateRecord;
use putyourlightson\blitz\records\ElementQueryRecord;
use yii\db\Exception;

/**
 * @property string[] $allCachedUrls
 */
class InvalidateService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RefreshCacheEvent
     */
    const EVENT_AFTER_REFRESH_CACHE = 'afterRefreshCache';

    /**
     * @event Event
     */
    const EVENT_AFTER_CLEAR_CACHE = 'afterClearCache';

    // Properties
    // =========================================================================

    /**
     * @var SettingsModel
     */
    public $settings;

    /**
     * @var bool
     */
    public $batchMode = false;

    /**
     * @var int[]
     */
    private $_cacheIds = [];

    /**
     * @var int[]
     */
    private $_elementIds = [];

    /**
     * @var string[]
     */
    private $_elementTypes = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns cached URLs given an array of cache IDs.
     *
     * @param int[] $cacheIds
     *
     * @return string[]
     * @throws \yii\base\Exception
     */
    public function getCachedUrls(array $cacheIds): array
    {
        $urls = [];

        /** @var CacheRecord[] $cacheRecords */
        $cacheRecords = CacheRecord::find()
            ->select('uri, siteId')
            ->where(['id' => $cacheIds])
            ->all();

        foreach ($cacheRecords as $cacheRecord) {
            $urls[] = Blitz::$plugin->request->getSiteUrl($cacheRecord->siteId, $cacheRecord->uri);
        }

        return $urls;
    }

    /**
     * Returns all cached URLs.
     *
     * @return string[]
     * @throws \yii\base\Exception
     */
    public function getAllCachedUrls(): array
    {
        $urls = [];

        // Get URLs from all cache records
        $cacheRecords = CacheRecord::find()
            ->select(['siteId', 'uri'])
            ->all();

        /** @var CacheRecord $cacheRecord */
        foreach ($cacheRecords as $cacheRecord) {
            if (Blitz::$plugin->request->getIsCacheableUri($cacheRecord->siteId, $cacheRecord->uri)) {
                $urls[] = Blitz::$plugin->request->getSiteUrl($cacheRecord->siteId, $cacheRecord->uri);
            }
        }

        // Get URLs from all element types
        $elementTypes = Craft::$app->getElements()->getAllElementTypes();

        /** @var Element $elementType */
        foreach ($elementTypes as $elementType) {
            if ($elementType::hasUris()) {
                // Loop through all sites to ensure we warm all site element URLs
                $sites = Craft::$app->getSites()->getAllSites();

                foreach ($sites as $site) {
                    $elements = $elementType::find()->siteId($site->id)->all();

                    /** @var Element $element */
                    foreach ($elements as $element) {
                        $uri = trim($element->uri, '/');
                        $uri = ($uri == '__home__' ? '' : $uri);

                        if ($uri !== null && Blitz::$plugin->request->getIsCacheableUri($site->id, $uri)) {
                            $url = $element->getUrl();

                            if ($url !== null && !in_array($url, $urls, true)) {
                                $urls[] = $url;
                            }
                        }
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Adds an element to invalidate.
     *
     * @param ElementInterface $element
     * @throws Exception
     * @throws \yii\base\Exception
     */
    public function addElement(ElementInterface $element)
    {
        // Clear and the cache if this is a global set element as they are populated on every request
        if ($element instanceof GlobalSet) {
            $this->clearCache();

            if ($this->settings->cachingEnabled && $this->settings->warmCacheAutomatically && $this->settings->warmCacheAutomaticallyForGlobals) {
                $this->warmCache($this->getAllCachedUrls());
            }

            return;
        }

        /** @var Element $element */
        $elementType = get_class($element);

        // Don't proceed if this is a non cacheable element type
        if (in_array($elementType, Blitz::$plugin->cache->getNonCacheableElementTypes(), true)) {
            return;
        }

        // Cast ID to integer to ensure the strict type check below works
        $elementId = (int)$element->id;

        // Don't proceed if this entry has already been added
        if (in_array($elementId, $this->_elementIds, true)) {
            return;
        }

        $this->_elementIds[] = $elementId;

        if (!in_array($elementType, $this->_elementTypes, true)) {
            $this->_elementTypes[] = $elementType;
        }

        // Get the element cache IDs to clear now as we may not be able to detect it later in a job (if the element was deleted for example)
        $cacheIds = ElementCacheRecord::find()
            ->select('cacheId')
            ->where(['elementId' => $elementId])
            ->groupBy('cacheId')
            ->column();

        foreach ($cacheIds as $cacheId) {
            if (!in_array($cacheId, $this->_cacheIds, true)) {
                $this->_cacheIds[] = $cacheId;
            }
        }

        // Check if element has a future post or expiry date
        $expiryDate = null;
        $now = new \DateTime();

        if (!empty($element->postDate) && $element->postDate > $now) {
            $expiryDate = $element->postDate;
        }
        else if (!empty($element->expiryDate) && $element->expiryDate > $now) {
            $expiryDate = $element->expiryDate;
        }

        if ($expiryDate !== null) {
            $expiryDate = Db::prepareDateForDb($expiryDate);

            /** @var ElementExpiryDateRecord|null $elementExpiryDateRecord */
            $elementExpiryDateRecord = ElementExpiryDateRecord::find()
                ->where(['elementId' => $elementId])
                ->one();

            if ($elementExpiryDateRecord !== null && $elementExpiryDateRecord->expiryDate < $expiryDate) {
                $expiryDate = $elementExpiryDateRecord->expiryDate;
            }

            /** @noinspection MissedFieldInspection */
            Craft::$app->getDb()->createCommand()
                ->upsert(ElementExpiryDateRecord::tableName(), [
                        'elementId' => $elementId,
                        'expiryDate' => $expiryDate,
                    ],
                    ['expiryDate' => $expiryDate],
                    [],
                    false)
                ->execute();
        }

        // Refresh the cache if not in batch mode
        if ($this->batchMode === false) {
            $this->refreshCache();
        }
    }

    /**
     * Refreshes the cache.
     */
    public function refreshCache()
    {
        if (empty($this->_cacheIds) && empty($this->_elementIds)) {
            return;
        }

        Craft::$app->getQueue()->push(new RefreshCacheJob([
            'cacheIds' => $this->_cacheIds,
            'elementIds' => $this->_elementIds,
            'elementTypes' => $this->_elementTypes,
        ]));
    }

    /**
     * Performs actions after the cache is refreshed.
     *
     * @param string[] $urls
     */
    public function afterRefreshCache(array $urls)
    {
        // Fire an 'afterRefreshCache' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_REFRESH_CACHE)) {
            $this->trigger(self::EVENT_AFTER_REFRESH_CACHE, new RefreshCacheEvent([
                'urls' => $urls,
            ]));
        }

        if ($this->settings->cachingEnabled && $this->settings->warmCacheAutomatically) {
            $this->warmCache($urls);
        }
    }

    /**
     * Warms the cache.
     *
     * @param string[] $urls
     */
    public function warmCache(array $urls)
    {
        Craft::$app->getQueue()->push(new WarmCacheJob([
            'urls' => $urls,
            'concurrency' => $this->settings->concurrency,
        ]));
    }

    /**
     * Refreshes expired cache.
     */
    public function refreshExpiredCache()
    {
        // Check for expired elements and invalidate them
        $elementExpiryDates = ElementExpiryDateRecord::find()
            ->where(['<', 'expiryDate', Db::prepareDateForDb(new \DateTime())])
            ->all();

        if (empty($elementExpiryDates)) {
            return;
        }

        $elements = Craft::$app->getElements();

        /** @var ElementExpiryDateRecord $elementExpiryDate */
        foreach ($elementExpiryDates as $elementExpiryDate) {
            $element = $elements->getElementById($elementExpiryDate->elementId);

            // This should happen before invalidating the element so that other expire dates will be saved
            $elementExpiryDate->delete();

            if ($element !== null) {
                $this->addElement($element);
            }
        }
    }

    /**
     * Clears cache records for a given site and URI.
     *
     * @param int $siteId
     * @param string $uri
     */
    public function clearCacheRecords(int $siteId, string $uri)
    {
        CacheRecord::deleteAll([
            'siteId' => $siteId,
            'uri' => $uri,
        ]);
    }

    /**
     * Clears the cache.
     *
     * @param bool $flush
     */
    public function clearCache(bool $flush = false)
    {
        // Clear the cache
        Blitz::$plugin->driver->clearCache();

        // Purge all cache
        Blitz::$plugin->purger->purgeAll();

        if ($flush) {
            // Delete all cache records
            CacheRecord::deleteAll();

            $this->runGarbageCollection();
        }

        // Fire an 'afterClearCache' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_CLEAR_CACHE)) {
            $this->trigger(self::EVENT_AFTER_CLEAR_CACHE);
        }
    }

    /**
     * Runs garbage collection.
     */
    public function runGarbageCollection()
    {
        // Get and delete element query records without an associated element query cache
        $elementQueryRecordIds = ElementQueryRecord::find()
            ->select('id')
            ->joinWith('elementQueryCaches')
            ->where(['cacheId' => null])
            ->column();

        ElementQueryRecord::deleteAll(['id' => $elementQueryRecordIds]);
    }
}
