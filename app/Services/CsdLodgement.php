<?php

namespace App\Services;

use App\Models\Allotment;
use App\Models\OfferDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * CSD lodgement (CR s5.4). Builds the Investor-level lodgement report and transmits it.
 *
 * Driver "file" (default): stores the report as a lodgement document that both the CSD
 * and the TSP/RM can download. Driver "http": additionally POSTs it to CSD_LODGEMENT_URL
 * and records the returned status/confirmation where supported.
 */
class CsdLodgement
{
    public const HEADINGS = [
        'S/N', 'Investor Name', 'Investor Category', 'Bid Reference', 'CSD Name', 'CSD Account No.',
        'Amount Subscribed', 'Amount Allotted', 'Units Allotted', 'Amount Released', 'Bank Name', 'Account Name', 'Account Number',
    ];

    private DocumentStore $documents;

    public function __construct(DocumentStore $documents)
    {
        $this->documents = $documents;
    }

    public function rows(Allotment $allotment): array
    {
        $rows = [];
        $n = 0;
        foreach ($allotment->items()->with('bid')->where('allotted', '>', 0)->get() as $item) {
            $bid = $item->bid;
            $rows[] = [
                ++$n, $bid->investor_name, strtoupper(str_replace('_', '-', $bid->investor_category)), $bid->reference,
                $bid->csd_name, $bid->csd_account_number, $item->subscribed, $item->allotted, $item->units,
                $item->released, $bid->bank_name, $bid->bank_account_name, $bid->bank_account_number,
            ];
        }

        return $rows;
    }

    /**
     * @return array{reference: string, status: string, message: ?string}
     */
    public function transmit(Allotment $allotment): array
    {
        $reference = 'LDG-'.$allotment->offer->reference.'-'.Str::upper(Str::random(4));
        $csv = $this->csv($allotment);
        $path = $this->documents->put('lodgements/'.$allotment->offer_id.'/'.$reference.'.csv', $csv);

        OfferDocument::create([
            'offer_id' => $allotment->offer_id,
            'allotment_id' => $allotment->id,
            'category' => 'lodgement',
            'document_type' => 'Lodgement Summary',
            'original_name' => $reference.'.csv',
            'path' => $path,
            'size' => strlen($csv),
            'visible_to_investors' => false,
        ]);

        $status = 'transmitted';
        $message = null;

        if (config('services.csd.driver') === 'http' && config('services.csd.url')) {
            try {
                $response = Http::withToken((string) config('services.csd.token'))->asJson()->post((string) config('services.csd.url'), [
                    'reference' => $reference,
                    'offer' => $allotment->offer->reference,
                    'issuer' => $allotment->offer->issuer_name,
                    'rows' => array_map(function ($row) {
                        return array_combine(self::HEADINGS, $row);
                    }, $this->rows($allotment)),
                ]);
                $status = $response->successful() ? (string) ($response->json('status') ?? 'confirmed') : 'failed';
                $message = (string) ($response->json('message') ?? '');
            } catch (Throwable $e) {
                report($e);
                $status = 'failed';
                $message = 'The CSD could not be reached; the report is available for manual lodgement.';
            }
        }

        return ['reference' => $reference, 'status' => $status, 'message' => $message];
    }

    private function csv(Allotment $allotment): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADINGS);
        foreach ($this->rows($allotment) as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
