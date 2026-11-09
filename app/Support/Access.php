<?php

namespace App\Support;

use App\Models\Allotment;
use App\Models\Offer;
use App\Models\User;

/**
 * Record-level access rules shared by controllers (route middleware already checks the area).
 */
class Access
{
    /** Lead sponsor or a co-sponsor with order-book access. */
    public static function tspCanView(User $user, Offer $offer): bool
    {
        if (! $user->isTsp() || ! $user->institution_id) {
            return false;
        }
        if ($offer->institution_id === $user->institution_id) {
            return true;
        }

        return $offer->coSponsors()->where('institution_id', $user->institution_id)->exists();
    }

    /** Only the lead sponsor's own users can change the offer. */
    public static function tspOwns(User $user, Offer $offer): bool
    {
        return $user->isTsp() && $user->institution_id !== null && $offer->institution_id === $user->institution_id;
    }

    /** Lead sponsor, or a co-sponsor allowed to confirm payments. */
    public static function tspCanConfirmPayments(User $user, Offer $offer): bool
    {
        if (static::tspOwns($user, $offer)) {
            return true;
        }

        return $user->isTsp() && $offer->coSponsors()->where('institution_id', $user->institution_id)
            ->where('can_confirm_payments', true)->exists();
    }

    public static function investorCanSee(User $user, Offer $offer): bool
    {
        return $user->isInvestor()
            && in_array($offer->status, [Offer::LIVE], true)
            && in_array($user->investorCategory(), (array) $offer->investor_universe, true);
    }

    public static function canViewOffer(User $user, Offer $offer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isTsp()) {
            return static::tspCanView($user, $offer);
        }
        if ($user->isInvestor()) {
            return static::investorCanSee($user, $offer) || $offer->bids()->where('user_id', $user->id)->exists();
        }
        if ($user->isRegulator() || $user->isCsd()) {
            $allotment = $offer->allotment;

            return $allotment && $allotment->status !== Allotment::DRAFT;
        }

        return false;
    }

    public static function abortUnless(bool $allowed): void
    {
        abort_unless($allowed, 403, 'You do not have access to this record.');
    }
}
