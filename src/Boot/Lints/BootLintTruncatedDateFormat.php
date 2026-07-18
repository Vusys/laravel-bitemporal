<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Lints;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootLint;

/**
 * The model's effective $dateFormat has no sub-second precision. The trait
 * defaults to microsecond precision, so this only fires when a model declares
 * its own $dateFormat that drops fractional seconds — Eloquent would then
 * truncate the writer's microsecond instants on save, collapsing distinct
 * recorded/valid boundaries within the same second and risking overlaps.
 */
final class BootLintTruncatedDateFormat implements BootLint
{
    public function check(Model $model): ?string
    {
        $format = $model->getDateFormat();

        // Drop escaped literals (\u etc.) before looking for a real format token.
        $tokens = preg_replace('/\\\\./', '', $format) ?? $format;

        if (str_contains($tokens, 'u') || str_contains($tokens, 'v')) {
            return null;
        }

        return "the model's \$dateFormat ('{$format}') has no sub-second precision, "
            .'so microsecond instants are truncated when temporal rows are saved. '
            .'Use a format with fractional seconds (e.g. Y-m-d H:i:s.u), or unset '
            .'$dateFormat to accept the trait default.';
    }
}
