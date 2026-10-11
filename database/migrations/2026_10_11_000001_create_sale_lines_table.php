<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PTY-29 (D-048): a sale becomes a ticket of lines. The menu item and quantity move
 * from `sales` to `sale_lines`, and stock movements reference the line.
 *
 * SQLite cannot drop or add a foreign-key column in place: Laravel rebuilds the whole `sales` table
 * (drop, create, copy). That rebuild fails while `sale_lines` rows still reference `sales`, so in
 * both directions the data is held in memory while `sales` is reshaped and written afterwards.
 * Everything runs in the migration's transaction, so a failure leaves the old shape untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Read first: after the columns are gone this data would be unrecoverable.
        $sales = DB::table('sales')->orderBy('id')->get(['id', 'menu_item_id', 'quantity', 'created_at', 'updated_at']);

        // The foreign key goes before the column it covers.
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_id');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            // A menu item appears once per ticket: duplicates are merged before they are stored.
            $table->unique(['sale_id', 'menu_item_id']);
        });

        // Every existing sale keeps its item and quantity as its one line, with the sale's own
        // timestamps so the line looks as old as the sale. Chunked to stay under SQLite's variable limit.
        foreach ($sales->chunk(100) as $chunk) {
            DB::table('sale_lines')->insert($chunk->map(fn ($sale) => [
                'sale_id' => $sale->id,
                'menu_item_id' => $sale->menu_item_id,
                'quantity' => $sale->quantity,
                'created_at' => $sale->created_at,
                'updated_at' => $sale->updated_at,
            ])->all());
        }
    }

    /**
     * Lossy for a ticket with several lines: a sale can hold one item again, so it keeps its
     * first line (lowest id) and the others are discarded with the table. Rolling back past
     * real multi-item sales is a data loss the operator has to accept.
     *
     * Rolling back after real multi-line tickets is not supported. Movements with reference_type
     * `sale_line` would point at a table and morph alias the old code lacks; the movements of the
     * dropped later lines remain with no sale line; and the restored columns are nullable / default 0.
     * The append-only triggers on stock_movements forbid repairing the ledger afterwards.
     */
    public function down(): void
    {
        $first = DB::table('sale_lines')->orderBy('id')->get()->unique('sale_id');

        Schema::drop('sale_lines');

        Schema::table('sales', function (Blueprint $table) {
            // Nullable/defaulted only so the columns can be added to a table that already has rows.
            $table->foreignId('menu_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
        });

        foreach ($first as $line) {
            DB::table('sales')->where('id', $line->sale_id)->update([
                'menu_item_id' => $line->menu_item_id,
                'quantity' => $line->quantity,
            ]);
        }
    }
};
