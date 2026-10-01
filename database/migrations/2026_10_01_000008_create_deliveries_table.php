<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('number')->unique();
            // Restrict: deliveries are history and must outlive any attempt to delete their order.
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->timestamp('received_at');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
