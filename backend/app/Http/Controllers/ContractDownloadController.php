<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractPdfService;

class ContractDownloadController extends Controller
{
    public function __construct(
        private readonly ContractPdfService $contractPdfService,
    ) {}

    public function downloadContract($id)
    {
        $contract = Contract::with('signers')->findOrFail($id);

        if ($contract->status !== 'closed') {
            abort(403, 'Vertrag kann erst nach Schließung heruntergeladen werden.');
        }

        $pdfOutput = $this->contractPdfService->generate($contract);

        return response()->streamDownload(function () use ($pdfOutput) {
            echo $pdfOutput;
        }, 'Vertrag_' . $contract->id . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
