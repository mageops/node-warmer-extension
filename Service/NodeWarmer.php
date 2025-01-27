<?php

declare(strict_types=1);

namespace MageOps\NodeWarmer\Service;

class NodeWarmer
{
    public const WARM_LOG_FILENAME = 'WARMUP';
    public const WARMUP_TIMEOUT = 60;
    public const WARMUP_REQUEST_BATCH = 32;

    protected int $warmupRequestBatch = self::WARMUP_REQUEST_BATCH;
    protected ?\GuzzleHttp\Client $http;
    protected ?\MageOps\NodeWarmer\Log\CapturingLoggerDecorator $logger;

    public function __construct(
        protected \MageOps\NodeWarmer\Model\Config $config,
        protected \Magento\Framework\Event\ManagerInterface $eventManager,
        protected \Magento\Framework\App\Cache\Manager $cacheManager,
        protected \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        protected \Magento\Store\Model\StoreManagerInterface $storeManager,
        protected \Magento\Framework\UrlInterface $urlGenerator,
        protected MergedAssetsWarmupUrlsProvider $mergedAssetsWarmupUrlsProvider,
        protected \Psr\Log\LoggerInterface $psrLogger,
        protected \Magento\Framework\Filesystem\DriverInterface $filesystemDriver,
        protected \Symfony\Component\Stopwatch\StopwatchFactory $stopwatchFactory,
        protected \MageOps\NodeWarmer\Log\LogFormatter $logFormatter,
    ) {
        $this->logger = new \MageOps\NodeWarmer\Log\CapturingLoggerDecorator($psrLogger);
        $this->http = new \GuzzleHttp\Client([
            'timeout' => self::WARMUP_TIMEOUT,
            'allow_redirects' => true,
            'http_errors' => false,
        ]);
    }

    public function warmNodeUp(string $localUrl, bool $force = false): void
    {
        $codeVersion = $this->getCurrentCodeVersion();
        $deployedStaticContentVersion = $this->getDeployedStaticContentVersion() . uniqid();

        $this->logger->info(sprintf('Starting warmup for node "%s"', $this->getNodeId()));

        if ($this->filesystemDriver->isExists($this->getWarmupLogFilePath()) && !$force) {
            $this->logger->info('Skipping warmup, already warm...');
            return;
        }

        $stopwatch = $this->stopwatchFactory->create();
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

        if ($this->config->getDeployedStaticContentVersion() == $deployedStaticContentVersion) {
            $took = $stopwatch->stop('warmup')->getDuration() / 1000.0;

            $this->logger->info(sprintf('All done, took %.2fs', $took));
            $this->saveWarmupLog();
            return;
        }

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
                        $this->warmupRequestBatch = max(1, $this->warmupRequestBatch - 1);
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

        $took = $stopwatch->stop('warmup')->getDuration() / 1000.0;

        $this->logger->info(sprintf('All done, took %.2fs', $took));
        $this->saveWarmupLog();
    }

    protected function flushCache(): void
    {
        $this->eventManager->dispatch('adminhtml_cache_flush_all');
        $this->cacheManager->flush($this->cacheManager->getAvailableTypes());
    }

    /**
     * @return string
     */
    protected function getComposerLockPath(): string
    {
        return $this->directoryList->getRoot() . '/composer.lock';
    }

    public function getWarmupLogFilePath(): string
    {
        return $this->directoryList->getPath(\Magento\Framework\App\Filesystem\DirectoryList::PUB) . \DIRECTORY_SEPARATOR . self::WARM_LOG_FILENAME;
    }

    protected function saveWarmupLog(): void
    {
        $path = $this->getWarmupLogFilePath();
        $content = $this->logFormatter->formatBatch($this->logger->flush());

        $handle = $this->filesystemDriver->fileOpen($path, 'w');
        $this->filesystemDriver->fileWrite($handle, $content);
        $this->filesystemDriver->fileClose($handle);
    }

    protected function getUrlsToBeWarmedUp(): array
    {
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
        } while ($attempt < 10);

        return [];
    }

    protected function queryUrl(\GuzzleHttp\Promise\PromiseInterface $promise, string $url, string $host): void
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

    protected function getCurrentCodeVersion(): string
    {
        try {
            $composerLockContent = $this->filesystemDriver->fileGetContents($this->getComposerLockPath());
            return md5($composerLockContent); // phpcs:ignore
        } catch (\Magento\Framework\Exception\FileSystemException $e) {
            return '';
        }
    }

    protected function getDeployedStaticContentVersion(): string
    {
        try {
            return $this->filesystemDriver->fileGetContents($this->getDeployedStaticContentVersionPath());
        } catch (\Magento\Framework\Exception\FileSystemException $e) {
            return '';
        }
    }

    protected function getDeployedStaticContentVersionPath(): string
    {
        return $this->directoryList->getPath(\Magento\Framework\App\Filesystem\DirectoryList::STATIC_VIEW) . \DIRECTORY_SEPARATOR . 'deployed_version.txt';
    }

    protected function getNodeId(): string
    {
        return (string)gethostname();
    }
}
