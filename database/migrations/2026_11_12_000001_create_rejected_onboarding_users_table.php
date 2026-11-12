<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users of a rejected onboarding request are moved here out of `users`, so their email
 * address (unique on `users`) is free to register again. The institution row stays, with
 * status "rejected", as the record of the request and the reason it was refused.
 */
class CreateRejectedOnboardingUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('rejected_onboarding_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            // The users.id the row had, for matching against audit log entries.
            $table->unsignedBigInteger('original_user_id')->nullable()->index();
            $table->string('type', 20);
            $table->string('access_level', 20)->nullable();
            $table->string('name');
            // Deliberately not unique: the same person may be rejected more than once.
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        // Requests rejected before this table existed still hold their users' emails.
        $rejected = DB::table('institutions')->where('status', 'rejected')->pluck('reviewed_at', 'id');
        foreach ($rejected as $institutionId => $reviewedAt) {
            DB::transaction(function () use ($institutionId, $reviewedAt) {
                foreach (DB::table('users')->where('institution_id', $institutionId)->whereNull('last_login_at')->get() as $user) {
                    DB::table('rejected_onboarding_users')->insert([
                        'institution_id' => $institutionId,
                        'original_user_id' => $user->id,
                        'type' => $user->type,
                        'access_level' => $user->access_level,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'privacy_consent_at' => $user->privacy_consent_at,
                        'submitted_at' => $user->created_at,
                        'rejected_at' => $reviewedAt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('users')->where('id', $user->id)->delete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_onboarding_users');
    }
}
