<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_waas_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nuban', 10)->unique();
            $table->string('wallet_id', 40)->unique();
            $table->string('account_name');
            $table->char('bvn_hash', 64)->unique();   // HMAC via IdentityHasher::hashBvn(); never the raw BVN
            $table->char('nin_hash', 64)->nullable();
            $table->timestampsTz();
        });

        Schema::create('mock_waas_debits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('idempotency_key', 128)->unique();
            $table->string('kind', 10);                      // debit | reversal
            $table->string('state', 12);                     // succeeded | failed | lost (lost = timed out, never applied)
            $table->string('source_nuban', 10)->nullable();
            $table->bigInteger('amount_kobo')->nullable();
            $table->string('destination_masked', 20)->nullable(); // masked only
            $table->char('request_fingerprint', 64);
            $table->string('failure_reason', 30)->nullable();
            $table->string('provider_reference', 40)->nullable();
            $table->string('original_key', 128)->nullable(); // reversals only
            $table->timestampsTz();

            $table->index(['source_nuban', 'kind', 'state']);
            $table->index(['kind', 'original_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_waas_debits');
        Schema::dropIfExists('mock_waas_accounts');
    }
};