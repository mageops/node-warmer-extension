<?php

namespace MageOps\NodeWarmer\Model;

class Config
{
    const CACHE_CODE_VERSION_PATH = 'node_warmer/cache_code_version';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    private $configWriter;


    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
    }

    /**
     * @return bool
     */
    public function getCacheCodeVersion()
    {
        return $this->scopeConfig->getValue(self::CACHE_CODE_VERSION_PATH);
    }

    /**
     * @param string $newVersion
     */
    public function updateCacheCodeVersion($newVersion)
    {
        $this->configWriter->save(self::CACHE_CODE_VERSION_PATH, $newVersion);
    }
}