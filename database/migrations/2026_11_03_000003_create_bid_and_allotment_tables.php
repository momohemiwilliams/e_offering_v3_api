<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investor bids (commitment forms, CR s6), the allotment schedule (s5.2-5.4),
 * audit trail and in-app notifications.
 */
class CreateBidAndAllotmentTables extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('institution_id')->nullable();
            // retail | qii | non_qii
            $table->string('investor_category', 20);
            // s6.1 investor type (pfa, bank, ...)
            $table->string('investor_type', 30);
            $table->string('investor_name');
            $table->decimal('amount', 20, 2);
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('yield', 12, 4)->nullable();

            // Allotment / settlement details.
            $table->string('csd_name')->nullable();
            $table->string('csd_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();

            // s6.2 Who the investor paid: sponsor | co_sponsor | receiving_agent
            $table->string('payee_type', 20);
            $table->unsignedBigInteger('payee_id')->nullable();
            $table->string('payee_name');
            $table->string('payee_bank_name')->nullable();
            $table->string('payee_account_name')->nullable();
            $table->string('payee_account_number')->nullable();
            $table->foreignId('placed_through_institution_id')->nullable();

            // pending | paid | invalid
            $table->string('payment_status', 20)->default('pending')->index();
            $table->string('payment_reference')->nullable();
            $table->string('payment_evidence_path')->nullable();
            $table->timestamp('payment_uploaded_at')->nullable();
            $table->text('payment_comment')->nullable();
            $table->foreignId('payment_reviewed_by')->nullable();
            $table->timestamp('payment_reviewed_at')->nullable();

            // active | revoked
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bid_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            // submitted | amended | revoked | payment_uploaded | payment_valid | payment_invalid
            $table->string('action', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('allotments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('method');
            $table->decimal('final_rate', 12, 4)->nullable();
            $table->decimal('issue_size', 20, 2);
            $table->decimal('total_demand', 20, 2)->default(0);
            $table->decimal('valid_demand', 20, 2)->default(0);
            $table->decimal('total_allotted', 20, 2)->default(0);
            $table->decimal('subscription_level', 10, 2)->default(0);
            // oversubscribed | fully_subscribed | undersubscribed | below_minimum
            $table->string('subscription_status', 20)->nullable();
            $table->json('notes')->nullable();
            // draft | pending_authorisation | returned | sec_pending | sec_rejected | approved | lodged | cancelled
            $table->string('status', 30)->default('draft')->index();
            $table->text('return_reason')->nullable();
            $table->foreignId('initiated_by')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('authorised_by')->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->foreignId('sec_reviewed_by')->nullable();
            $table->timestamp('sec_reviewed_at')->nullable();
            $table->text('sec_reason')->nullable();
            $table->string('lodgement_reference')->nullable();
            // transmitted | confirmed | failed
            $table->string('lodgement_status', 20)->nullable();
            $table->foreignId('lodged_by')->nullable();
            $table->timestamp('lodged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('allotment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allotment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bid_id')->constrained()->cascadeOnDelete();
            $table->decimal('subscribed', 20, 2);
            $table->decimal('allotted', 20, 2)->default(0);
            $table->decimal('units', 24, 4)->default(0);
            $table->decimal('released', 20, 2)->default(0);
            $table->decimal('filled_pct', 7, 2)->default(0);
            $table->boolean('eligible')->default(true);
            $table->string('ineligible_reason')->nullable();
            $table->decimal('override_amount', 20, 2)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();
            $table->unique(['allotment_id', 'bid_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->foreignId('institution_id')->nullable()->index();
            $table->foreignId('offer_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('allotment_items');
        Schema::dropIfExists('allotments');
        Schema::dropIfExists('bid_events');
        Schema::dropIfExists('bids');
    }
}
