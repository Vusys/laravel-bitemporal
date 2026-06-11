<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;
use Vusys\Bitemporal\Boot\Lints\BootLintMutableDatetimeCast;
use Vusys\Bitemporal\Events\TemporalBootLintRaised;

/**
 * Runs the advisory boot lints. Each raised lint is logged at warning level and
 * dispatched as TemporalBootLintRaised. Lints never block boot. Per-model
 * suppression is read from the model's $suppressedBootLints property.
 */
final readonly class BootLints
{
    /**
     * @param  array<int, BootLint>  $lints
     */
    public function __construct(private array $lints) {}

    public static function default(): self
    {
        return new self([
            new BootLintCompactionExcludesDomainColumn,
            new BootLintMutableDatetimeCast,
        ]);
    }

    /**
     * Run every (non-suppressed) lint against the model. Returns the raised
     * lints as [lintClass => message]; also logs and dispatches each.
     *
     * @return array<class-string, string>
     */
    public function runAgainst(Model $model, bool $dispatch = true): array
    {
        $suppressed = $this->suppressedLints($model);
        $raised = [];

        foreach ($this->lints as $lint) {
            $class = $lint::class;

            if (in_array($class, $suppressed, true)) {
                continue;
            }

            $message = $lint->check($model);

            if ($message === null) {
                continue;
            }

            $shortName = new ReflectionClass($lint)->getShortName();
            $raised[$class] = $message;

            if ($dispatch) {
                Log::warning("[{$shortName}] {$message}", ['model' => $model::class]);
                event(new TemporalBootLintRaised($model::class, $class, $message));
            }
        }

        return $raised;
    }

    /**
     * @return array<int, string>
     */
    private function suppressedLints(Model $model): array
    {
        if (! property_exists($model, 'suppressedBootLints')) {
            return [];
        }

        // Read via reflection: Eloquent's __get would intercept a protected
        // property access and return a (null) attribute instead.
        $value = new \ReflectionProperty($model, 'suppressedBootLints')->getValue($model);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
