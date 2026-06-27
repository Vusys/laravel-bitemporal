<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Support\Mutation;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Support\MorphContext;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Mutation coverage for {@see MorphContext}: constructor + fromModel guard,
 * equals() comparison branches, and the "type:id" string format.
 */
final class MorphContextMutationTest extends TestCase
{
    private function modelWithKey(mixed $key, string $morph = 'widget'): Model
    {
        return new class($key, $morph) extends Model
        {
            public function __construct(private readonly mixed $k = null, private readonly string $m = 'widget')
            {
                parent::__construct();
            }

            #[\Override]
            public function getKey(): mixed
            {
                return $this->k;
            }

            #[\Override]
            public function getMorphClass(): string
            {
                return $this->m;
            }
        };
    }

    public function test_from_model_accepts_an_int_key(): void
    {
        $context = MorphContext::fromModel($this->modelWithKey(42, 'product'));

        $this->assertSame('product', $context->type);
        $this->assertSame(42, $context->id);
    }

    // Kills LogicalNot: `!is_int($key) && is_string($key)` would throw on a
    // perfectly valid string key.
    public function test_from_model_accepts_a_string_key_without_throwing(): void
    {
        $context = MorphContext::fromModel($this->modelWithKey('abc-1', 'product'));

        $this->assertSame('product', $context->type);
        $this->assertSame('abc-1', $context->id);
    }

    // Kills LogicalAndAllSubExprNegation + Throw_ (removing the throw lets a
    // float reach the int|string-typed constructor -> TypeError, not
    // InvalidArgumentException) and the message Concat / ConcatOperandRemoval
    // mutants.
    public function test_from_model_rejects_a_non_scalar_key(): void
    {
        try {
            MorphContext::fromModel($this->modelWithKey(1.5));
            $this->fail('Expected an InvalidArgumentException for a float key.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringStartsWith('temporal entity key must be an int or string; got ', $exception->getMessage());
            $this->assertStringContainsString('float', $exception->getMessage());
        }
    }

    // Kills the equals() Identical / LogicalAnd* mutants: an exact match must be
    // true, and either axis differing must be false. Also kills PublicVisibility
    // (this call is from outside the class).
    public function test_equals_requires_both_type_and_id(): void
    {
        $base = new MorphContext('customer', 7);

        $this->assertTrue($base->equals(new MorphContext('customer', 7)));
        $this->assertFalse($base->equals(new MorphContext('supplier', 7)));
        $this->assertFalse($base->equals(new MorphContext('customer', 8)));
    }

    // Kills both CastString mutants: ids are compared as strings, so 5 (int)
    // and '5' (string) are equal in either argument position.
    public function test_equals_coerces_ids_to_strings(): void
    {
        $this->assertTrue(new MorphContext('customer', 5)->equals(new MorphContext('customer', '5')));
        $this->assertTrue(new MorphContext('customer', '5')->equals(new MorphContext('customer', 5)));
    }

    // Kills the __toString Concat / ConcatOperandRemoval mutants.
    public function test_string_form_is_type_colon_id(): void
    {
        $this->assertSame('customer:42', (string) new MorphContext('customer', 42));
        $this->assertSame('customer:42', new MorphContext('customer', 42)->__toString());
    }
}
