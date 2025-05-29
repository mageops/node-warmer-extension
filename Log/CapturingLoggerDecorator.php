<?php

namespace MageOps\NodeWarmer\Log;

class CapturingLoggerDecorator extends \Psr\Log\AbstractLogger implements \Psr\Log\LoggerInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $upstreamLogger;

    /**
     * @var \Monolog\LogRecord[]
     */
    private $buffer = [];

    /**
     * @param \Psr\Log\LoggerInterface $upstreamLogger
     */
    public function __construct(\Psr\Log\LoggerInterface $upstreamLogger)
    {
        $this->upstreamLogger = $upstreamLogger;
    }

    /**
     * @return array
     */
    public function flush()
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        return $buffer;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->upstreamLogger->log($level, $message, $context);
        $this->buffer[] = new \Monolog\LogRecord(
            message: $message,
            level: \Monolog\Level::fromName($level),
            channel: 'monolog',
            datetime: new \Monolog\JsonSerializableDateTimeImmutable(true),
        );
    }
}