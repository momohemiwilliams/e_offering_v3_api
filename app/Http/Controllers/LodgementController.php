<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Allotment;
use App\Services\CsdLodgement;
use App\Services\Reports\Exporter;
use Illuminate\Http\Request;

/**
 * CSD / FMDQ Depository view of lodgements (CR s5.4 step 3).
 */
class LodgementController extends Controller
{
    public function index(): JsonResponse
    {
        return successResponse('Fetched.', [
            'allotments' => Allotment::with('offer:id,reference,issuer_name,security_type,institution_id', 'offer.institution:id,name')
                ->whereIn('status', [Allotment::APPROVED, Allotment::LODGED])
                ->latest('lodged_at')->paginate(20),
        ]);
    }

    public function show(Allotment $allotment, CsdLodgement $csd): JsonResponse
    {
        abort_unless(in_array($allotment->status, [Allotment::APPROVED, Allotment::LODGED], true), 404);

        return successResponse('Fetched.', [
            'allotment' => $allotment->load('offer.institution:id,name'),
            'headings' => CsdLodgement::HEADINGS,
            'rows' => $csd->rows($allotment),
            'documents' => $allotment->documents()->where('category', 'lodgement')->get(),
        ]);
    }

    public function download(Request $request, Allotment $allotment, CsdLodgement $csd, Exporter $exporter)
    {
        abort_unless(in_array($allotment->status, [Allotment::APPROVED, Allotment::LODGED], true), 404);

        return $exporter->download('lodgement-summary-'.$allotment->offer->reference, CsdLodgement::HEADINGS, $csd->rows($allotment), (string) $request->query('format', 'xlsx'), 'Lodgement Summary — '.$allotment->offer->issuer_name);
    }
}
