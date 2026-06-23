<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class IdempotencyKeyTest extends IntegrationTestCase
{
    private function priceCount(mixed $productId): int
    {
        return ProductPrice::query()->where('product_id', $productId)->count();
    }

    public function test_same_key_and_params_is_a_no_op_replay(): void
    {
        $product = $this->makeProduct();

        $first = $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-1');
        $countAfterFirst = $this->priceCount($product->getKey());

        $second = $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-1');

        $this->assertSame($countAfterFirst, $this->priceCount($product->getKey()), 'replay must not write new rows');
        $this->assertSame(
            $first->recordedAt->format('Y-m-d H:i:s.u'),
            $second->recordedAt->format('Y-m-d H:i:s.u'),
            'replay must return the original recordedAt',
        );
    }

    public function test_same_key_different_params_throws(): void
    {
        $product = $this->makeProduct();

        $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-2');

        $this->expectException(TemporalWriteConflictException::class);

        $product->prices()->correct(['amount' => 9999], '2026-01-01', null, idempotencyKey: 'job-2');
    }

    public function test_different_keys_produce_two_writes(): void
    {
        $product = $this->makeProduct();

        $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-3a');
        $product->prices()->correct(['amount' => 1600], '2026-02-01', null, idempotencyKey: 'job-3b');

        $this->assertGreaterThanOrEqual(2, $this->priceCount($product->getKey()));
    }

    public function test_no_key_is_not_idempotent(): void
    {
        $product = $this->makeProduct();

        $product->prices()->correct(['amount' => 1500], '2026-01-01');
        $product->prices()->correct(['amount' => 1600], '2026-01-01');

        // Without a key both corrections run; the second supersedes the first,
        // so more than one physical row exists.
        $this->assertGreaterThan(1, $this->priceCount($product->getKey()));
    }
}
