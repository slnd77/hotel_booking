<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CreditNotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Credit notes on the Invoice database: issued against an invoice, printed or downloaded.
 */
class CreditNoteController extends Controller
{
    public function store(Request $request, Invoice $invoice, CreditNotes $creditNotes): RedirectResponse
    {
        $validated = $request->validate([
            'total' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $note = $creditNotes->issue($invoice, (float) $validated['total'], $validated['reason'], $request->user());

        return back()->with('success', "Credit note {$note->credit_note_number} issued for {$note->total}.");
    }

    public function print(Request $request, CreditNote $creditNote, CreditNotes $creditNotes): View
    {
        return view('prints.credit-note', [
            ...$creditNotes->printData($creditNote),
            'autoPrint' => ! $request->boolean('preview'),
            'pdfUrl' => route('admin.databases.invoices.creditNotes.pdf', $creditNote),
        ]);
    }

    public function pdf(CreditNote $creditNote, CreditNotes $creditNotes): PdfBuilder
    {
        return $creditNotes->pdf($creditNote)->download();
    }
}
