# Dimensions

Sometimes a single entity has *several independent timelines*. A product price might vary by currency: the GBP price and the USD price each have their own history and must not collide. A "dimension" is an extra column that partitions the timeline so each combination of dimension values is corrected, scoped, and overlap-checked independently.

## Declaring dimensions

List the columns on the temporal model:

```php
class ProductPrice extends Model
{
    use Bitemporal;

    protected string $temporalEntity = Product::class;

    protected array $temporalDimensions = ['currency'];
}
```

Add the column to the migration and include it in the overlap guard so the index partitions correctly:

```php
$table->string('currency', 3);
// ...
$table->preventBitemporalOverlaps(['product_id'], ['currency']);
```

## Reading and writing within a dimension

Use `forDimensions()` to pin the tuple. On reads it filters; on writes it both scopes *and* stamps the inserted rows, so you never set the dimension column by hand.

```php
// Read the GBP price valid on a date.
$gbp = $product->prices()
    ->forDimensions(['currency' => 'GBP'])
    ->validAt($date)
    ->currentKnowledge()
    ->sole();

// Correct only the USD timeline — the GBP timeline is untouched.
$product->prices()
    ->forDimensions(['currency' => 'USD'])
    ->correct(['amount' => 15.00], validFrom: '2026-02-01');
```

A write through a relation that has dimensions but no `forDimensions()` call is rejected with `TemporalMissingDimensionException` — the writer refuses to guess which timeline you meant.

## NULL is a value

A `null` dimension value is treated as a distinct, matchable value (not "any"). `forDimensions(['region' => null])` scopes to exactly the rows whose region is null, and is a different timeline from `['region' => 'EU']`. The application logic treats null as a first-class dimension value.

## Multiple dimensions

List as many as you need; the tuple is the full combination:

```php
protected array $temporalDimensions = ['currency', 'price_list'];
```

```php
$product->prices()
    ->forDimensions(['currency' => 'GBP', 'price_list' => 'retail'])
    ->changeEffectiveFrom(['amount' => 12.00], '2026-06-01');
```

Next: [The as-of lens](07-as-of-lens.md).
