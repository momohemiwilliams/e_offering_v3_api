<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offers and everything attached to them (CR s4): documents, co-sponsors,
 * receiving agents and post-launch amendments.
 */
class CreateOfferTables extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('created_by')->constrained('users');

            // s4.1 Offer details.
            $table->string('issuer_name');
            $table->text('issue_description');
            $table->string('issue_series')->nullable();
            $table->decimal('offer_size', 20, 2);
            $table->decimal('min_subscription_threshold', 5, 2)->default(0);
            $table->string('issuer_type', 30);
            $table->string('security_type', 30)->index();
            $table->dateTime('open_at');
            $table->dateTime('close_at');
            $table->decimal('min_subscription_amount', 20, 2)->default(0);
            $table->decimal('subsequent_increment', 20, 2)->default(0);
            $table->string('tenor')->nullable();
            $table->string('day_count_fraction', 20)->nullable();
            $table->string('price_discovery_method', 20);
            $table->json('investor_universe');

            // s4.3 Price-discovery parameters.
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('rate_min', 12, 4)->nullable();
            $table->decimal('rate_max', 12, 4)->nullable();
            $table->decimal('implied_yield', 12, 4)->nullable();
            $table->decimal('issue_price', 20, 4)->nullable();
            $table->date('maturity_date')->nullable();
            $table->date('allotment_date')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->boolean('uses_receiving_agents')->default(false);

            // draft | pending_authorisation | pending_admin_review | returned | approved | live | cancelled
            $table->string('status', 30)->default('draft')->index();
            $table->text('return_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('authorised_by')->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->foreignId('admin_reviewed_by')->nullable();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->foreignId('launched_by')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->decimal('final_rate', 12, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('offer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('allotment_id')->nullable()->index();
            // offer | sec_support | sponsor_letter | amendment | lodgement
            $table->string('category', 20)->default('offer');
            $table->string('document_type');
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('visible_to_investors')->default(true);
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('offer_co_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained();
            $table->boolean('can_view_order_book')->default(true);
            $table->boolean('can_confirm_payments')->default(false);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->timestamps();
            $table->unique(['offer_id', 'institution_id']);
        });

        Schema::create('offer_receiving_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name');
            $table->string('bank_account_number', 20);
            $table->timestamps();
        });

        Schema::create('offer_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            // { field: { from, to } }
            $table->json('changes');
            // pending_authorisation | pending_admin_review | approved | rejected
            $table->string('status', 30)->default('pending_authorisation')->index();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // Every approval / rejection on an offer, amendment or allotment, by stage
        // (TSP authoriser, FMDQ admin, SEC). Drives exception reporting (CR s7).
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->foreignId('offer_id')->nullable()->index();
            // tsp_authoriser | fmdq_admin | sec | inputter
            $table->string('stage', 30);
            // submitted | approved | rejected | cancelled
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('offer_amendments');
        Schema::dropIfExists('offer_receiving_agents');
        Schema::dropIfExists('offer_co_sponsors');
        Schema::dropIfExists('offer_documents');
        Schema::dropIfExists('offers');
    }
}
