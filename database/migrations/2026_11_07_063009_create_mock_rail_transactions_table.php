<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mock_rail_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();   // same reference twice = same bank event
            $t->string('nuban', 10)->index();
            $t->bigInteger('amount_kobo');
            $t->string('channel', 30);
            $t->string('terminal_id')->nullable();
            $t->timestampTz('paid_at')->index();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_rail_transactions');
    }
};
