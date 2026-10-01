<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Human-readable document numbers (D-031): PO-2026-0001, GRN-2026-0001,
 * SALE-2026-000001. Display-only, never derived from the primary key (that
 * would leak the id). One counter per type per UTC calendar year.
 */
class DocumentNumber
{
    public const PURCHASE_ORDER = 'PO';

    public const DELIVERY = 'GRN';

    public const SALE = 'SALE';

    /**
     * Zero-padding width per type; sales run far more often than orders.
     *
     * @var array<string, int>
     */
    private const WIDTHS = [
        self::PURCHASE_ORDER => 4,
        self::DELIVERY => 4,
        self::SALE => 6,
    ];

    /**
     * Take the next number for a document type.
     *
     * The body runs in its own DB::transaction, which becomes a savepoint when
     * the caller already has one, so it is safe on its own. Callers should
     * still call it inside the transaction of the document it numbers, so a
     * rollback never burns a number. The counter row is read with a lock (a
     * no-op on SQLite, whose writer lock already serialises; effective on
     * MySQL/Postgres). A new year starts a new row, so numbering restarts at 1.
     *
     * @throws InvalidArgumentException for an unknown type
     */
    public function next(string $type): string
    {
        if (! isset(self::WIDTHS[$type])) {
            throw new InvalidArgumentException("Unknown document type [{$type}].");
        }

        return DB::transaction(function () use ($type) {
            $year = now('UTC')->year;

            // Create the counter row if missing. insertOrIgnore lets two first-of-the-year
            // callers race without one of them hitting the unique(type, year) violation.
            DocumentSequence::query()->insertOrIgnore([
                'type' => $type,
                'year' => $year,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::query()
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->last_value++;
            $sequence->save();

            return sprintf('%s-%d-%0'.self::WIDTHS[$type].'d', $type, $year, $sequence->last_value);
        });
    }
}
