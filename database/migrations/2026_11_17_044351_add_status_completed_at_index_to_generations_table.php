<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The operator report selects succeeded generations by completion date.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->index(['status', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['status', 'completed_at']);
        });
    }
};
