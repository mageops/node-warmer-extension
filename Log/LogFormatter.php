<?php

namespace MageOps\NodeWarmer\Log;

class LogFormatter implements \Monolog\Formatter\FormatterInterface
{
    public function format(\Monolog\LogRecord $record)
    {
        return sprintf('[%s] [%s] %s',
            $record->datetime->format('Y-m-d H:i:s'),
            $record->level->getName(),
            $record->message
        );
    }

    public function formatBatch(array $records)
    {
        foreach ($records as $key => $record) {
            $records[$key] = new \Monolog\LogRecord(
                message: $record[2],
                level: \Monolog\Level::fromName($record[1]),
                channel: 'monolog',
                datetime: new \Monolog\JsonSerializableDateTimeImmutable(true),
            );
        }

        return implode("\n", array_map([$this, 'format'], $records));
    }
}