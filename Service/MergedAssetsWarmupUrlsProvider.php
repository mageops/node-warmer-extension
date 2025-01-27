<?php

declare(strict_types=1);

namespace MageOps\NodeWarmer\Service;

class MergedAssetsWarmupUrlsProvider
{
    public function __construct(
        protected \Magento\Framework\View\LayoutInterfaceFactory $layoutFactory,
        protected \Magento\Framework\View\Page\ConfigFactory $pageConfigFactory,
        protected \Magento\Framework\View\DesignInterface $design,
        protected \Magento\Framework\View\Asset\MergeService $mergeService,
        protected \Magento\Store\Model\StoreManagerInterface $storeManager,
        protected \Magento\Store\Model\App\Emulation $emulation,
        protected array $layoutHandles
    ) {
    }

    public function getUrls(): array
    {
        $staticStoreUrls = [];
        foreach ($this->storeManager->getStores() as $store) {
            $staticStoreUrls[] = $this->getAssetsUrls($store);
        }
        $staticStoreUrls = array_unique(array_merge(...$staticStoreUrls));
        $urls = array_map([$this, 'extractHostAndPath'], $staticStoreUrls);

        return $urls;
    }

    protected function getAssetUrlsByContentType(string $contentType): array
    {
        $group = $this->pageConfigFactory->create()->getAssetCollection()->getGroupByContentType($contentType);
        $assets = $this->mergeService->getMergedAssets($group->getAll(), $contentType);

        $urls = [];
        foreach ($assets as $asset) {
            $urls[] = $asset->getUrl();
        }

        return $urls;
    }

    protected function getAssetsUrls(\Magento\Store\Api\Data\StoreInterface $store): array
    {
        $this->emulation->startEnvironmentEmulation($store->getId());
        $layout = $this->layoutFactory->create();
        $layout->getUpdate()->load($this->layoutHandles);
        $layout->generateXml();
        $layout->generateElements();
        $assets = array_merge(
            $this->getAssetUrlsByContentType(\Magento\Framework\View\Design\Theme\Customization\File\Js::CONTENT_TYPE),
            $this->getAssetUrlsByContentType(\Magento\Framework\View\Design\Theme\Customization\File\Css::CONTENT_TYPE)
        );
        $this->emulation->stopEnvironmentEmulation();

        return $assets;
    }

    protected function extractHostAndPath(string $url): array
    {
        $urlParts = parse_url($url); // phpcs:ignore Magento2.Functions.DiscouragedFunction

        $path = $urlParts['path'];

        if (isset($urlParts['query']) && !empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        return [
            'host' => $urlParts['host'],
            'path' => $path
        ];
    }
}
