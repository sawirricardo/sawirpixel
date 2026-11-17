<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table was scaffolded empty; nothing has been stored in it yet.
     */
    public function up(): void
    {
        Schema::table('org_invitations', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->index(['organization_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('org_invitations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'email']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('invited_by_id');
            $table->dropColumn(['email', 'token_hash', 'expires_at', 'accepted_at']);
        });
    }
};
