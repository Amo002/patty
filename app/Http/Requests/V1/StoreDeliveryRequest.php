<?php

namespace App\Http\Requests\V1;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E23: record a delivery against a purchase order (validation.md, Deliveries).
 *
 * Shape only. Whether the order can take a delivery (409) and whether a line
 * would exceed its limit (422 over_delivery) are rules about stock and status,
 * so ReceivingService decides them under the order's row lock.
 */
class StoreDeliveryRequest extends FormRequest
{
    /**
     * ISO-8601 only: `date` would also accept "yesterday" or "next monday".
     * The fractional forms are what JavaScript's toISOString() produces.
     */
    private const TIME_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s.v\Z'];

    private ?CarbonImmutable $receivedAt = null;

    /**
     * @var array<int, array{purchase_order_line: PurchaseOrderLine, quantity: int}>
     */
    private array $resolvedLines = [];

    /**
     * No authentication in this system (brief), so every request is allowed.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Stored ULIDs are lowercase; `ulid` also accepts uppercase, which would then miss `exists`.
     */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (is_array($line) && is_string($line['purchase_order_line_id'] ?? null)) {
                $lines[$index]['purchase_order_line_id'] = strtolower($line['purchase_order_line_id']);
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['bail', 'required', 'array', 'min:1', 'max:50'],
            'lines.*.purchase_order_line_id' => [
                'bail', 'required', 'string', 'ulid', 'distinct',
                // Scoped to the route's order: a line of another order is "does not exist" here.
                Rule::exists('purchase_order_lines', 'ulid')->where('purchase_order_id', $this->order()->id),
            ],
            // integer:strict, because plain `integer` accepts `true` as 1 and "5" as 5.
            'lines.*.quantity' => ['bail', 'required', 'integer:strict', 'min:1', 'max:1000000'],
            'received_at' => ['bail', 'nullable', 'string', 'date_format:'.implode(',', self::TIME_FORMATS)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Static messages: ":position" is the 1-based line number Laravel fills in for wildcards.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'A delivery needs at least one line.',
            'lines.array' => 'Delivery lines must be a list.',
            'lines.min' => 'A delivery needs at least one line.',
            'lines.max' => 'A delivery can have at most 50 lines.',
            'lines.*.purchase_order_line_id.required' => 'Line :position: choose which order line arrived.',
            'lines.*.purchase_order_line_id.string' => 'Line :position: the order line must be an id.',
            'lines.*.purchase_order_line_id.ulid' => 'Line :position: the order line is not a valid id.',
            'lines.*.purchase_order_line_id.distinct' => 'Line :position: this order line is listed more than once.',
            'lines.*.purchase_order_line_id.exists' => 'Line :position: that line is not part of this order.',
            'lines.*.quantity.required' => 'Line :position: quantity is required.',
            'lines.*.quantity.integer' => 'Line :position: quantity must be a whole number.',
            'lines.*.quantity.min' => 'Line :position: quantity must be at least 1.',
            'lines.*.quantity.max' => 'Line :position: quantity must be at most 1,000,000.',
            'received_at.string' => 'Received time must be an ISO-8601 date and time, for example 2026-10-01T14:00:00+03:00.',
            'received_at.date_format' => 'Received time must be an ISO-8601 date and time, for example 2026-10-01T14:00:00+03:00.',
            'note.max' => 'The note can be at most 255 characters.',
        ];
    }

    /**
     * Time checks that need the order and the clock. Everything is compared in UTC:
     * Carbon::parse keeps the caller's offset and Eloquent stores the clock time without
     * converting, so +03:00 input must be normalised before it goes anywhere.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! is_string($this->input('received_at'))) {
                return;
            }

            $receivedAt = CarbonImmutable::parse($this->input('received_at'))->utc();
            $order = $this->order();

            if ($receivedAt->isFuture()) {
                $validator->errors()->add('received_at', 'Received time cannot be in the future.');
                // A draft has no sent_at; the service answers that with 409 cannot_receive.
            } elseif ($order->status !== PurchaseOrderStatus::Draft && $order->sent_at !== null && $receivedAt->lt($order->sent_at->utc())) {
                $validator->errors()->add('received_at', 'Received time cannot be before the order was sent ('.$order->sent_at->utc()->toIso8601ZuluString().').');
            }
        }];
    }

    /**
     * Swap validated ULIDs for models and parse the time to UTC, once, for the controller.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated('lines');
        $models = PurchaseOrderLine::query()
            ->where('purchase_order_id', $this->order()->id)
            ->whereIn('ulid', array_column($validated, 'purchase_order_line_id'))
            ->get()
            ->keyBy('ulid');

        $this->resolvedLines = array_map(fn (array $line) => [
            'purchase_order_line' => $models[$line['purchase_order_line_id']],
            'quantity' => (int) $line['quantity'],
        ], array_values($validated));

        $time = $this->validated('received_at');
        $this->receivedAt = is_string($time) ? CarbonImmutable::parse($time)->utc() : null;
    }

    /**
     * @return array<int, array{purchase_order_line: PurchaseOrderLine, quantity: int}>
     */
    public function lines(): array
    {
        return $this->resolvedLines;
    }

    /**
     * Null means "now": the service stamps the time.
     */
    public function receivedAt(): ?CarbonInterface
    {
        return $this->receivedAt;
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? $note : null;
    }

    private function order(): PurchaseOrder
    {
        return $this->route('purchaseOrder');
    }
}
