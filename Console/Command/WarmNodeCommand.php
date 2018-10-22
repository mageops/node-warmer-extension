<?php

namespace MageOps\NodeWarmer\Console\Command;


class WarmNodeCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var \Magento\Framework\App\State
     */
    private $state;

    /**
     * @var \MageOps\NodeWarmer\Service\NodeWarmer
     */
    private $nodeWarmer;

    public function __construct(
        \Magento\Framework\App\State $state,
        \MageOps\NodeWarmer\Service\NodeWarmer $nodeWarmer
    )
    {
        parent::__construct();

        $this->state = $state;
        $this->nodeWarmer = $nodeWarmer;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('cs:warm-node')
            ->setDescription('Warms node cache and optionally clears cache if new code is detected. This command shall be ran when new app node is added as the first thing on it.')
            ->addOption('force', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Force even if already warm')
            ->addOption('local-url', 'u', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Url of the local app instance', 'http://localhost:80');
    }

    private function setAreaCode()
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    )
    {
        $this->setAreaCode();

        $force = $input->getOption('force');
        $localUrl = $input->getOption('local-url');

        try {
            @$this->nodeWarmer->warmNodeUp($localUrl, $force);
            $output->writeln(sprintf('Done, output saved to "%s"', $this->nodeWarmer->getWarmupLogFilePath()));
        } catch (\Exception $exception) {
            $message = sprintf('Warmup did not complete, generated WARMUP file anyway: %s', (string)$exception);

            $output->writeln($message);

            file_put_contents($this->nodeWarmer->getWarmupLogFilePath(), $message);
        }
    }
}
