<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing\Mutation {

    use Closure;
    use PHPUnit\Framework\AssertionFailedError;
    use RuntimeException;
    use Vusys\Bitemporal\BitemporalBuilder;
    use Vusys\Bitemporal\Exceptions\TemporalException;
    use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
    use Vusys\Bitemporal\Testing\PestExpectations;
    use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
    use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

    /**
     * Minimal stand-in for Pest's Expectation so the closures registered by
     * PestExpectations actually run under plain PHPUnit (Pest is not installed
     * here). The global expect() helper at the bottom of this file points at it,
     * which lets PestExpectations::register() wire its interceptors/extensions
     * onto this shim exactly as it would onto Pest.
     *
     * @method self validAt(\Carbon\CarbonInterface|string $date)
     * @method self knownAt(\Carbon\CarbonInterface|string $date)
     * @method self toHaveTemporalAttributes(array<string, mixed> $attributes)
     * @method self toThrowTemporalException(string $exception, ?string $messageSubstring = null)
     */
    final class PestExpectationShim
    {
        public mixed $value;

        /** @var array<string, array{class: string, handler: Closure}> */
        public static array $intercepts = [];

        /** @var array<string, Closure> */
        public static array $extends = [];

        public function __construct(mixed $value = null)
        {
            $this->value = $value;
        }

        public function intercept(string $name, string $class, Closure $handler): self
        {
            self::$intercepts[$name] = ['class' => $class, 'handler' => $handler];

            return $this;
        }

        public function extend(string $name, Closure $handler): self
        {
            self::$extends[$name] = $handler;

            return $this;
        }

        /**
         * @param  array<int, mixed>  $arguments
         */
        public function __call(string $name, array $arguments): mixed
        {
            if (isset(self::$intercepts[$name]) && $this->value instanceof self::$intercepts[$name]['class']) {
                $handler = Closure::bind(self::$intercepts[$name]['handler'], $this, self::class);

                return $handler(...$arguments);
            }

            if (isset(self::$extends[$name])) {
                $handler = Closure::bind(self::$extends[$name], $this, self::class);

                return $handler(...$arguments);
            }

            throw new RuntimeException("No expectation [{$name}] is registered.");
        }
    }

    final class PestExpectationsMutationTest extends IntegrationTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            // The service provider already calls this during boot (expect() now
            // exists), but call it again defensively so the expectations are
            // guaranteed to be registered for this test.
            PestExpectations::register();
        }

        // ----- toHaveTemporalAttributes ------------------------------------

        public function test_to_have_temporal_attributes_passes(): void
        {
            $builder = $this->builderWithPrice(1000);

            expect($builder)->validAt('2026-03-01')->toHaveTemporalAttributes(['amount' => 1000]);
        }

        public function test_known_at_interceptor_passes(): void
        {
            $builder = $this->builderWithPrice(1000);

            expect($builder)->validAt('2026-03-01')->knownAt('2026-03-10')->toHaveTemporalAttributes(['amount' => 1000]);
        }

        public function test_to_have_temporal_attributes_wrong_value_throws(): void
        {
            $builder = $this->builderWithPrice(1000);

            $this->assertAssertionFails(
                fn () => expect($builder)->validAt('2026-03-01')->toHaveTemporalAttributes(['amount' => 9999]),
                'Temporal attribute [amount] did not match.',
            );
        }

        public function test_to_have_temporal_attributes_no_row_throws(): void
        {
            $builder = ProductPrice::query()->where('product_id', 999999);

            $this->assertAssertionFails(
                fn () => expect($builder)->toHaveTemporalAttributes([]),
                'Expected a temporal row, found none.',
            );
        }

        public function test_to_have_temporal_attributes_requires_a_bitemporal_builder(): void
        {
            // A non-BitemporalBuilder value must resolve to a null row even when
            // the underlying relation has matching rows.
            $product = $this->makeProduct();
            $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

            $this->assertAssertionFails(
                fn () => expect($product->prices())->toHaveTemporalAttributes(['amount' => 1000]),
                'Expected a temporal row, found none.',
            );
        }

        // ----- toThrowTemporalException ------------------------------------

        public function test_to_throw_temporal_exception_passes(): void
        {
            expect(fn () => throw new TemporalInvalidSpellException('boom'))
                ->toThrowTemporalException(TemporalInvalidSpellException::class);
        }

        public function test_to_throw_temporal_exception_with_message_passes(): void
        {
            expect(fn () => throw new TemporalInvalidSpellException('it went boom'))
                ->toThrowTemporalException(TemporalInvalidSpellException::class, 'boom');
        }

        public function test_to_throw_temporal_exception_wrong_type_throws(): void
        {
            $this->assertAssertionFails(
                fn () => expect(fn () => throw new RuntimeException('nope'))
                    ->toThrowTemporalException(TemporalException::class),
                'instance of class '.TemporalException::class,
            );
        }

        public function test_to_throw_temporal_exception_nothing_thrown_throws(): void
        {
            $this->assertAssertionFails(
                fn () => expect(fn () => null)->toThrowTemporalException(TemporalInvalidSpellException::class),
                'Expected '.TemporalInvalidSpellException::class.' to be thrown; nothing was thrown.',
            );
        }

        public function test_to_throw_temporal_exception_message_mismatch_throws(): void
        {
            $this->assertAssertionFails(
                fn () => expect(fn () => throw new TemporalInvalidSpellException('boom'))
                    ->toThrowTemporalException(TemporalInvalidSpellException::class, 'NOT-PRESENT'),
                'NOT-PRESENT',
            );
        }

        // ----- helpers ------------------------------------------------------

        /**
         * @return BitemporalBuilder<ProductPrice>
         */
        private function builderWithPrice(int $amount): BitemporalBuilder
        {
            $product = $this->makeProduct();
            $this->insertPrice($product, ['amount' => $amount, 'valid_from' => '2026-01-01', 'valid_to' => null]);

            return ProductPrice::query()->where('product_id', $product->getKey());
        }

        private function assertAssertionFails(callable $fn, string $expectedPrefix): void
        {
            try {
                $fn();
            } catch (AssertionFailedError $e) {
                $this->assertStringContainsString($expectedPrefix, $e->getMessage());

                return;
            }

            $this->fail('Expected an AssertionFailedError to be thrown.');
        }
    }
}

namespace {
    use Vusys\Bitemporal\Tests\Integration\Testing\Mutation\PestExpectationShim;

    if (! function_exists('expect')) {
        function expect(mixed $value = null): PestExpectationShim
        {
            return new PestExpectationShim($value);
        }
    }
}
