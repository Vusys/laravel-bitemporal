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

    #[\Override]
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
    #[\Override]
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

        // Reference: implode the sorted composite -> sha1 -> first 40 chars.
        $composite = implode('|', ['t', 'm', '1', (string) json_encode(['channel' => 'web', 'region' => 'eu'])]);
        $expected = 'temporal:'.substr(sha1($composite), 0, 40);

        $key = $this->key($model, $dimensions);

        // Exact string pins the composite operands AND their order, the prefix
        // literal, and the digest length/offset all at once.
        $this->assertSame($expected, $key);
        $this->assertStringStartsWith('temporal:', $key);
    }

    public function test_digest_segment_is_sha1_truncated_to_exactly_40_chars(): void
    {
        $model = $this->model('t', 1, 'm');
        $dimensions = ['channel' => 'web'];

        $key = $this->key($model, $dimensions);
        $digest = substr($key, strlen('temporal:'));

        // UnwrapSubstr (full 40-char sha1 already, so length pins the offset),
        // Increment/Decrement on the length (39/41) change this segment.
        $this->assertSame(40, strlen($digest));
        $composite = implode('|', ['t', 'm', '1', (string) json_encode(['channel' => 'web'])]);
        $this->assertSame(substr(sha1($composite), 0, 40), $digest);
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

    public function test_table_and_morph_class_contribute_to_the_key(): void
    {
        $base = $this->key($this->model('t', 1, 'm'), ['x' => 1]);

        // Both feed the digest, so changing either must change the key. Under the
        // old truncating scheme a long class name could shear these off entirely.
        $this->assertNotSame($base, $this->key($this->model('t2', 1, 'm'), ['x' => 1]));
        $this->assertNotSame($base, $this->key($this->model('t', 1, 'm2'), ['x' => 1]));
    }

    public function test_id_contributes_to_the_key(): void
    {
        // is_int($id) || is_string($id) must be true for an int/string id, so the
        // id feeds the digest; the Logical*/Ternary mutants that drop it would
        // make these collide.
        $this->assertNotSame(
            $this->key($this->model('t', 42, 'm'), ['x' => 1]),
            $this->key($this->model('t', 43, 'm'), ['x' => 1]),
        );
        $this->assertNotSame(
            $this->key($this->model('t', 'sku-9', 'm'), ['x' => 1]),
            $this->key($this->model('t', 'sku-8', 'm'), ['x' => 1]),
        );
    }

    public function test_non_int_non_string_id_collapses_to_an_empty_segment(): void
    {
        // A float id is neither int nor string: the ternary must collapse it to
        // '', so it keys identically to an explicit empty-string id and the float
        // value never leaks into the digest input.
        $floatKey = $this->key($this->model('t', 1.5, 'm'), ['x' => 1]);

        $this->assertSame($this->key($this->model('t', '', 'm'), ['x' => 1]), $floatKey);
        // If the float were stringified to '1.5', it would differ from ''.
        $this->assertNotSame($this->key($this->model('t', '1.5', 'm'), ['x' => 1]), $floatKey);
    }

    public function test_key_stays_within_the_get_lock_budget_for_long_names(): void
    {
        // 128-char table + long morph class: the old substr-to-64 form would have
        // sheared the id/hash off the end. The digest form is always 49 chars.
        $longTable = str_repeat('long_table_name_', 8);
        $key = $this->key($this->model($longTable, 1, 'a_very_long_morph_class_name_that_would_blow_the_budget'), ['x' => 1]);

        $this->assertSame(49, strlen($key));
        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringStartsWith('temporal:', $key);
    }

    public function test_long_named_entities_no_longer_collide_via_truncation(): void
    {
        // Regression for #49: under the old scheme these two long-named entities
        // produced the same 64-char-truncated key (the discriminating id was
        // sheared off), silently sharing one MySQL GET_LOCK. They must differ now.
        $prefix = str_repeat('very_long_table_name_', 4); // 84 chars — pushes id past char 64

        $this->assertNotSame(
            $this->key($this->model($prefix, 111111111, 'm'), ['x' => 1]),
            $this->key($this->model($prefix, 222222222, 'm'), ['x' => 1]),
        );
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
