<?php

namespace MageOps\NodeWarmer\Service;

class NodeWarmer
{
    const WARM_LOG_FILENAME = 'WARMUP';
    const WARMUP_TIMEOUT = 60;

    /**
     * @var MergedAssetsWarmupUrlsProvider
     */
    protected $mergedAssetsWarmupUrlsProvider;

    /**
     * @var \MageOps\NodeWarmer\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

    /**
     * @var \Magento\Framework\App\Cache\Manager
     */
    protected $cacheManager;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlGenerator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $http;

    public function __construct(
        \MageOps\NodeWarmer\Model\Config $config,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\Cache\Manager $cacheManager,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlGenerator,
        MergedAssetsWarmupUrlsProvider $mergedAssetsWarmupUrlsProvider,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->eventManager = $eventManager;
        $this->cacheManager = $cacheManager;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->urlGenerator = $urlGenerator;
        $this->mergedAssetsWarmupUrlsProvider = $mergedAssetsWarmupUrlsProvider;
        $this->logger = new \MageOps\NodeWarmer\Log\CapturingLoggerDecorator($logger);

        $this->http = new \GuzzleHttp\Client([
            'timeout' => self::WARMUP_TIMEOUT,
            'allow_redirects' => true,
            'http_errors' => false,
        ]);
    }

    /**
     * @param bool $force
     * @param string $localUrl
     */
    public function warmNodeUp($localUrl, $force = false)
    {
        $codeVersion = $this->getCurrentCodeVersion();
        $deployedStaticContentVersion = $this->getDeployedStaticContentVersion();

        $this->logger->info(sprintf('Starting warmup for node "%s"', $this->getNodeId()));

        if (file_exists($this->getWarmupLogFilePath()) && !$force) {
            $this->logger->info('Skipping warmup, already warm...');
            return;
        }

        $stopwatch = new \Symfony\Component\Stopwatch\Stopwatch();
        $stopwatch->start('warmup');

        if ($this->config->getCacheCodeVersion() !== $codeVersion) {
            $stopwatch->start('cc');
            $this->config->updateCacheCodeVersion($codeVersion);

            $this->logger->info(sprintf('Cache version mismatch - DB: %s, New: %s, flushing cache...',
                $this->config->getCacheCodeVersion(),
                $codeVersion
            ));

            $this->flushCache();
            $this->logger->info(sprintf('Finished cache flush, took %.2fs',
                $stopwatch->stop('cc')->getDuration() / 1000.0
            ));
        }

        if($this->config->getDeployedStaticContentVersion() !== $deployedStaticContentVersion) {
            $urls = $this->getUrlsToBeWarmedUp();

            if(!empty($urls)) {
                foreach ($urls as $url) {
                    $this->queryUrl($localUrl . $url['path'], $url['host']);
                }
            }

            $this->config->updateDeployedStaticContentVersion($deployedStaticContentVersion);
        }

        $took = $stopwatch->stop('warmup')->getDuration() / 1000.0;

        $this->logger->info(sprintf('All done, took %.2fs', $took));
        $this->saveWarmupLog();
    }

    protected function flushCache()
    {
        $this->eventManager->dispatch('adminhtml_cache_flush_all');
        $this->cacheManager->flush($this->cacheManager->getAvailableTypes());
    }

    /**
     * @return string
     */
    protected function getComposerLockPath()
    {
        return $this->directoryList->getRoot() . '/composer.lock';
    }

    /**
     * @return string
     */
    public function getWarmupLogFilePath()
    {
        return $this->directoryList->getPath('pub') . '/' . self::WARM_LOG_FILENAME;
    }

    protected function saveWarmupLog()
    {
        $path = $this->getWarmupLogFilePath();
        $formatter = new \MageOps\NodeWarmer\Log\LogFormatter();

        file_put_contents(
            $path,
            $formatter->formatBatch($this->logger->flush())
        );
    }

    protected function getUrlsToBeWarmedUp() {
        $attempt = 1;

        do {
            try {
                $urls = $this->mergedAssetsWarmupUrlsProvider->getUrls();

                return $urls;
            }
            catch(\Exception $exception) {
                $this->logger->error(sprintf(
                    'Unable to warmup merged assets during attempt %d: %s, %s',
                    $attempt,
                    $exception->getMessage(),
                    $exception->getTraceAsString()
                ));
            }

            $attempt++;
        }
        while($attempt < 10);

        return [];
    }

    /**
     * @param string $url
     * @param string $fakeHost
     */
    protected function queryUrl($url, $fakeHost = null)
    {
        $stopwatch = new \Symfony\Component\Stopwatch\Stopwatch();
        $stopwatch->start('get');

        $this->logger->info(sprintf('Querying url "%s" with host "%s"', $url, $fakeHost));

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'Host' => $fakeHost,
                    'X-Forwarded-Host' => $fakeHost,
                    'X-Forwarded-Proto' => 'https',
                ]
            ]);

            $this->logger->info(sprintf('GET "%s" returned %d %s, took %.2fs',
                $url,
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $stopwatch->stop('get')->getDuration() / 1000.0
            ));
        } catch (\Exception $exception) {
            $this->logger->warning(sprintf('Could not get "%s" because: %s',
                get_class($exception),
                $exception->getMessage()
            ));
        }
    }

    /**
     * @return string
     */
    protected function getCurrentCodeVersion()
    {
        return md5(file_get_contents($this->getComposerLockPath()));
    }

    /**
     * @return string
     */
    protected function getDeployedStaticContentVersion()
    {
        return file_get_contents($this->getDeployedStaticContentVersionPath());
    }

    /**
     * @return string
     */
    protected function getDeployedStaticContentVersionPath()
    {
        return $this->directoryList->getRoot() . '/pub/static/deployed_version.txt';
    }

    protected function getNodeId()
    {
        return gethostname();
    }
}
