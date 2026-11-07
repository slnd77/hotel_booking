<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\LegalSettings;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The property's public privacy policy and terms of booking (linked from the booking engine
 * checkout and web check-in).
 */
class LegalPageController extends Controller
{
    public function __construct(private LegalSettings $settings) {}

    public function privacy(Property $property): Response
    {
        return $this->page($property, 'privacy');
    }

    public function terms(Property $property): Response
    {
        return $this->page($property, 'terms');
    }

    private function page(Property $property, string $document): Response
    {
        $legal = $this->settings->forGuests($property);

        return Inertia::render('Legal/Show', [
            'property' => ['name' => $property->name],
            'title' => $document === 'privacy' ? 'Privacy Policy' : 'Terms of Booking',
            'body' => $document === 'privacy' ? $legal['privacy_policy'] : $legal['terms'],
            'grievanceOfficer' => $document === 'privacy' ? $legal['grievance_officer'] : null,
            'version' => $legal['version'],
            'otherDocument' => $document === 'privacy'
                ? ['title' => 'Terms of Booking', 'url' => route('legal.terms', $property)]
                : ['title' => 'Privacy Policy', 'url' => route('legal.privacy', $property)],
            'bookingUrl' => route('booking-engine.web', $property),
        ]);
    }
}
