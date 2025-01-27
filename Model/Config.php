<?php

declare(strict_types=1);

namespace MageOps\NodeWarmer\Model;

class Config
{
    public const CACHE_CODE_VERSION_PATH = 'node_warmer/cache_code_version';
    public const DEPLOYED_STATIC_CONTENT_VERSION_PATH = 'node_warmer/deployed_static_content_version';

    public function __construct(
        protected \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory $configCollectionFactory,
        protected \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    ) {
    }

    /**
     * @return bool
     */
    public function getCacheCodeVersion(): bool
    {
        return $this->getUncachedConfigValue(self::CACHE_CODE_VERSION_PATH);
    }

    /**
     * @param string $newVersion
     */
    public function updateCacheCodeVersion(string $newVersion): void
    {
        $this->configWriter->save(self::CACHE_CODE_VERSION_PATH, $newVersion);
    }

    public function getDeployedStaticContentVersion(): ?string
    {
        return $this->getUncachedConfigValue(self::DEPLOYED_STATIC_CONTENT_VERSION_PATH);
    }

    public function updateDeployedStaticContentVersion(string $newVersion): void
    {
        $this->configWriter->save(self::DEPLOYED_STATIC_CONTENT_VERSION_PATH, $newVersion);
    }

    /**
     * Standard ScopeConfig can return value cached in redis
     * For this module we always need value directly from database
     */
    protected function getUncachedConfigValue(string $path): ?string {
        $configCollection = $this->configCollectionFactory->create();
        $configCollection->addFieldToFilter('path', ['eq' => $path]);

        $config =  $configCollection->getFirstItem();

        if ($config === null) {
            return null;
        }

        return $config->getValue();
    }
}
