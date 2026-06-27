<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Locking;

use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * A controllable stand-in model. key() only reads getTable(), getMorphClass()
 * and getKey(), none of which touch a database connection, so these tests run
 * under the default sqlite suite with no Docker engine required.
 *
 * getMorphClass() is overridden to a short, fixed value so the generated key
 * stays comfortably under the 64-character cap. That keeps the hash segment
 * fully visible (un-truncated), which is what lets the substr() length/offset
 * mutants on the hash be observed.
 */
final class KeyStubModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    private string $morph = 'm';

    private mixed $keyValue = 1;

    public function getMorphClass(): string
    {
        return $this->morph;
    }

    public function withMorph(string $morph): self
    {
        $this->morph = $morph;

        return $this;
    }

    /**
     * Bypass Eloquent's attribute casting so we keep full control over the id
     * type (int / string / float / null) that key() inspects.
     */
    public function getKey(): mixed
    {
        return $this->keyValue;
    }

    public function withKey(mixed $key): self
    {
        $this->keyValue = $key;

        return $this;
    }
}

/**
 * Pins the key()/sortedKeys() survivors in
 * build/mutants/src__Locking__AdvisoryLocker.txt. All of these are killable on
 * ANY engine because they exercise the private helpers directly via reflection.
 */
final class AdvisoryLockerKeyTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $dimensions
     */
    private function key(Model $entity, array $dimensions): string
    {
        $method = new ReflectionMethod(AdvisoryLocker::class, 'key');
        $result = $method->invoke(new AdvisoryLocker, $entity, $dimensions);

        $this->assertIsString($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    private function sortedKeys(array $dimensions): array
    {
        $method = new ReflectionMethod(AdvisoryLocker::class, 'sortedKeys');
        $result = $method->invoke(new AdvisoryLocker, $dimensions);

        $this->assertIsArray($result);

        return $result;
    }

    private function model(string $table = 't', mixed $key = 1, string $morph = 'm'): KeyStubModel
    {
        $model = (new KeyStubModel)->withMorph($morph)->withKey($key);
        $model->setTable($table);

        return $model;
    }

    public function test_key_has_the_exact_expected_shape(): void
    {
        $model = $this->model('t', 1, 'm');
        $dimensions = ['region' => 'eu', 'channel' => 'web'];

        // Reference: ksort -> json_encode -> sha1 -> first 24 chars.
        $expectedHash = substr(sha1((string) json_encode(['channel' => 'web', 'region' => 'eu'])), 0, 24);
        $expected = 'temporal:t:m:1:'.$expectedHash;

        $key = $this->key($model, $dimensions);

        // Exact string pins the concat operands AND their order (Concat /
        // ConcatOperandRemoval), the prefix literal, the hash value, and the
        // id segment all at once.
        $this->assertSame($expected, $key);
        $this->assertStringStartsWith('temporal:t:m:1:', $key);
    }

    public function test_hash_segment_is_sha1_truncated_to_exactly_24_chars(): void
    {
        $model = $this->model('t', 1, 'm');
        $dimensions = ['channel' => 'web'];

        $key = $this->key($model, $dimensions);
        $hash = substr($key, strlen('temporal:t:m:1:'));

        // UnwrapSubstr (full 40-char sha1), Increment/Decrement on the length
        // (23/25) and on the offset (-1/1) all change this segment.
        $this->assertSame(24, strlen($hash));
        $this->assertSame(substr(sha1((string) json_encode(['channel' => 'web'])), 0, 24), $hash);
    }

    public function test_dimension_key_order_does_not_change_the_key(): void
    {
        $model = $this->model('t', 1, 'm');

        // ksort makes the encoded payload order-independent. FunctionCallRemoval
        // (dropping ksort) would make these two differ.
        $this->assertSame(
            $this->key($model, ['a' => 1, 'b' => 2]),
            $this->key($model, ['b' => 2, 'a' => 1]),
        );
    }

    public function test_every_dimension_contributes_to_the_key(): void
    {
        $model = $this->model('t', 1, 'm');

        // ArrayOneItem keeps only the first sorted entry; the full set must
        // differ from just its first key.
        $this->assertNotSame(
            $this->key($model, ['a' => 1]),
            $this->key($model, ['a' => 1, 'b' => 2]),
        );
    }

    public function test_different_dimension_values_produce_different_keys(): void
    {
        $model = $this->model('t', 1, 'm');

        $this->assertNotSame(
            $this->key($model, ['region' => 'eu']),
            $this->key($model, ['region' => 'us']),
        );
    }

    public function test_integer_id_is_stringified_into_the_key(): void
    {
        $key = $this->key($this->model('t', 42, 'm'), ['x' => 1]);

        // is_int($id) || is_string($id) must be true for an int id; the various
        // Logical* / Ternary mutants would drop the id and leave 'temporal:t:m::'.
        $this->assertStringContainsString(':m:42:', $key);
        $this->assertStringNotContainsString(':m::', $key);
    }

    public function test_string_id_is_included_in_the_key(): void
    {
        $key = $this->key($this->model('t', 'sku-9', 'm'), ['x' => 1]);

        $this->assertStringContainsString(':m:sku-9:', $key);
    }

    public function test_non_int_non_string_id_yields_an_empty_segment(): void
    {
        // A float id is neither int nor string: the ternary must collapse it to
        // ''. This kills LogicalOrAllSubExprNegation (!is_int || !is_string
        // would be true here and inject '1.5') and the Ternary swap.
        $key = $this->key($this->model('t', 1.5, 'm'), ['x' => 1]);

        $this->assertStringContainsString(':m::', $key);
        $this->assertStringNotContainsString('1.5', $key);
    }

    public function test_key_is_capped_at_64_characters(): void
    {
        $longTable = str_repeat('long_table_name_', 8); // 128 chars, key >> 64
        $model = $this->model($longTable, 1, 'morph');

        $key = $this->key($model, ['x' => 1]);

        // DecrementInteger (0,63), IncrementInteger (0,65) and UnwrapSubstr
        // (uncapped) all change the length; offset mutants (1 / -1) change the
        // leading character.
        $this->assertSame(64, strlen($key));
        $this->assertStringStartsWith('temporal:long_table_name_', $key);

        $expected = substr('temporal:'.$longTable.':morph:1:'.substr(sha1((string) json_encode(['x' => 1])), 0, 24), 0, 64);
        $this->assertSame($expected, $key);
    }

    public function test_sorted_keys_ksorts_and_keeps_every_entry(): void
    {
        // Direct on the helper: FunctionCallRemoval leaves the original order;
        // ArrayOneItem returns only ['a' => 2].
        $this->assertSame(
            ['a' => 2, 'b' => 1, 'c' => 3],
            $this->sortedKeys(['b' => 1, 'c' => 3, 'a' => 2]),
        );
    }
}
