<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Testing;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use Throwable;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Support\AttributeEquality;

/**
 * Registers Pest expectations for temporal models. The service provider calls
 * register() during testing; it is a no-op when Pest is not installed.
 *
 * Usage:
 *   expect($product->prices())->validAt('2026-02-15')->knownAt('2026-03-10')
 *       ->toHaveTemporalAttributes(['amount' => 1200]);
 *   expect(fn () => ...)->toThrowTemporalException(SomeException::class);
 */
final class PestExpectations
{
    public $value;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! function_exists('expect')) {
            return;
        }

        self::$registered = true;

        expect()->intercept('validAt', BitemporalBuilder::class, function (CarbonInterface|string $date): mixed {
            /** @var Expectation<BitemporalBuilder<Model>> $this */
            return expect($this->value->validAt($date));
        });

        expect()->intercept('knownAt', BitemporalBuilder::class, function (CarbonInterface|string $date): mixed {
            /** @var Expectation<BitemporalBuilder<Model>> $this */
            return expect($this->value->knownAt($date));
        });

        expect()->extend('toHaveTemporalAttributes', function (array $attributes): mixed {
            /** @var Expectation<BitemporalBuilder<Model>> $this */
            $query = $this->value;
            $row = $query instanceof BitemporalBuilder ? $query->get()->first() : null;

            Assert::assertNotNull($row, 'Expected a temporal row, found none.');

            foreach ($attributes as $key => $expected) {
                Assert::assertTrue(
                    AttributeEquality::equals($row->getAttribute($key), $expected),
                    "Temporal attribute [{$key}] did not match.",
                );
            }

            return $this;
        });

        expect()->extend('toThrowTemporalException', function (string $exception, ?string $messageSubstring = null): mixed {
            /** @var Expectation<callable():mixed> $this */
            $callback = $this->value;

            try {
                $callback();
            } catch (Throwable $thrown) {
                Assert::assertInstanceOf($exception, $thrown);

                if ($messageSubstring !== null) {
                    Assert::assertStringContainsString($messageSubstring, $thrown->getMessage());
                }

                return $this;
            }

            Assert::fail("Expected {$exception} to be thrown; nothing was thrown.");
        });
    }
}
