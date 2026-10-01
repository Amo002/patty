<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            // Signed: positive in, negative out. On-hand is the SUM of this column.
            $table->integer('quantity_delta');
            $table->string('reason');
            $table->morphs('reference');
            $table->timestamp('occurred_at');
            // No updated_at: a movement is never updated, so the column would only mislead.
            $table->timestamp('created_at')->nullable();

            // On-hand is a SUM over this index.
            $table->index('ingredient_id');
        });

        // D-024: append-only is a property of the database, not a promise of the code.
        // MySQL/Postgres equivalent: a BEFORE UPDATE / BEFORE DELETE trigger that signals
        // an error (SIGNAL SQLSTATE '45000' on MySQL, RAISE EXCEPTION on Postgres).
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_movements_no_update
            BEFORE UPDATE ON stock_movements
            BEGIN
                SELECT RAISE(ABORT, 'stock_movements is append-only');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_movements_no_delete
            BEFORE DELETE ON stock_movements
            BEGIN
                SELECT RAISE(ABORT, 'stock_movements is append-only');
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::statement('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
    }
};
