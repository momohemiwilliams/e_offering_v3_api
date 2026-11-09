<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Controls carried over from the legacy platform: password policy, staff maker-checker, notes, reminders. */
class AddLegacyControls extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_logins')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_change_requests', function (Blueprint $table) {
            $table->id();
            // create | disable | enable
            $table->string('action', 20);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name');
            $table->string('target_email');
            $table->foreignId('requested_by')->constrained('users');
            // pending | approved | rejected
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
        });

        Schema::create('offer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('bids', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_reminders_sent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn('payment_reminders_sent');
        });
        Schema::dropIfExists('offer_notes');
        Schema::dropIfExists('user_change_requests');
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_logins', 'locked_at', 'password_changed_at']);
        });
    }
}
