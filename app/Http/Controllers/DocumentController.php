<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\Institution;
use App\Models\OfferDocument;
use App\Models\PlatformFeeInvoice;
use App\Models\User;
use App\Services\DocumentStore;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Streams private uploads after an access check (nothing under storage/ is public). */
class DocumentController extends Controller
{
    private DocumentStore $store;

    public function __construct(DocumentStore $store)
    {
        $this->store = $store;
    }

    public function show(Request $request, OfferDocument $document): BinaryFileResponse
    {
        $user = $this->viewer($request);
        $offer = $document->offer;

        if ($user->isInvestor()) {
            Access::abortUnless($document->visible_to_investors && $document->category === OfferDocument::OFFER && Access::canViewOffer($user, $offer));
        } else {
            Access::abortUnless(Access::canViewOffer($user, $offer));
        }

        return $this->file($document->path, $document->original_name);
    }

    public function bidEvidence(Request $request, Bid $bid): BinaryFileResponse
    {
        $user = $this->viewer($request);
        $allowed = $bid->user_id === $user->id
            || $user->isAdmin()
            || ($user->isTsp() && Access::tspCanView($user, $bid->offer))
            || ($user->isRegulator() && Access::canViewOffer($user, $bid->offer));
        Access::abortUnless($allowed);

        return $this->file($bid->payment_evidence_path, $bid->reference . '-payment');
    }

    public function feeEvidence(Request $request, PlatformFeeInvoice $invoice): BinaryFileResponse
    {
        $user = $this->viewer($request);
        Access::abortUnless($user->isAdmin() || ($user->isTsp() && $user->institution_id === $invoice->institution_id));

        return $this->file($invoice->evidence_path, $invoice->reference . '-evidence');
    }

    public function institutionFile(Request $request, Institution $institution, int $index): BinaryFileResponse
    {
        $user = $this->viewer($request);
        Access::abortUnless($user->isAdmin() || $user->institution_id === $institution->id);

        if ($index === 0 && $institution->proof_of_registration_path) {
            return $this->file($institution->proof_of_registration_path, 'proof-of-registration');
        }

        $doc = ((array) $institution->documents)[$index - 1] ?? null;
        abort_unless($doc, 404);

        return $this->file($doc['path'], $doc['name'] ?? 'document');
    }

    /** The signed link names who it was issued to; their current access still applies. */
    private function viewer(Request $request): User
    {
        $user = User::findOrFail($request->query('uid'));
        abort_unless($user->isActive(), 403);

        return $user;
    }

    private function file(?string $path, string $name): BinaryFileResponse
    {
        abort_unless($this->store->exists($path), 404, 'File not found.');

        $extension = pathinfo((string) $path, PATHINFO_EXTENSION);
        if ($extension && !str_ends_with(strtolower($name), '.' . strtolower($extension))) {
            $name .= '.' . $extension;
        }

        return response()->file($this->store->absolutePath($path), [
            'Content-Disposition' => 'inline; filename="' . addslashes($name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
