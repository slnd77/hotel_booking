<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GuestConsent;
use App\Models\Property;
use App\Services\GstCompliance;
use App\Services\LegalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Privacy & Terms: the property's privacy policy, terms of booking, marketing
 * consent and grievance officer; the consents guests gave; and the GST invoice check.
 */
class LegalSettingController extends Controller
{
    public function __construct(private LegalSettings $settings) {}

    /**
     * Opens on the property picked in the header.
     */
    public function index(): Response
    {
        return Inertia::render('admin/legal-settings/Index');
    }

    public function edit(Property $property, GstCompliance $gst): Response
    {
        return Inertia::render('admin/legal-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'settings' => $this->settings->get($property),
            'publicUrls' => [
                'privacy' => route('legal.privacy', $property),
                'terms' => route('legal.terms', $property),
            ],
            'consentCounts' => GuestConsent::query()->where('property_id', $property->id)
                ->selectRaw('purpose, SUM(CASE WHEN granted THEN 1 ELSE 0 END) as granted, SUM(CASE WHEN granted THEN 0 ELSE 1 END) as refused')
                ->groupBy('purpose')
                ->get()
                ->mapWithKeys(fn ($row): array => [$row->purpose => ['granted' => (int) $row->granted, 'refused' => (int) $row->refused]]),
            'gstChecks' => $gst->check($property),
            'activityLogs' => ActivityLog::query()->where('property_id', $property->id)->where('action', 'like', 'Privacy & Terms:%')
                ->with('user:id,name')->latest('date')->limit(30)->get()
                ->map(fn (ActivityLog $log): array => ['action' => $log->action, 'user' => $log->user?->name, 'at' => $log->date?->toIso8601String()]),
        ]);
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $validated = $request->validate([
            'privacy_policy' => ['required', 'string', 'max:30000'],
            'terms' => ['required', 'string', 'max:30000'],
            'marketing_opt_in' => ['boolean'],
            'grievance_officer_name' => ['nullable', 'string', 'max:120'],
            'grievance_officer_email' => ['required', 'email', 'max:255'],
            'grievance_officer_phone' => ['nullable', 'string', 'max:30'],
        ], [
            'grievance_officer_email.required' => 'Guests need an e-mail address to send their data requests to.',
        ]);

        $this->settings->save($property, $validated, $request->user());

        return back()->with('success', 'Privacy & terms saved.');
    }
}
