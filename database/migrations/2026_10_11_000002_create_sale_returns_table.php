<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PTY-30 (D-049): a refund is a new document against a sale; the sale itself is never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('number')->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            // Whether the ingredients went back to stock; decided per refund, never defaulted (D-049).
            $table->boolean('restock');
            $table->string('reason', 200);
            // D-029: the POS idempotency key. Unique so a replayed refund cannot be stored twice.
            $table->string('pos_reference')->nullable()->unique();
            $table->timestamp('returned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
