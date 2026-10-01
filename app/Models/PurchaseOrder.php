<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
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
     * Fixed-point scale for progressPercent(): one whole line is 1,000,000,000 units.
     */
    private const PROGRESS_SCALE = 1_000_000_000;

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
     * D-030: the average of each line's own completion, floored to a whole percent.
     *
     * Lines are in different units (g, pieces), so quantities are never added
     * across lines. Each line contributes min(received, ordered) / ordered,
     * scaled to a fixed-point integer (no float anywhere). Flooring each line
     * loses less than one unit per line, which would turn an exact 50% (1/3
     * plus 2/3 of two lines) into 49%, so one unit per line is added back
     * before the final floor. Complete lines are exact, so 100% stays 100%.
     */
    public function progressPercent(): int
    {
        $lines = $this->lines;
        $count = $lines->count();

        if ($count === 0) {
            return 0;
        }

        $scaled = $lines->sum(fn (PurchaseOrderLine $line) => intdiv(
            min($line->received(), $line->quantity_ordered) * self::PROGRESS_SCALE,
            $line->quantity_ordered,
        ));

        return intdiv(($scaled + $count) * 100, $count * self::PROGRESS_SCALE);
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
