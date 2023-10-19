<?php

namespace MageOps\NodeWarmer\Service;

class NodeWarmer
{
    const WARM_LOG_FILENAME = 'WARMUP';
    const WARMUP_TIMEOUT = 60;
    const WARMUP_REQUEST_BATCH = 32;

    /**
     * @var int
     */
    protected $warmupRequestBatch = self::WARMUP_REQUEST_BATCH;

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

            if (!empty($urls)) {
                foreach (array_chunk($urls, $this->warmupRequestBatch) as $urlBatch) {
                    $asyncOperations = [];

                    foreach ($urlBatch as $url) {
                        $uri = $localUrl . $url['path'];
                        $asyncOperations[] = [
                            'promise' => $this->http->getAsync(
                                $uri,
                                [
                                    'headers' => [
                                        'Host' => $url['host'],
                                        'X-Forwarded-Host' => $url['host'],
                                        'X-Forwarded-Proto' => 'https',
                                        'User-Agent' => 'Node Warmer'
                                    ]
                                ]
                            ),
                            'url' => $uri,
                            'host' => $url['host'],
                            'path' => $url['path']
                        ];
                    }

                    foreach ($asyncOperations as $asyncOperation) {
                        try {
                            $this->queryUrl($asyncOperation['promise'], $asyncOperation['url'], $asyncOperation['host']);
                        }catch(\Exception $exception) {
                            // Reduce parallel requests if we get a eg. 503
                            if ($this->warmupRequestBatch > 1) {
                                $this->warmupRequestBatch -= 1;
                            }
                            // Retry failed requests
                            $urls[] = [
                                'host' => $asyncOperation['host'],
                                'path' => $asyncOperation['path']
                            ];
                        }
                    }
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
                return $this->mergedAssetsWarmupUrlsProvider->getUrls();
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
     * @param \GuzzleHttp\Promise\PromiseInterface $promise
     * @param string $url
     * @param string $host
     * @throws \Exception
     * @return void
     */
    protected function queryUrl(\GuzzleHttp\Promise\PromiseInterface $promise, string $url, string $host)
    {
        $this->logger->info(sprintf('Querying url "%s" with host "%s"', $url, $host));

        try {
            /** @var \GuzzleHttp\Psr7\Response $response */
            $response = $promise->wait();
            $this->logger->info(sprintf('GET "%s" returned %d %s',
                $url,
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));
        } catch (\Exception $exception) {
            $this->logger->warning(sprintf('Could not get "%s" because: %s',
                get_class($exception),
                $exception->getMessage()
            ));
            throw $exception;
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
