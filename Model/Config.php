<?php

namespace MageOps\NodeWarmer\Model;

class Config
{
    const CACHE_CODE_VERSION_PATH = 'node_warmer/cache_code_version';
    const DEPLOYED_STATIC_CONTENT_VERSION_PATH = 'node_warmer/deployed_static_content_version';

    /**
     * @var \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory
     */
    protected $configCollectionFactory;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    protected $configWriter;

    public function __construct(
        \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory $configCollectionFactory,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    )
    {
        $this->configWriter = $configWriter;
        $this->configCollectionFactory = $configCollectionFactory;
    }

    /**
     * @return bool
     */
    public function getCacheCodeVersion()
    {
        return $this->getUncachedConfigValue(self::CACHE_CODE_VERSION_PATH);
    }

    /**
     * @param string $newVersion
     */
    public function updateCacheCodeVersion($newVersion)
    {
        $this->configWriter->save(self::CACHE_CODE_VERSION_PATH, $newVersion);
    }

    /**
     * @return bool
     */
    public function getDeployedStaticContentVersion()
    {
        return $this->getUncachedConfigValue(self::DEPLOYED_STATIC_CONTENT_VERSION_PATH);
    }

    /**
     * @param string $newVersion
     */
    public function updateDeployedStaticContentVersion($newVersion)
    {
        $this->configWriter->save(self::DEPLOYED_STATIC_CONTENT_VERSION_PATH, $newVersion);
    }

    /**
     * Standard ScopeConfig can return value cached in redis
     * For this module we always need value directly from database
     * @param $path
     * @return string|null
     */
    protected function getUncachedConfigValue($path): ?string {
        $configCollection = $this->configCollectionFactory->create();
        $configCollection->addFieldToFilter('path', ['eq' => $path]);

        $config =  $configCollection->getFirstItem();

        if($config === null) {
            return null;
        }

        return $config->getValue();
    }
}
