<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\InvestorProfile;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service onboarding for the four account types (CR s3) and the FMDQ Admin review.
 * New accounts have no password: on approval each user receives a "set your password" link.
 */
class OnboardingService
{
    private AuditLogger $audit;

    private Notifier $notifier;

    private DocumentStore $documents;

    public function __construct(AuditLogger $audit, Notifier $notifier, DocumentStore $documents)
    {
        $this->audit = $audit;
        $this->notifier = $notifier;
        $this->documents = $documents;
    }

    /**
     * CR s3.2 steps 1-3. $users = [[name, email, phone?, access_level]].
     */
    public function registerTsp(array $data, UploadedFile $proof, array $users): Institution
    {
        $this->assertBothAccessLevels($users);

        return DB::transaction(function () use ($data, $proof, $users) {
            $institution = Institution::create([
                'type' => Institution::TYPE_TSP,
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'proof_of_registration_path' => $this->documents->store($proof, 'onboarding/tsp'),
                'status' => Institution::STATUS_PENDING,
                'privacy_consent_at' => now(),
            ]);
            $this->createUsers($institution, User::TYPE_TSP, $users);
            $this->submitted($institution);

            return $institution;
        });
    }

    /**
     * QII / Non-QII onboarding with CAC documents and representatives (Inputter / Authoriser).
     *
     * @param UploadedFile[] $files keyed by document label
     */
    public function registerInstitutionalInvestor(array $data, array $files, array $users): Institution
    {
        $this->assertBothAccessLevels($users);

        return DB::transaction(function () use ($data, $files, $users) {
            $documents = [];
            foreach ($files as $label => $file) {
                if ($file instanceof UploadedFile) {
                    $documents[] = ['label' => $label, 'name' => $file->getClientOriginalName(), 'path' => $this->documents->store($file, 'onboarding/investors')];
                }
            }

            $institution = Institution::create([
                'type' => $data['category'] === 'qii' ? Institution::TYPE_QII : Institution::TYPE_NON_QII,
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'registration_number' => $data['registration_number'],
                'documents' => $documents,
                'status' => Institution::STATUS_PENDING,
                'privacy_consent_at' => now(),
            ]);
            $this->createUsers($institution, User::TYPE_INVESTOR, $users);
            $this->submitted($institution);

            return $institution;
        });
    }

    /** CR s3.3 step 1. */
    public function registerRegulator(array $data): Institution
    {
        return DB::transaction(function () use ($data) {
            $body = strtoupper($data['regulator_body']);
            $institution = Institution::create([
                'type' => Institution::TYPE_REGULATOR,
                'regulator_body' => $body,
                'name' => $body === 'SEC' ? 'Securities and Exchange Commission' : 'Central Bank of Nigeria',
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'],
                'contact_name' => $data['name'],
                'status' => Institution::STATUS_PENDING,
                'privacy_consent_at' => now(),
            ]);
            $this->createUsers($institution, User::TYPE_REGULATOR, [[
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'access_level' => null,
            ]]);
            $this->submitted($institution);

            return $institution;
        });
    }

    /**
     * Retail onboarding after BVN verification. Unchanged from the existing flow: the verified
     * investor is activated straight away and receives a set-password link.
     */
    public function registerRetail(array $bvn, array $data): User
    {
        return DB::transaction(function () use ($bvn, $data) {
            if (InvestorProfile::where('bvn', $bvn['bvn'])->exists()) {
                throw ValidationException::withMessages(['bvn' => 'An investor account already exists for this BVN.']);
            }

            $user = User::create([
                'type' => User::TYPE_INVESTOR,
                'name' => trim($bvn['first_name'].' '.$bvn['last_name']) ?: $data['name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? $bvn['phone'],
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => null,
                'privacy_consent_at' => now(),
            ]);

            $dob = null;
            if (! empty($bvn['date_of_birth']) && strtotime((string) $bvn['date_of_birth'])) {
                $dob = date('Y-m-d', strtotime((string) $bvn['date_of_birth']));
            }

            $user->investorProfile()->create([
                'category' => 'retail',
                'bvn' => $bvn['bvn'],
                'nin' => $bvn['nin'] ?? null,
                'date_of_birth' => $dob,
                'address' => $data['address'] ?? $bvn['address'],
                'csd_name' => $data['csd_name'] ?? null,
                'csd_account_number' => $data['csd_account_number'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_name' => $data['bank_account_name'] ?? null,
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'bvn_payload' => $bvn['raw'] ?? null,
                'bvn_verified_at' => now(),
            ]);

            $this->audit->log('onboarding.retail', 'Retail investor onboarded: '.$user->name, $user, [], $user);
            $this->sendActivation($user, 'Your e-Offering investor account is ready');

            return $user;
        });
    }

    public function approve(Institution $institution, User $admin): void
    {
        DB::transaction(function () use ($institution, $admin) {
            $institution->forceFill([
                'status' => Institution::STATUS_APPROVED,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            foreach ($institution->users as $user) {
                if ($user->status === User::STATUS_PENDING) {
                    $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
                }
            }
        });

        $this->audit->log('onboarding.approved', 'Approved onboarding of '.$institution->name.' ('.$institution->typeLabel().')', $institution, [], $admin);

        foreach ($institution->users()->get() as $user) {
            $this->sendActivation($user, 'Your e-Offering onboarding request was approved');
        }
    }

    public function reject(Institution $institution, User $admin, string $reason): void
    {
        $institution->forceFill([
            'status' => Institution::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->log('onboarding.rejected', 'Rejected onboarding of '.$institution->name, $institution, ['reason' => $reason], $admin);

        $institution->users()->get()->each(function (User $user) use ($reason) {
            $this->mailQuietly($user, new PlatformNotification(
                'Your e-Offering onboarding request was not approved',
                'FMDQ reviewed your onboarding request and could not approve it. Reason: '.$reason,
                null,
                ['mail_only' => true]
            ));
        });
    }

    /** A mail outage must not undo an approval; the link can be re-sent or requested again. */
    private function mailQuietly(User $user, PlatformNotification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Emails a password-setup link (valid 24 hours, see config/auth.php). */
    public function sendActivation(User $user, string $subject): void
    {
        $token = Password::broker()->createToken($user);
        $url = frontendUrl('/auth/reset-password?token='.$token.'&email='.urlencode($user->email));

        $this->mailQuietly($user, new PlatformNotification(
            $subject,
            'Set a password to start using the FMDQ e-Offering Platform. The link expires in 24 hours; you can request a new one from "Forgot password" on the sign-in page.',
            $url,
            ['mail_only' => true, 'button' => 'Set your password']
        ));
    }

    /**
     * CR s3.2 step 3: at least one Inputter and one Authoriser before the request can be submitted.
     */
    private function assertBothAccessLevels(array $users): void
    {
        $levels = array_column($users, 'access_level');
        if (! in_array(User::INPUTTER, $levels, true) || ! in_array(User::AUTHORISER, $levels, true)) {
            throw ValidationException::withMessages([
                'users' => 'Add at least one Inputter and one Authoriser before submitting.',
            ]);
        }

        $emails = array_map('strtolower', array_column($users, 'email'));
        if (count($emails) !== count(array_unique($emails))) {
            throw ValidationException::withMessages(['users' => 'Each user needs a different email address.']);
        }
    }

    private function createUsers(Institution $institution, string $type, array $users): void
    {
        foreach ($users as $row) {
            $institution->users()->create([
                'type' => $type,
                'access_level' => $row['access_level'] ?? null,
                'name' => $row['name'],
                'email' => Str::lower($row['email']),
                'phone' => $row['phone'] ?? null,
                'status' => User::STATUS_PENDING,
                'privacy_consent_at' => now(),
            ]);
        }
    }

    private function submitted(Institution $institution): void
    {
        $this->audit->log('onboarding.submitted', 'Onboarding request submitted by '.$institution->name.' ('.$institution->typeLabel().')', $institution);
        $this->notifier->send(
            $this->notifier->admins(),
            'New onboarding request: '.$institution->name,
            $institution->typeLabel().' onboarding request '.$institution->reference.' is waiting for review.',
            frontendUrl('/admin/onboarding/'.$institution->id)
        );
    }
}
