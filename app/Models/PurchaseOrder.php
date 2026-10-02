<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[UseFactory(PurchaseOrderFactory::class)]
class PurchaseOrder extends Model
{
    use HasFactory, HasPublicUlid, LogsActivity;

    /**
     * Only these events reach the activity log through the trait. The named
     * business events (purchase_order.created and so on) are written by
     * PurchaseOrderService through Audit::record, so recording `created` and
     * `deleted` here as well would only duplicate them.
     *
     * @var array<int, string>
     */
    protected static array $recordEvents = ['updated'];

    /**
     * `status`, `sent_at`, `closed_at` and `short_closed` are deliberately NOT
     * fillable. D-012/D-013: a status may only change through transitionTo(),
     * so mass assignment (create/update/fill with request data) cannot move an
     * order to a state the transition map forbids. A new order is a draft
     * because of $attributes below, not because a caller said so.
     */
    protected $fillable = [
        'number',
        'supplier_id',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'short_closed' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Status only: supplier_id is an integer key and must not reach the audit trail (D-031).
        return LogOptions::defaults()
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn () => "Status of {$this->number} changed");
    }

    /**
     * Move the order to a new status. The only way status changes (D-012, D-013).
     *
     * The map lives in PurchaseOrderStatus; this method asks it, stamps
     * sent_at / closed_at, and saves. Callers that change state should hold a
     * row lock (PurchaseOrderService does), so two requests cannot both pass
     * the check against the same old status.
     *
     * @throws InvalidTransition when the map does not allow from to to
     */
    public function transitionTo(PurchaseOrderStatus $to): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            Log::channel('purchasing')->notice("Rejected transition of {$this->number} from {$from->value} to {$to->value}");

            throw InvalidTransition::between($this->number, $from, $to);
        }

        $this->forceFill(['status' => $to]);

        if ($to === PurchaseOrderStatus::Sent) {
            $this->sent_at = now();
        }

        if ($to === PurchaseOrderStatus::Closed) {
            $this->closed_at = now();
        }

        $this->save();
    }

    /**
     * Buttons the UI may show, derived from status alone so the front end never
     * re-implements the rules. `receive` is served by PTY-8.
     *
     * @return array<int, string>
     */
    public function allowedActions(): array
    {
        return match ($this->status) {
            PurchaseOrderStatus::Draft => ['edit_lines', 'send', 'delete'],
            PurchaseOrderStatus::Sent => ['receive'],
            PurchaseOrderStatus::Received => ['receive', 'short_close'],
            PurchaseOrderStatus::Closed => [],
        };
    }

    /**
     * D-030: each line's percent rounded down, then the average rounded down.
     *
     * Never sums quantities across lines (they are in different units); never
     * over-reports; 100 only when every line is fully received (over-received
     * counts as 100 for its line). 1/3 and 2/3 shows 49 by design.
     */
    public function progressPercent(): int
    {
        $lines = $this->lines;

        if ($lines->isEmpty()) {
            return 0;
        }

        $sum = $lines->sum(fn (PurchaseOrderLine $line) => intdiv(min($line->received(), $line->quantity_ordered) * 100, $line->quantity_ordered));

        return intdiv($sum, $lines->count());
    }

    /**
     * The one definition of what a purchase order response needs loaded:
     * supplier, lines with their delivered total (`received_sum`, one grouped
     * query) and each line's ingredient. Used by the list, show and every
     * service return, so no caller loads a slightly different copy.
     *
     * @return array<string, mixed>
     */
    public static function detailRelations(): array
    {
        return [
            'supplier',
            'lines' => fn ($lines) => $lines->withSum('deliveryLines as received_sum', 'quantity_received'),
            'lines.ingredient',
        ];
    }

    public function scopeWithDetails(Builder $query): void
    {
        $query->with(static::detailRelations());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
