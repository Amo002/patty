<?php

namespace App\Models;

use App\Enums\MovementReason;
use App\Exceptions\ImmutableMovement;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only (D-024): the database rejects UPDATE and DELETE. Only
 * StockLedger (PTY-4) is allowed to create rows; nothing here is a business
 * rule, which is why there is no ulid and no behaviour on this model.
 */
#[UseFactory(StockMovementFactory::class)]
class StockMovement extends Model
{
    use HasFactory;

    // A movement is never updated, so there is no updated_at column.
    public const UPDATED_AT = null;

    protected $fillable = [
        'ingredient_id',
        'quantity_delta',
        'reason',
        'reference_type',
        'reference_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'reason' => MovementReason::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * D-024, application layer: an existing movement can never be saved again.
     * The PTY-3 triggers are the database layer below this one.
     *
     * @throws ImmutableMovement
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableMovement::forAction('update');
        }

        return parent::save($options);
    }

    /**
     * @throws ImmutableMovement
     */
    public function delete(): never
    {
        throw ImmutableMovement::forAction('delete');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
