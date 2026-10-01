<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * D-031: integer primary keys stay internal, the `ulid` column is the public identifier.
 *
 * Laravel's HasUlids does the work: it fills every column named by uniqueIds()
 * on insert and rejects a malformed route value with a 404. Because we point
 * uniqueIds() at `ulid` instead of the key, the primary key stays an
 * auto-incrementing integer (getKeyType and getIncrementing only change when
 * the key itself is listed).
 */
trait HasPublicUlid
{
    use HasUlids {
        resolveRouteBindingQuery as private resolveUlidBindingQuery;
    }

    /**
     * Stored ULIDs are always lowercase (HasUlids generates them that way), but
     * Str::isUlid and the `ulid` validation rule also accept uppercase. Lowercase
     * the incoming value so an uppercase ULID finds the same record instead of
     * passing validation and then missing.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $this->resolveUlidBindingQuery($query, is_string($value) ? strtolower($value) : $value, $field);
    }

    /**
     * Keep stored ULIDs lowercase even when a caller (a factory, a seeder) supplies one.
     */
    public function setUlidAttribute(string $value): void
    {
        $this->attributes['ulid'] = strtolower($value);
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Hide `id` and every `*_id` column on top of the model's own hidden list,
     * so an internal key can never be serialised by accident. Computed per
     * call from the loaded attributes because foreign keys differ per model.
     *
     * @return array<int, string>
     */
    public function getHidden(): array
    {
        $internal = array_filter(
            array_keys($this->attributes),
            fn (string $key) => $key === 'id' || str_ends_with($key, '_id'),
        );

        return array_values(array_unique([...parent::getHidden(), ...$internal]));
    }
}
