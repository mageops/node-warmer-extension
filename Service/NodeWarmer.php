<?php

namespace MageOps\NodeWarmer\Service;

class NodeWarmer
{
    const WARM_LOG_FILENAME = 'WARMUP';
    const WARMUP_TIMEOUT = 60;

    /**
     * @var \MageOps\NodeWarmer\Model\Config
     */
    private $config;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var \Magento\Framework\App\Cache\Manager
     */
    private $cacheManager;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    private $directoryList;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlGenerator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \GuzzleHttp\Client
     */
    private $http;

    public function __construct(
        \MageOps\NodeWarmer\Model\Config $config,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\Cache\Manager $cacheManager,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlGenerator,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->eventManager = $eventManager;
        $this->cacheManager = $cacheManager;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->urlGenerator = $urlGenerator;
        $this->logger = new \MageOps\NodeWarmer\Log\CapturingLoggerDecorator($logger);
        $this->http = new \GuzzleHttp\Client([
            'timeout' => self::WARMUP_TIMEOUT,
            'allow_redirects' => true,
            'http_errors' => false,
        ]);
    }

    private function flushCache()
    {
        $this->eventManager->dispatch('adminhtml_cache_flush_all');
        $this->cacheManager->flush($this->cacheManager->getAvailableTypes());
    }

    /**
     * @return string
     */
    private function getComposerLockPath()
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

    private function saveWarmupLog()
    {
        $path = $this->getWarmupLogFilePath();
        $formatter = new \MageOps\NodeWarmer\Log\LogFormatter();

        file_put_contents(
            $path,
            $formatter->formatBatch($this->logger->flush())
        );
    }

    /**
     * @return string
     */
    private function getDefaultHost()
    {
        return parse_url(
            $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB),
            PHP_URL_HOST
        );
    }

    /**
     * @param string $url
     * @param string $fakeHost
     */
    private function queryUrl($url, $fakeHost = null)
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
            $this->logger->warning(sprintf('Could not get "%s" because %s: %s',
                get_class($exception),
                $exception->getMessage()
            ));
        }
    }

    /**
     * @param string $route
     * @return string
     */
    private function generateRoutePath($route)
    {
        return parse_url($this->urlGenerator->getUrl($route), PHP_URL_PATH);
    }

    private function getWarmupPaths()
    {
        return [
            '/',
            $this->generateRoutePath('checkout/cart'),
            '/contentconstructor/components',
            '/contentconstructor/components/index/page/herocarousel-large/',
            '/contentconstructor/components/index/page/herocarousel-slider/',
            '/contentconstructor/components/index/page/herocarousel-hidden/',
            '/contentconstructor/components/index/page/itlegacywindowwidth/',
            '/contentconstructor/components/index/page/itlegacycontainerwidth/',
            '/contentconstructor/components/index/page/itlegacywindowwidthslider/',
            '/contentconstructor/components/index/page/itlegacycontainerwidthslider/',
            '/contentconstructor/components/index/page/itwindowwidth/',
            '/contentconstructor/components/index/page/itcontainerwidth/',
            '/contentconstructor/components/index/page/itwindowwidthslider/',
            '/contentconstructor/components/index/page/itcontentwidthslider/',
            '/contentconstructor/components/index/page/contrastoptimizers/',
            '/contentconstructor/components/index/page/ttwindowwidth/',
            '/contentconstructor/components/index/page/ttcontainerwidth/',
            '/contentconstructor/components/index/page/icon/',
            '/contentconstructor/components/index/page/productgridnohero/',
            '/contentconstructor/components/index/page/productgridheroleft/',
            '/contentconstructor/components/index/page/productgridheroright/',
            '/contentconstructor/components/index/page/headline/',
            '/contentconstructor/components/index/page/paragraph/'
        ];
    }

    /**
     * @param string $localUrl
     */
    private function warmup($localUrl)
    {
        $hostname = $this->getDefaultHost();

        foreach ($this->getWarmupPaths() as $path) {
            $this->queryUrl($localUrl . $path, $hostname);
        }
    }

    /**
     * @return string
     */
    private function getCurrentCodeVersion()
    {
        return md5(file_get_contents($this->getComposerLockPath()));
    }

    private function getNodeId()
    {
        return gethostname();
    }

    /**
     * @param bool $force
     * @param string $localUrl
     */
    public function warmNodeUp($localUrl, $force = false)
    {
        $codeVersion = $this->getCurrentCodeVersion();
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

        $this->logger->info(sprintf('Warming up URLs using "%s"', $localUrl));
        $this->warmup($localUrl);

        $took = $stopwatch->stop('warmup')->getDuration() / 1000.0;

        $this->logger->info(sprintf('All done, took %.2fs', $took));
        $this->saveWarmupLog();
    }

}