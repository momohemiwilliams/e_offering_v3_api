<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who uses the platform: institutions (TSP/RM, QII, Non-QII, Regulator), their users,
 * retail investors, FMDQ staff, plus login OTPs and settings (CR s3, Login).
 */
class CreateIdentityTables extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            // tsp | qii | non_qii | regulator
            $table->string('type', 20)->index();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('registration_number')->nullable();
            // SEC | CBN for regulators
            $table->string('regulator_body', 10)->nullable();
            $table->string('proof_of_registration_path')->nullable();
            $table->json('documents')->nullable();
            // pending | approved | rejected | suspended
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();

            // TSP "Registration" module (CR s3.2 step 6).
            $table->string('registered_name')->nullable();
            $table->string('registered_address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamp('registration_completed_at')->nullable();

            // Default bank account shown to investors when an offer has no receiving agents.
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();

            // Annual platform fee (CR s3.2 steps 7-12).
            $table->date('fee_paid_until')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            // admin | csd | tsp | investor | regulator
            $table->string('type', 20)->index();
            // TSP and institutional investor users: inputter | authoriser
            $table->string('access_level', 20)->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            // pending | active | disabled
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('investor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // retail | qii | non_qii
            $table->string('category', 20);
            $table->string('bvn', 20)->nullable();
            $table->string('nin', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('csd_name')->nullable();
            $table->string('csd_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->json('bvn_payload')->nullable();
            $table->timestamp('bvn_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });


        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30)->default('login');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('amount', 20, 2);
            // paystack | flutterwave | bank_transfer
            $table->string('method', 20)->nullable();
            // unpaid | pending_review | paid | rejected
            $table->string('status', 20)->default('unpaid')->index();
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('evidence_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_invoices');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('investor_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('institutions');
    }
}
