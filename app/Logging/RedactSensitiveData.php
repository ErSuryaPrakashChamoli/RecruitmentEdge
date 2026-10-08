<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Phase 8.7 (SEC-87-07): log tap — every record written by the application's log channels passes
 * through SensitiveDataRedactor (message and context; exception messages included).
 */
class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            $context = SensitiveDataRedactor::context(array_map(
                // An exception keeps its class, location and trace; only its message is redacted.
                fn (mixed $value) => $value instanceof \Throwable
                    ? $value::class.': '.SensitiveDataRedactor::text($value->getMessage()).' in '.$value->getFile().':'.$value->getLine()."\n".SensitiveDataRedactor::text($value->getTraceAsString())
                    : $value,
                $record->context,
            ));

            return $record->with(message: (string) SensitiveDataRedactor::text($record->message), context: $context);
        });
    }
}
