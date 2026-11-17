<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generations form threads: a follow-up points at the version it continues from (parent_id), and every
     * generation in a thread shares the id of the thread's first generation (thread_id).
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->unsignedBigInteger('thread_id')->nullable()->index();
        });

        // Every existing generation starts its own thread.
        DB::table('generations')->whereNull('thread_id')->update(['thread_id' => DB::raw('id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->dropIndex(['thread_id']);
            $table->dropColumn('thread_id');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
