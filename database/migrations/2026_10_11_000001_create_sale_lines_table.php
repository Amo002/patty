<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PTY-29 (D-048): a sale becomes a ticket of lines. The menu item and quantity move
 * from `sales` to `sale_lines`, and stock movements reference the line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            // A menu item appears once per ticket: duplicates are merged before they are stored.
            $table->unique(['sale_id', 'menu_item_id']);
        });

        // Copy first, drop second: every existing sale keeps its item and quantity as its one line.
        // Same timestamps, so the line looks as old as the sale.
        DB::table('sale_lines')->insertUsing(
            ['sale_id', 'menu_item_id', 'quantity', 'created_at', 'updated_at'],
            DB::table('sales')->select('id', 'menu_item_id', 'quantity', 'created_at', 'updated_at')->orderBy('id'),
        );

        // The foreign key goes before the column it covers.
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_id');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }

    /**
     * Lossy for a ticket with several lines: a sale can hold one item again, so it keeps its
     * first line (lowest id) and the others are discarded with the table. Rolling back past
     * real multi-item sales is a data loss the operator has to accept.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Nullable/defaulted only so the column can be added to a table that already has rows.
            $table->foreignId('menu_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
        });

        foreach (DB::table('sales')->pluck('id') as $saleId) {
            $first = DB::table('sale_lines')->where('sale_id', $saleId)->orderBy('id')->first();

            if ($first !== null) {
                DB::table('sales')->where('id', $saleId)->update([
                    'menu_item_id' => $first->menu_item_id,
                    'quantity' => $first->quantity,
                ]);
            }
        }

        Schema::dropIfExists('sale_lines');
    }
};
