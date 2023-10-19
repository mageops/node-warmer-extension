<?php

namespace MageOps\NodeWarmer\Service;

class MergedAssetsWarmupUrlsProvider
{
    const ALLOWED_VISIBILITIES = [
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
        \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
    ];

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\Collection
     */
    protected $categoryCollectionFactory;

    /**
     * @var \Magento\UrlRewrite\Model\UrlFinderInterface
     */
    protected $urlFinder;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $url;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\UrlRewrite\Model\UrlFinderInterface $urlFinder,
        \Magento\Framework\UrlInterface $url
    )
    {
        $this->storeManager = $storeManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->urlFinder = $urlFinder;
        $this->url = $url;
    }

    public function getUrls()
    {
        $urls = [];

        foreach ($this->storeManager->getStores() as $store) {
            $urls[] = $store->getBaseUrl();
            $urls[] = $store->getUrl('customer/account/login');
            $urls[] = $store->getUrl('customer/account/create');
            $urls[] = $store->getUrl('customer/account/forgotpassword');
            $urls[] = $store->getUrl('checkout/cart');
            $urls[] = $store->getUrl('catalogsearch/result', ['_query' => ['q' => 'test']]);
            $urls[] = $this->getProductUrl($store);
            $urls[] = $this->getCategoryUrl($store);
        }

        $urls = array_map([$this, 'extractHostAndPath'], $urls);

        return $urls;
    }

    public function getProductUrl($store)
    {
        $productCollection = $this->productCollectionFactory->create();

        $productCollection
            ->addFieldToFilter(
                'status',
                \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
            )
            ->addFieldToFilter(
                'visibility',
                self::ALLOWED_VISIBILITIES
            )
            ->setPageSize(1);

        $product = $productCollection->getFirstItem();

        return sprintf(
            '%scatalog/product/view/id/%d',
            $store->getBaseUrl(),
            $product->getId()
        );
    }

    public function getCategoryUrl($store)
    {
        $categoryCollection = $this->categoryCollectionFactory->create();

        $categoryCollection
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToSelect('*')
            ->setPageSize(1);
        $categoryCollection->getSelect()->orderRand();

        $category = $categoryCollection->getFirstItem();

        return sprintf(
            '%scatalog/category/view/id/%d',
            $store->getBaseUrl(),
            $category->getId()
        );
    }

    protected function extractHostAndPath($url)
    {
        $urlParts = parse_url($url);

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
