<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\OutletTable;
use App\Models\Property;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings → Outlets → an outlet → Tables: its tables, their places on the floor plan the POS
 * shows, each table's ordering QR code, and whether guests may order from those codes.
 */
class OutletTableController extends Controller
{
    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $table = $outlet->tables()->create([...$this->validated($request, $outlet), 'property_id' => $property->id]);
        $this->log($request, $property, "Outlet {$outlet->name}: table {$table->name} added");

        return back()->with('success', "Table {$table->name} added.");
    }

    public function update(Request $request, Property $property, Outlet $outlet, OutletTable $table): RedirectResponse
    {
        $table->update($this->validated($request, $outlet, $table));

        return back()->with('success', "Table {$table->name} saved.");
    }

    public function destroy(Request $request, Property $property, Outlet $outlet, OutletTable $table): RedirectResponse
    {
        $table->delete();
        $this->log($request, $property, "Outlet {$outlet->name}: table {$table->name} deleted");

        return back()->with('success', "Table {$table->name} deleted.");
    }

    /**
     * Saves where every table sits on the floor plan (the plan editor's drag and drop).
     */
    public function layout(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'tables' => ['required', 'array', 'max:500'],
            'tables.*.id' => ['required', 'integer', Rule::exists(OutletTable::class, 'id')->where('outlet_id', $outlet->id)],
            'tables.*.pos_x' => ['required', 'integer', 'between:0,59'],
            'tables.*.pos_y' => ['required', 'integer', 'between:0,59'],
        ]);

        foreach ($data['tables'] as $position) {
            $outlet->tables()->whereKey($position['id'])->update(['pos_x' => $position['pos_x'], 'pos_y' => $position['pos_y']]);
        }

        return back()->with('success', 'Floor plan saved.');
    }

    /**
     * Whether guests may order from the tables' QR codes.
     */
    public function settings(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $enabled = $request->validate(['qr_ordering' => ['required', 'boolean']])['qr_ordering'];
        $outlet->update(['qr_ordering' => $enabled]);
        $this->log($request, $property, "Outlet {$outlet->name}: QR ordering turned ".($enabled ? 'on' : 'off'));

        return back()->with('success', $enabled ? 'Guests can now order from the table QR codes.' : 'Ordering from the table QR codes is off.');
    }

    /**
     * The table's ordering QR code (SVG) to print and put on the table.
     */
    public function qr(Property $property, Outlet $outlet, OutletTable $table): Response
    {
        $url = route('table-order.show', $table->qrToken());
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))->writeString($url);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="'.Str::slug("{$outlet->name} table {$table->name}").'.svg"',
            'X-Order-Url' => $url,
        ]);
    }

    /**
     * A new code for the table: the old printed one stops working.
     */
    public function renewQr(Request $request, Property $property, Outlet $outlet, OutletTable $table): RedirectResponse
    {
        $table->qrToken(renew: true);
        $this->log($request, $property, "Outlet {$outlet->name}: QR code of table {$table->name} renewed");

        return back()->with('success', "Table {$table->name} has a new QR code. Print it; the old one no longer works.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Outlet $outlet, ?OutletTable $table = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:20', Rule::unique(OutletTable::class, 'name')->where('outlet_id', $outlet->id)->ignore($table?->id)],
            'seats' => ['required', 'integer', 'between:1,50'],
            'area' => ['nullable', 'string', 'max:60'],
            'shape' => ['required', Rule::in(OutletTable::SHAPES)],
            'status' => ['boolean'],
        ]);
    }

    private function log(Request $request, Property $property, string $action): void
    {
        ActivityLog::create(['user_id' => $request->user()->id, 'property_id' => $property->id, 'action' => $action, 'date' => now()]);
    }
}
