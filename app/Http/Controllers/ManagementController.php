<?php

namespace App\Http\Controllers;

use App\Models\Allotment;
use App\Models\AuditLog;
use App\Models\Bid;
use App\Models\Institution;
use App\Models\Offer;
use App\Models\PlatformFeeInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserChangeRequest;
use App\Services\AuditLogger;
use App\Services\OnboardingService;
use App\Services\PlatformFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FMDQ Admin: dashboard, staff accounts, platform fees and settings. */
class ManagementController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return successResponse('Dashboard fetched.', [
            'stats' => [
                'open_offers' => Offer::openForBidding()->count(),
                'live_offers' => Offer::where('status', Offer::LIVE)->count(),
                'sponsors' => Institution::where('type', Institution::TYPE_TSP)->where('status', Institution::STATUS_APPROVED)->count(),
                'investors' => User::where('type', User::TYPE_INVESTOR)->where('status', User::STATUS_ACTIVE)->count(),
                'pending_onboarding' => Institution::where('status', Institution::STATUS_PENDING)->count(),
                'pending_fees' => PlatformFeeInvoice::where('status', PlatformFeeInvoice::PENDING_REVIEW)->count(),
                'pending_admin_review' => Offer::where('status', Offer::PENDING_ADMIN_REVIEW)->count(),
                'sec_pending' => Allotment::where('status', Allotment::SEC_PENDING)->count(),
                'total_subscribed' => (float) Bid::where('status', Bid::ACTIVE)->sum('amount'),
            ],
            'offers' => Offer::with('institution:id,name', 'allotment:id,offer_id,status')->latest('id')->limit(8)->get(),
            'activity' => AuditLog::latest('id')->limit(10)->get(),
            'adminReviewEnabled' => (bool) Setting::get('fmdq_admin_review_enabled'),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::with('institution:id,name,type,regulator_body')
            ->when($request->query('type'), function ($q, $type) {
                $q->where('type', $type);
            })
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', '%' . $term . '%')->orWhere('email', 'like', '%' . $term . '%');
                });
            })
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(function (User $u) {
                return array_merge($u->only(['id', 'name', 'email', 'type', 'access_level', 'status', 'last_login_at']), [
                    'role_label' => $u->roleLabel(),
                    'institution' => optional($u->institution)->name,
                ]);
            });

        $requests = UserChangeRequest::with('requester:id,name')->where('status', UserChangeRequest::PENDING)->latest('id')->get()
            ->map(function (UserChangeRequest $r) use ($request) {
                return array_merge($r->only(['id', 'action', 'target_name', 'target_email', 'created_at']), [
                    'requester' => optional($r->requester)->name,
                    'can_review' => $r->requested_by !== $request->user()->id,
                ]);
            });

        return successResponse('Users fetched.', ['users' => $users, 'requests' => $requests, 'filters' => $request->only('type', 'search')]);
    }

    /** FMDQ staff accounts (Admin and CSD / Depository users). */
    public function storeUser(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'type' => ['required', Rule::in([User::TYPE_ADMIN, User::TYPE_CSD])],
        ]);

        $user = User::create(array_merge($data, ['status' => User::STATUS_PENDING]));
        $this->openRequest('create', $user, $request->user());
        $audit->log('user.create_requested', 'Requested ' . $user->roleLabel() . ' account for ' . $user->email, $user, [], $request->user());

        return successResponse($user->name . ' was submitted. Another admin must approve the account.');
    }

    /** Applies a pending change. The admin who asked for it cannot approve it. */
    public function approveUserRequest(Request $request, UserChangeRequest $change, OnboardingService $onboarding, AuditLogger $audit): JsonResponse
    {
        $this->assertReviewable($request, $change);
        $target = $change->target;
        abort_unless($target, 422, 'This user no longer exists.');

        if ($change->action === 'disable') {
            $target->forceFill(['status' => User::STATUS_DISABLED])->save();
        } else {
            $target->forceFill(['status' => User::STATUS_ACTIVE])->save();
        }
        if ($change->action === 'create') {
            $onboarding->sendActivation($target, 'Your FMDQ e-Offering staff account');
        }

        $this->closeRequest($change, $request->user(), 'approved');
        $audit->log('user.change_approved', 'Approved ' . $change->action . ' for ' . $change->target_email, $target, [], $request->user());

        return successResponse('Approved: ' . $change->action . ' ' . $change->target_name . '.');
    }

    public function rejectUserRequest(Request $request, UserChangeRequest $change, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]);
        $this->assertReviewable($request, $change);

        $this->closeRequest($change, $request->user(), 'rejected', $data['comment']);
        $audit->log('user.change_rejected', 'Rejected ' . $change->action . ' for ' . $change->target_email, null, ['reason' => $data['comment']], $request->user());

        // An account that was never approved has nothing to keep.
        if ($change->action === 'create' && $change->target) {
            $change->target->delete();
        }

        return successResponse('Rejected: ' . $change->action . ' ' . $change->target_name . '.');
    }

    private function openRequest(string $action, User $target, User $requester): void
    {
        UserChangeRequest::create([
            'action' => $action,
            'target_user_id' => $target->id,
            'target_name' => $target->name,
            'target_email' => $target->email,
            'requested_by' => $requester->id,
        ]);
    }

    private function assertReviewable(Request $request, UserChangeRequest $change): void
    {
        abort_unless($change->status === UserChangeRequest::PENDING, 422, 'This request has already been decided.');
        abort_if($change->requested_by === $request->user()->id, 422, 'A different admin must review this request.');
    }

    private function closeRequest(UserChangeRequest $change, User $reviewer, string $status, ?string $comment = null): void
    {
        $change->forceFill(['status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_comment' => $comment])->save();
    }

    public function toggleUser(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot disable your own account.');

        abort_if(UserChangeRequest::where('target_user_id', $user->id)->where('status', UserChangeRequest::PENDING)->exists(), 422, 'This user already has a change waiting for approval.');

        $action = $user->status === User::STATUS_DISABLED ? 'enable' : 'disable';
        $this->openRequest($action, $user, $request->user());
        $audit->log('user.status_requested', 'Requested ' . $action . ' for ' . $user->email, $user, [], $request->user());

        return successResponse('Submitted. Another admin must approve this change.');
    }

    public function fees(Request $request): JsonResponse
    {
        $status = $request->query('status', PlatformFeeInvoice::PENDING_REVIEW);

        return successResponse('Platform fees fetched.', [
            'invoices' => PlatformFeeInvoice::with('institution:id,name,reference', 'reviewer:id,name')
                ->when($status !== 'all', function ($q) use ($status) {
                    $q->where('status', $status);
                })
                ->latest('id')->paginate(20)->withQueryString()
                ->through(function (PlatformFeeInvoice $i) {
                    return array_merge($i->toArray(), [
                        'evidence_url' => $i->evidence_path ? signedFileUrl('fees.evidence', $i) : null,
                    ]);
                }),
            'filters' => ['status' => $status],
            'feeEnabled' => (bool) Setting::get('platform_fee_enabled'),
        ]);
    }

    public function approveFee(Request $request, PlatformFeeInvoice $invoice, PlatformFeeService $fees): JsonResponse
    {
        abort_unless($invoice->status === PlatformFeeInvoice::PENDING_REVIEW, 422, 'This payment is not awaiting review.');
        $fees->approve($invoice, $request->user());

        return successResponse('Payment ' . $invoice->reference . ' confirmed. The TSP/RM was notified and can download the receipt.');
    }

    public function rejectFee(Request $request, PlatformFeeInvoice $invoice, PlatformFeeService $fees): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]);
        abort_unless($invoice->status === PlatformFeeInvoice::PENDING_REVIEW, 422, 'This payment is not awaiting review.');
        $fees->reject($invoice, $request->user(), $data['comment']);

        return successResponse('Payment ' . $invoice->reference . ' was rejected.');
    }

    public function settings(): JsonResponse
    {
        return successResponse('Settings fetched.', [
            'settings' => Setting::allValues(),
            'securityTypes' => collect(config('eoffering.security_types'))->map(function ($t, $k) {
                return ['value' => $k, 'label' => $t['label']];
            })->values(),
        ]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'fmdq_admin_review_enabled' => ['required', 'boolean'],
            'fmdq_admin_review_security_types' => ['array'],
            'fmdq_admin_review_security_types.*' => [Rule::in(array_keys(config('eoffering.security_types')))],
            'platform_fee_enabled' => ['required', 'boolean'],
            'platform_fee_amount' => ['required', 'numeric', 'min:0'],
            'platform_fee_bank_name' => ['nullable', 'string', 'max:100'],
            'platform_fee_account_name' => ['nullable', 'string', 'max:191'],
            'platform_fee_account_number' => ['nullable', 'string', 'max:20'],
        ]);

        $before = Setting::allValues();
        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }
        $audit->log('settings.updated', 'Updated platform settings', null, ['before' => $before, 'after' => $data], $request->user());

        return successResponse('Settings saved.');
    }
}
