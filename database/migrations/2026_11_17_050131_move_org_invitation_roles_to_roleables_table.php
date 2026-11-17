<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations now carry roles (and direct permissions) the same way memberships do, through the
     * roleables and permissionables pivots, so the single role_id column moves there.
     */
    public function up(): void
    {
        DB::table('org_invitations')->orderBy('id')->each(function (object $invitation): void {
            DB::table('roleables')->insert([
                'role_id' => $invitation->role_id,
                'roleable_type' => 'org_invitation',
                'roleable_id' => $invitation->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('org_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });
    }

    /**
     * Restores role_id from each invitation's first role.
     */
    public function down(): void
    {
        Schema::table('org_invitations', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('roleables')->where('roleable_type', 'org_invitation')->orderBy('id')->each(function (object $roleable): void {
            DB::table('org_invitations')->where('id', $roleable->roleable_id)->whereNull('role_id')->update(['role_id' => $roleable->role_id]);
        });

        DB::table('roleables')->where('roleable_type', 'org_invitation')->delete();
    }
};
