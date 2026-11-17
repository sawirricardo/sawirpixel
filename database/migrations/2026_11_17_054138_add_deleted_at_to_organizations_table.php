<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organizations are soft deleted: their generations and credit ledger are billing records that must
     * outlive them (the profit report reconciles them against Pixelforge's usage).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
