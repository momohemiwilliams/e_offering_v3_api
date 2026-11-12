<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FMDQ Admin review of onboarding requests (CR s3.2 step 4, s3.3 step 2). */
class OnboardingReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', Institution::STATUS_PENDING);
        $type = $request->query('type');

        $institutions = Institution::query()
            ->withCount(['users', 'rejectedUsers'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($type, function ($q) use ($type) {
                $q->where('type', $type);
            })
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', '%' . $term . '%')->orWhere('reference', 'like', '%' . $term . '%')->orWhere('email', 'like', '%' . $term . '%');
                });
            })
            ->latest('id')->paginate(20)->withQueryString();

        // A rejected request's users live in rejected_onboarding_users; count those instead.
        $institutions->getCollection()->each(function (Institution $institution) {
            if ($institution->status === Institution::STATUS_REJECTED) {
                $institution->users_count = $institution->rejected_users_count;
            }
        });

        return successResponse('Onboarding requests fetched.', [
            'institutions' => $institutions,
            'filters' => ['status' => $status, 'type' => $type, 'search' => $request->query('search')],
            'counts' => Institution::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recentRetail' => User::where('type', User::TYPE_INVESTOR)->whereNull('institution_id')
                ->latest('id')->limit(10)->get(['id', 'name', 'email', 'status', 'created_at']),
        ]);
    }

    public function show(Institution $institution): JsonResponse
    {
        // Rejected requests show the users archived at rejection (status "rejected").
        $institution->status === Institution::STATUS_REJECTED
            ? $institution->setRelation('users', $institution->rejectedUsers()->get())
            : $institution->load('users');

        $files = [];
        if ($institution->proof_of_registration_path) {
            $files[] = ['label' => 'Proof of registration', 'url' => signedFileUrl('institutions.file', [$institution->id, 0])];
        }
        foreach ((array) $institution->documents as $i => $doc) {
            $files[] = ['label' => $doc['label'] ?? 'Document', 'name' => $doc['name'] ?? null, 'url' => signedFileUrl('institutions.file', [$institution->id, $i + 1])];
        }

        return successResponse('Onboarding request fetched.', [
            'institution' => array_merge($institution->toArray(), ['type_label' => $institution->typeLabel()]),
            'files' => $files,
            'reviewer' => optional(User::find($institution->reviewed_by))->name,
        ]);
    }

    public function approve(Request $request, Institution $institution, OnboardingService $onboarding): JsonResponse
    {
        abort_unless($institution->status === Institution::STATUS_PENDING, 422, 'This request has already been decided.');
        $onboarding->approve($institution, $request->user());

        return successResponse($institution->name . ' was approved. Each user was emailed a link to set their password.');
    }

    public function reject(Request $request, Institution $institution, OnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_unless($institution->status === Institution::STATUS_PENDING, 422, 'This request has already been decided.');
        $onboarding->reject($institution, $request->user(), $data['reason']);

        return successResponse($institution->name . ' was rejected and notified.');
    }

    public function resend(Institution $institution, OnboardingService $onboarding): JsonResponse
    {
        abort_unless($institution->isApproved(), 422, 'This request has not been approved.');
        foreach ($institution->users()->whereNull('last_login_at')->get() as $user) {
            $onboarding->sendActivation($user, 'Set your e-Offering password');
        }

        return successResponse('Password links were sent again to users who have not signed in yet.');
    }
}
