<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Limits on what an organization generates. Null means no limit. Periods are UTC calendar days and
     * months. The member_* limits apply to each member who can't change the organization's settings.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->decimal('spend_limit_daily', 20, 10)->nullable();
            $table->decimal('spend_limit_monthly', 20, 10)->nullable();
            $table->unsignedInteger('generation_limit_daily')->nullable();
            $table->unsignedInteger('generation_limit_monthly')->nullable();
            $table->decimal('member_spend_limit_daily', 20, 10)->nullable();
            $table->decimal('member_spend_limit_monthly', 20, 10)->nullable();
            $table->unsignedInteger('member_generation_limit_daily')->nullable();
            $table->unsignedInteger('member_generation_limit_monthly')->nullable();
            $table->json('member_qualities')->nullable();
            $table->json('member_sizes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'spend_limit_daily',
                'spend_limit_monthly',
                'generation_limit_daily',
                'generation_limit_monthly',
                'member_spend_limit_daily',
                'member_spend_limit_monthly',
                'member_generation_limit_daily',
                'member_generation_limit_monthly',
                'member_qualities',
                'member_sizes',
            ]);
        });
    }
};
