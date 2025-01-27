<?php

declare(strict_types=1);

namespace MageOps\NodeWarmer\Log;

class CapturingLoggerDecorator extends \Psr\Log\AbstractLogger implements \Psr\Log\LoggerInterface
{
    protected array $buffer = [];
    public function __construct(
        protected \Psr\Log\LoggerInterface $upstreamLogger
    ) {
    }

    /**
     * @return array
     */
    public function flush(): array
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        return $buffer;
    }

    public function log($level, $message, array $context = []): void //phpcs:ignore
    {
        $this->upstreamLogger->log($level, $message, $context);
        $this->buffer[] = [time(), $level, $message];
    }
}
