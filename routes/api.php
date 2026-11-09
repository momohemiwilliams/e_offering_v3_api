<?php

use App\Http\Controllers\AllotmentController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\InvestorController;
use App\Http\Controllers\LodgementController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferReviewController;
use App\Http\Controllers\OrderBookController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RegulatorController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TspDashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OnboardingReviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function ($router) {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
});

Route::prefix('onboarding')->group(function ($router) {
    Route::get('account-types', [OnboardingController::class, 'accountTypes']);
    Route::middleware('throttle:10,1')->group(function ($router) {
        Route::post('tsp', [OnboardingController::class, 'storeTsp']);
        Route::post('institutional', [OnboardingController::class, 'storeInstitutional']);
        Route::post('regulator', [OnboardingController::class, 'storeRegulator']);
        Route::post('retail/verify', [OnboardingController::class, 'retailVerify']);
        Route::post('retail/fetch', [OnboardingController::class, 'retailFetch']);
        Route::post('retail', [OnboardingController::class, 'storeRetail']);
    });
});

// Opened as plain links, so the signature replaces the bearer token.
Route::middleware('signed')->group(function ($router) {
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('bids/{bid}/evidence', [DocumentController::class, 'bidEvidence'])->name('bids.evidence');
    Route::get('fees/{invoice}/evidence', [DocumentController::class, 'feeEvidence'])->name('fees.evidence');
    Route::get('institutions/{institution}/files/{index}', [DocumentController::class, 'institutionFile'])->name('institutions.file');
});

Route::get('offers/{reference}', [PublicController::class, 'offer'])->name('public.offer');

Route::get('public/welcome', function () {
    return successResponse('Welcome.', ['stats' => [
        'live_offers' => \App\Models\Offer::where('status', \App\Models\Offer::LIVE)->count(),
        'sponsors' => \App\Models\Institution::where('type', \App\Models\Institution::TYPE_TSP)->where('status', \App\Models\Institution::STATUS_APPROVED)->count(),
    ]]);
});

Route::middleware(['auth:api', 'idle', 'active'])->group(function ($router) {
    Route::get('session/ping', function () {
        return response()->noContent();
    });

    Route::prefix('settings')->group(function ($router) {
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::put('password', [ProfileController::class, 'updatePassword']);
    });

    Route::prefix('notifications')->group(function ($router) {
        Route::get('/', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('read-all', [NotificationController::class, 'readAll']);
        Route::post('{id}/read', [NotificationController::class, 'read']);
    });

    Route::prefix('auth')->group(function ($router) {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    Route::middleware('role:admin')->prefix('admin')->group(function ($router) {
        Route::get('/', [ManagementController::class, 'dashboard'])->name('admin.dashboard');

        Route::prefix('onboarding')->group(function ($router) {
            Route::get('/', [OnboardingReviewController::class, 'index'])->name('admin.onboarding.index');
            Route::get('{institution}', [OnboardingReviewController::class, 'show'])->name('admin.onboarding.show');
            Route::post('{institution}/approve', [OnboardingReviewController::class, 'approve'])->name('admin.onboarding.approve');
            Route::post('{institution}/reject', [OnboardingReviewController::class, 'reject'])->name('admin.onboarding.reject');
            Route::post('{institution}/resend', [OnboardingReviewController::class, 'resend'])->name('admin.onboarding.resend');
        });

        Route::get('users', [ManagementController::class, 'users'])->name('admin.users.index');
        Route::post('users', [ManagementController::class, 'storeUser'])->name('admin.users.store');
        Route::post('users/{user}/status', [ManagementController::class, 'toggleUser'])->name('admin.users.status');

        Route::get('fees', [ManagementController::class, 'fees'])->name('admin.fees.index');
        Route::post('fees/{invoice}/approve', [ManagementController::class, 'approveFee'])->name('admin.fees.approve');
        Route::post('fees/{invoice}/reject', [ManagementController::class, 'rejectFee'])->name('admin.fees.reject');

        Route::post('user-requests/{change}/approve', [ManagementController::class, 'approveUserRequest'])->name('admin.user-requests.approve');
        Route::post('user-requests/{change}/reject', [ManagementController::class, 'rejectUserRequest'])->name('admin.user-requests.reject');

        Route::get('settings', [ManagementController::class, 'settings'])->name('admin.settings');
        Route::put('settings', [ManagementController::class, 'updateSettings'])->name('admin.settings.update');
    });

    Route::middleware('role:tsp')->prefix('tsp')->group(function ($router) {
        Route::prefix('registration')->group(function ($router) {
            Route::get('/', [RegistrationController::class, 'show'])->name('tsp.registration.show');
            Route::put('/', [RegistrationController::class, 'update'])->name('tsp.registration.update');
            Route::post('fee/{invoice}/gateway', [RegistrationController::class, 'payOnline'])->name('tsp.registration.pay');
            Route::post('fee/{invoice}/confirm', [RegistrationController::class, 'confirm'])->name('tsp.registration.callback');
            Route::post('fee/{invoice}/transfer', [RegistrationController::class, 'bankTransfer'])->name('tsp.registration.transfer');
            Route::get('fee/{invoice}/receipt', [RegistrationController::class, 'receipt'])->name('tsp.registration.receipt');
        });

        Route::middleware('tsp.access')->group(function ($router) {
            Route::get('access-check', function () {
                return successResponse('Full access.');
            });
        });
    });
});

// Offers, book building, allotment, regulator review, lodgement and reports.
Route::middleware(['auth:api', 'idle', 'active'])->group(function ($router) {
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('offers', [OfferReviewController::class, 'index'])->name('offers.index');
        Route::get('offers/{offer}', [OfferReviewController::class, 'show'])->name('offers.show');
        Route::post('offers/{offer}/notes', [OfferReviewController::class, 'storeNote'])->name('offers.notes');
        Route::post('offers/{offer}/review', [OfferReviewController::class, 'review'])->name('offers.review');
        Route::post('amendments/{amendment}/review', [OfferReviewController::class, 'reviewAmendment'])->name('amendments.review');

        Route::get('audit-trail', [AuditController::class, 'index'])->name('audit');
    });

    Route::middleware(['role:tsp', 'tsp.access'])->prefix('tsp')->name('tsp.')->group(function () {
            Route::get('/', TspDashboardController::class)->name('dashboard');

            Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
            Route::get('offers/create', [OfferController::class, 'create'])->middleware('role:tsp,inputter')->name('offers.create');
            Route::post('offers', [OfferController::class, 'store'])->middleware('role:tsp,inputter')->name('offers.store');
            Route::get('offers/agents-template', [OfferController::class, 'agentsTemplate'])->name('offers.agents-template');
            Route::post('offers/agents-import', [OfferController::class, 'importAgents'])->name('offers.agents-import');
            Route::get('offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
            Route::get('offers/{offer}/edit', [OfferController::class, 'edit'])->middleware('role:tsp,inputter')->name('offers.edit');
            Route::put('offers/{offer}', [OfferController::class, 'update'])->middleware('role:tsp,inputter')->name('offers.update');
            Route::post('offers/{offer}/documents', [OfferController::class, 'uploadDocument'])->middleware('role:tsp,inputter')->name('offers.documents.store');
            Route::delete('offers/{offer}/documents/{document}', [OfferController::class, 'deleteDocument'])->middleware('role:tsp,inputter')->name('offers.documents.destroy');
            Route::post('offers/{offer}/submit', [OfferController::class, 'submit'])->middleware('role:tsp,inputter')->name('offers.submit');
            Route::post('offers/{offer}/authorise', [OfferController::class, 'authorise'])->middleware('role:tsp,authoriser')->name('offers.authorise');
            Route::post('offers/{offer}/launch', [OfferController::class, 'launch'])->middleware('role:tsp,inputter')->name('offers.launch');
            Route::get('offers/{offer}/amend', [OfferController::class, 'amend'])->middleware('role:tsp,inputter')->name('offers.amend');
            Route::post('offers/{offer}/amendments', [OfferController::class, 'storeAmendment'])->middleware('role:tsp,inputter')->name('offers.amendments.store');
            Route::post('amendments/{amendment}/review', [OfferController::class, 'reviewAmendment'])->middleware('role:tsp,authoriser')->name('amendments.review');

            Route::get('order-book', [OrderBookController::class, 'index'])->name('order-book.index');
            Route::get('order-book/{offer}', [OrderBookController::class, 'show'])->name('order-book.show');
            Route::get('order-book/{offer}/export', [OrderBookController::class, 'export'])->name('order-book.export');
            Route::post('bids/{bid}/payment', [OrderBookController::class, 'reviewPayment'])->name('bids.payment');

            Route::get('allotments', [AllotmentController::class, 'index'])->name('allotments.index');
            Route::get('allotments/{offer}', [AllotmentController::class, 'show'])->name('allotments.show');
            Route::post('allotments/{offer}/initiate', [AllotmentController::class, 'initiate'])->middleware('role:tsp,inputter')->name('allotments.initiate');
            Route::put('allotments/{offer}/method', [AllotmentController::class, 'configure'])->middleware('role:tsp,inputter')->name('allotments.configure');
            Route::post('allotment-items/{item}/override', [AllotmentController::class, 'override'])->middleware('role:tsp,inputter')->name('allotments.override');
            Route::post('allotments/{offer}/documents', [AllotmentController::class, 'attach'])->middleware('role:tsp,inputter')->name('allotments.documents');
            Route::post('allotments/{offer}/submit', [AllotmentController::class, 'submit'])->middleware('role:tsp,inputter')->name('allotments.submit');
            Route::post('allotments/{offer}/cancel', [AllotmentController::class, 'cancel'])->middleware('role:tsp,inputter')->name('allotments.cancel');
            Route::post('allotments/{offer}/authorise', [AllotmentController::class, 'authorise'])->middleware('role:tsp,authoriser')->name('allotments.authorise');
            Route::post('allotments/{offer}/lodge', [AllotmentController::class, 'lodge'])->middleware('role:tsp,inputter')->name('allotments.lodge');
            Route::get('allotments/{offer}/export', [AllotmentController::class, 'export'])->name('allotments.export');

            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/exceptions', [ReportController::class, 'exceptions'])->name('reports.exceptions');
            Route::get('reports/{offer}', [ReportController::class, 'show'])->name('reports.show');
            Route::get('reports/{offer}/summary', [ReportController::class, 'summaryExport'])->name('reports.summary-export');

            Route::get('audit-trail', [AuditController::class, 'index'])->name('audit');
    });

    Route::middleware('role:investor')->prefix('investor')->name('investor.')->group(function () {
        Route::get('/', [InvestorController::class, 'dashboard'])->name('dashboard');
        Route::get('offers', [InvestorController::class, 'offers'])->name('offers.index');
        Route::get('offers/{offer}', [InvestorController::class, 'offer'])->name('offers.show');
        Route::get('history', [InvestorController::class, 'history'])->name('history');
        Route::get('audit-trail', [AuditController::class, 'index'])->name('audit');

        Route::get('offers/{offer}/bid', [BidController::class, 'create'])->name('bids.create');
        Route::post('offers/{offer}/bids', [BidController::class, 'store'])->middleware('throttle:20,1')->name('bids.store');
        Route::get('bids/{bid}', [BidController::class, 'show'])->name('bids.show');
        Route::get('bids/{bid}/edit', [BidController::class, 'edit'])->name('bids.edit');
        Route::put('bids/{bid}', [BidController::class, 'update'])->name('bids.update');
        Route::post('bids/{bid}/revoke', [BidController::class, 'revoke'])->name('bids.revoke');
        Route::post('bids/{bid}/evidence', [BidController::class, 'evidence'])->name('bids.evidence');
    });

    Route::middleware('role:regulator')->prefix('regulator')->name('regulator.')->group(function () {
        Route::get('/', [RegulatorController::class, 'index'])->name('dashboard');
        Route::get('allotments/{allotment}', [RegulatorController::class, 'show'])->name('allotments.show');
        Route::post('allotments/{allotment}/decision', [RegulatorController::class, 'decide'])->name('allotments.decide');
        Route::get('allotments/{allotment}/export', [RegulatorController::class, 'export'])->name('allotments.export');
    });

    Route::middleware('role:csd|admin')->prefix('csd')->name('csd.')->group(function () {
        Route::get('lodgements', [LodgementController::class, 'index'])->name('lodgements.index');
        Route::get('lodgements/{allotment}', [LodgementController::class, 'show'])->name('lodgements.show');
        Route::get('lodgements/{allotment}/download', [LodgementController::class, 'download'])->name('lodgements.download');
    });
});
