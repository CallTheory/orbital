<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_invitations — drives the email-based invitation flow for
 * tenant portal users.
 *
 * One row per pending invitation. The acceptance controller at
 * /invite/{token} either signs an existing user in, or creates a
 * new account, and in either case attaches them to the tenant via
 * Jetstream's team_user pivot and assigns the tenant-scoped
 * `tenant_user` role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('portal_user');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // A pending invite for the same email/tenant shouldn't
            // race with another one. When you accept or cancel the
            // pending invite first, then a new one can be created.
            $table->unique(['team_id', 'email'], 'tenant_invitations_team_email_unique');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};
