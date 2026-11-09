<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\Institution;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends in-app + email notifications and knows who should receive them.
 */
class Notifier
{
    /**
     * @param iterable<User>|User $users
     */
    public function send($users, string $title, string $message, ?string $url = null): void
    {
        $users = $users instanceof User ? collect([$users]) : collect($users);
        $users = $users->filter(function ($u) {
            return $u instanceof User && $u->status !== User::STATUS_DISABLED;
        })->unique('id');

        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, new PlatformNotification($title, $message, $url));
        } catch (Throwable $e) {
            // A mail outage must never block the business action that triggered it.
            report($e);
        }
    }

    public function tspUsers(int $institutionId, ?string $accessLevel = null): Collection
    {
        return User::where('type', User::TYPE_TSP)->where('institution_id', $institutionId)
            ->where('status', User::STATUS_ACTIVE)
            ->when($accessLevel, function ($q) use ($accessLevel) {
                $q->where('access_level', $accessLevel);
            })->get();
    }

    public function admins(): Collection
    {
        return User::where('type', User::TYPE_ADMIN)->where('status', User::STATUS_ACTIVE)->get();
    }

    public function regulators(): Collection
    {
        return User::where('type', User::TYPE_REGULATOR)->where('status', User::STATUS_ACTIVE)->get();
    }

    public function csdUsers(): Collection
    {
        return User::where('type', User::TYPE_CSD)->where('status', User::STATUS_ACTIVE)->get();
    }

    /** Every investor with an active bid on the offer (s4.6 step 4, s6.1 step 5). */
    public function activeBidders(Offer $offer): Collection
    {
        $ids = Bid::where('offer_id', $offer->id)->where('status', Bid::ACTIVE)->pluck('user_id')->unique();

        return User::whereIn('id', $ids)->get();
    }

    /** Investors whose category is in the offer's investor universe. */
    public function eligibleInvestors(Offer $offer): Collection
    {
        $universe = (array) $offer->investor_universe;

        return User::where('type', User::TYPE_INVESTOR)->where('status', User::STATUS_ACTIVE)
            ->with('institution', 'investorProfile')->get()
            ->filter(function (User $u) use ($universe) {
                return in_array($u->investorCategory(), $universe, true);
            });
    }

    public function institutionContacts(Institution $institution): Collection
    {
        return $institution->users()->get();
    }
}
