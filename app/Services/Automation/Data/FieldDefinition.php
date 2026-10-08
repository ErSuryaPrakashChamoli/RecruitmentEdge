<?php

namespace App\Services\Automation\Data;

use App\Services\Automation\AutomationContext;
use Closure;

/**
 * One condition field. `resolver` reads the value from an AutomationContext — the only way a
 * condition can see data, so rules can never reach arbitrary attributes or execute code.
 */
final readonly class FieldDefinition
{
    /**
     * @param  'enum'|'string'|'number'|'boolean'|'datetime'  $type
     * @param  array<string, string>|(Closure(): array<string, string>)  $options
     * @param  Closure(AutomationContext): mixed  $resolver
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public string $type,
        public Closure $resolver,
        public array|Closure $options = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->options instanceof Closure ? ($this->options)() : $this->options;
    }
}
