<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_waas_accounts', function (Blueprint $table) {
            $table->string('reference', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('mock_waas_accounts', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};