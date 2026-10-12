<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Property;
use App\Models\User;
use App\Services\CashierShifts;
use App\Services\PermissionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Front office → Cashier Shift: open a shift with a float, see what it has taken, close it
 * with the cash count (and hand over to the next cashier). Shifts of the last 30 days are
 * listed: the user's own, or everyone's with "View all cashier shifts".
 */
class CashierShiftController extends Controller
{
    /**
     * Opens on the property picked in the header.
     */
    public function index(): Response
    {
        return Inertia::render('admin/cashier-shifts/Index');
    }

    public function show(Request $request, Property $property, CashierShifts $shifts): Response
    {
        $user = $request->user();
        $viewAll = app(PermissionGate::class)->allows($user, 'front_office.cashier_shifts_view_all');
        $current = $shifts->current($property, $user);

        $history = CashierShift::query()
            ->where('property_id', $property->id)
            ->where('opened_at', '>=', now()->subDays(30))
            ->unless($viewAll, fn ($query) => $query->where('user_id', $user->id))
            ->with(['user:id,name', 'handedOverTo:id,name'])
            ->latest('opened_at')
            ->limit(200)
            ->get()
            ->map(fn (CashierShift $shift): array => [
                'id' => $shift->id,
                'cashier' => $shift->user?->name,
                'opened_at' => $shift->opened_at->toIso8601ZuluString(),
                'closed_at' => $shift->closed_at?->toIso8601ZuluString(),
                'opening_float' => (float) $shift->opening_float,
                'cash_receipts' => $shift->isOpen() ? null : (float) $shift->cash_receipts,
                'cash_refunds' => $shift->isOpen() ? null : (float) $shift->cash_refunds,
                'expected_cash' => $shift->isOpen() ? null : (float) $shift->expected_cash,
                'cash_counted' => $shift->isOpen() ? null : (float) $shift->cash_counted,
                'variance' => $shift->isOpen() ? null : (float) $shift->variance,
                'handed_over_to' => $shift->handedOverTo?->name,
                'closing_notes' => $shift->closing_notes,
            ]);

        return Inertia::render('admin/cashier-shifts/Show', [
            'property' => $property->only(['id', 'name']),
            'current' => $current ? [
                'id' => $current->id,
                'opened_at' => $current->opened_at->toIso8601ZuluString(),
                'opening_float' => (float) $current->opening_float,
                'opening_notes' => $current->opening_notes,
                ...$shifts->summary($current),
            ] : null,
            'cashiers' => User::query()->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name']),
            'history' => $history,
            'viewAll' => $viewAll,
        ]);
    }

    public function open(Request $request, Property $property, CashierShifts $shifts): RedirectResponse
    {
        $validated = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shifts->open($property, $request->user(), (float) $validated['opening_float'], $validated['opening_notes'] ?? null);

        return back()->with('success', 'Shift opened.');
    }

    public function close(Request $request, Property $property, CashierShifts $shifts): RedirectResponse
    {
        $validated = $request->validate([
            'cash_counted' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'closing_notes' => ['nullable', 'string', 'max:500'],
            'handed_over_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $shift = $shifts->current($property, $request->user())
            ?? throw ValidationException::withMessages(['cash_counted' => 'You have no shift open at this property.']);

        $shift = $shifts->close(
            $shift,
            (float) $validated['cash_counted'],
            $validated['closing_notes'] ?? null,
            isset($validated['handed_over_to']) ? User::query()->find($request->integer('handed_over_to')) : null,
        );

        $variance = (float) $shift->variance;

        return back()->with('success', match (true) {
            abs($variance) < 0.01 => 'Shift closed. The cash matches.',
            $variance < 0 => 'Shift closed. The drawer is short by '.number_format(abs($variance), 2).'.',
            default => 'Shift closed. The drawer is over by '.number_format($variance, 2).'.',
        });
    }
}
