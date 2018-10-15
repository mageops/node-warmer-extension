<?php

namespace MageOps\NodeWarmer\Log;

class LogFormatter implements \Monolog\Formatter\FormatterInterface
{
    public function format(array $record)
    {
        return sprintf('[%s] [%s] %s',
            date('Y-m-d H:i:s', $record[0]),
            $record[1],
            $record[2]
        );
    }

    public function formatBatch(array $records)
    {
        return implode("\n", array_map([$this, 'format'], $records));
    }
}