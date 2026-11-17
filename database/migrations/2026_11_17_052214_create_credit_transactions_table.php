<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credits belong to organizations and live in an append-only ledger in USD, exact to 10 decimal
     * places like charges. organizations.credit_balance caches the ledger's sum and is updated in the
     * same transaction as each entry. Each entry also records which member bought or spent.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->decimal('credit_balance', 20, 10)->default(0);
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 20, 10);
            $table->decimal('balance_after', 20, 10);
            $table->uuid('purchase_key')->nullable();
            $table->timestamps();

            // A generation is held, released and charged at most once each.
            $table->unique(['generation_id', 'type']);
            // Replaying a purchase request can't buy twice.
            $table->unique(['organization_id', 'purchase_key']);
            $table->index(['organization_id', 'id']);
        });

        // Owners and Admins of existing organizations can buy credits, like the defaults for new ones.
        $permissionId = DB::table('permissions')->where('name', 'credits.buy')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'credits.buy', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('roles')->whereIn('name', ['Owner', 'Admin'])->orderBy('id')->each(function (object $role) use ($permissionId): void {
            DB::table('permissionables')->insertOrIgnore([
                'permission_id' => $permissionId,
                'permissionable_type' => 'role',
                'permissionable_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('credit_balance');
        });
    }
};
