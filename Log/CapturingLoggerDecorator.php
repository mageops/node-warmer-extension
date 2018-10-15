<?php

namespace MageOps\NodeWarmer\Log;

class CapturingLoggerDecorator extends \Psr\Log\AbstractLogger implements \Psr\Log\LoggerInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $upstreamLogger;

    /**
     * @var array
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
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, array $context = array())
    {
        $this->upstreamLogger->log($level, $message, $context);
        $this->buffer[] = [time(), $level, $message];
    }
}