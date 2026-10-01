<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

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
     * Must run inside the transaction that inserts the numbered document: the
     * increment is then rolled back with it, so a failed write never burns a
     * number and two concurrent writers can never receive the same one. The
     * counter row is read with a lock (a no-op on SQLite, whose writer lock
     * already serialises; effective on MySQL/Postgres). A new year starts a
     * new row, so numbering restarts at 1.
     *
     * @throws LogicException when called outside a transaction
     * @throws InvalidArgumentException for an unknown type
     */
    public function next(string $type): string
    {
        if (! isset(self::WIDTHS[$type])) {
            throw new InvalidArgumentException("Unknown document type [{$type}].");
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('DocumentNumber::next() must be called inside a transaction.');
        }

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
    }
}
