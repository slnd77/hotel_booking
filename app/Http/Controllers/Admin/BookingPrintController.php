<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingPrintDocument;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Services\BookingPrints;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * A booking's printable documents: as print-ready pages that open the print dialog,
 * or as a downloaded PDF of the same page.
 */
class BookingPrintController extends Controller
{
    public function show(Request $request, Property $property, Booking $booking, BookingPrintDocument $document, BookingPrints $prints): View
    {
        abort_unless($booking->property_id === $property->id, 404);

        return view(BookingPrints::viewFor($document), [
            ...$prints->data($booking, $document, $request->user()),
            'autoPrint' => ! $request->boolean('preview'),
            'pdfUrl' => route('admin.bookings.printPdf', [$property, $booking, $document]),
        ]);
    }

    public function download(Request $request, Property $property, Booking $booking, BookingPrintDocument $document, BookingPrints $prints): PdfBuilder
    {
        abort_unless($booking->property_id === $property->id, 404);

        return $prints->pdf($booking, $document, $request->user())->download();
    }
}
