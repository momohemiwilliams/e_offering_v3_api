<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings: the signed-in user's own profile and password. */
class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->fill($data);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return successResponse('Profile saved.');
    }

    public function updatePassword(Request $request, AuditLogger $audit, PasswordPolicy $policy): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:api'],
            'password' => ['required', $policy->rule(), 'confirmed'],
        ]);

        $policy->change($request->user(), $data['password']);
        $audit->log('user.password_changed', 'Changed own password', $request->user());

        return successResponse('Password saved.');
    }
}
