<?php

declare(strict_types=1);

namespace MageOps\NodeWarmer\Console\Command;

class WarmNodeCommand extends \Symfony\Component\Console\Command\Command
{
    public function __construct(
        protected \Magento\Framework\App\State $state,
        protected \MageOps\NodeWarmer\Service\NodeWarmer $nodeWarmer,
        protected \Magento\Framework\Filesystem\DriverInterface $filesystemDriver,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setName('cs:warm-node')
            ->setDescription('Warms node cache and optionally clears cache if new code is detected. This command shall be ran when new app node is added as the first thing on it.')
            ->addOption('force', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Force even if already warm')
            ->addOption('local-url', 'u', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Url of the local app instance', 'http://localhost:80');
    }

    private function setAreaCode(): void
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
    }

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int
    {
        $this->setAreaCode();

        $force = $input->getOption('force');
        $localUrl = trim($input->getOption('local-url'), '"');

        try {
            @$this->nodeWarmer->warmNodeUp($localUrl, $force); // phpcs:ignore
            $output->writeln(sprintf('Done, output saved to "%s"', $this->nodeWarmer->getWarmupLogFilePath()));
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $exception) {
            $message = sprintf('Warmup did not complete, generated WARMUP file anyway: %s', (string)$exception);
            $output->writeln($message);
            $this->filesystemDriver->filePutContents($this->nodeWarmer->getWarmupLogFilePath(), $message);
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}
