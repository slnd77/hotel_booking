<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutletType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\OutletDisplayCategory;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletTable;
use App\Models\OutletTaxRate;
use App\Models\Property;
use App\Services\PosPrints;
use App\Services\PropertySettings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outlets → Settings: a property's outlets, and one outlet's display categories,
 * catalogue items and price plans.
 */
class OutletController extends Controller
{
    public const LOG_PREFIX = 'Outlets';

    public function index(): Response
    {
        return Inertia::render('admin/outlets/Index');
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/outlets/Edit', [
            'property' => $property->only(['id', 'name']),
            'outlets' => $property->outlets()->get()->map(fn (Outlet $outlet): array => $this->outletPayload($outlet)),
            'types' => array_map(fn (OutletType $type): string => $type->value, OutletType::cases()),
            'printerSettings' => $this->options(Outlet::PRINTER_SETTINGS),
            'activityLogs' => self::logs($property),
        ]);
    }

    public function store(Request $request, Property $property): RedirectResponse
    {
        $data = $this->validateOutlet($request);
        $outlet = $property->outlets()->create([
            ...$data,
            'display_order' => $data['display_order'] ?? ((int) $property->outlets()->max('display_order') + 1),
        ]);

        self::log($request, $property, "Outlet {$outlet->name} added");

        return redirect()->route('admin.outlets.show', [$property, $outlet])->with('success', "Outlet {$outlet->name} added.");
    }

    public function update(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $outlet->update($this->validateOutlet($request));
        self::log($request, $property, "Outlet {$outlet->name} updated");

        return back()->with('success', "Outlet {$outlet->name} updated.");
    }

    public function destroy(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $outlet->delete();
        self::log($request, $property, "Outlet {$outlet->name} deleted");

        return redirect()->route('admin.outlets.edit', $property)->with('success', "Outlet {$outlet->name} deleted.");
    }

    /**
     * The QR code (SVG) of the outlet's public menu, for one table when ?table= is given.
     */
    public function qr(Request $request, Property $property, Outlet $outlet): HttpResponse
    {
        $table = $request->validate(['table' => ['nullable', 'string', 'max:20']])['table'] ?? null;
        $url = route('outlet-menu.show', ['menuToken' => $outlet->menuToken(), ...($table ? ['table' => $table] : [])]);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))->writeString($url);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="'.Str::slug("{$outlet->name} menu".($table ? " table {$table}" : '')).'.svg"',
            'X-Menu-Url' => $url,
        ]);
    }

    /**
     * A new menu token: QR codes printed before stop working.
     */
    public function renewQr(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $outlet->menuToken(renew: true);
        self::log($request, $property, "Outlet {$outlet->name} menu QR code renewed");

        return back()->with('success', "{$outlet->name}'s menu QR code was renewed. Print the new one; the old one no longer works.");
    }

    /**
     * One outlet: Display Categories / Catalogue Items / Price Plans tabs.
     */
    public function show(Property $property, Outlet $outlet): Response
    {
        $outlet->load(['displayCategories', 'items', 'pricePlans.prices', 'taxRates']);
        $taxCategories = $this->taxCategories($property);

        return Inertia::render('admin/outlets/Show', [
            'property' => $property->only(['id', 'name']),
            'outlet' => $this->outletPayload($outlet),
            'displayCategories' => $outlet->displayCategories->map(fn (OutletDisplayCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'display_order' => $category->display_order,
                'items_count' => $outlet->items->where('outlet_display_category_id', $category->id)->count(),
            ]),
            'items' => $outlet->items->map(fn (OutletItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'outlet_display_category_id' => $item->outlet_display_category_id,
                'display_category' => $outlet->displayCategories->firstWhere('id', $item->outlet_display_category_id)?->name,
                'tax_category_id' => $item->tax_category_id,
                'tax_category' => collect($taxCategories)->firstWhere('id', $item->tax_category_id)['name'] ?? null,
                'outlet_tax_rate_id' => $item->outlet_tax_rate_id,
                'tax_rate' => $outlet->taxRates->firstWhere('id', $item->outlet_tax_rate_id)?->name,
                'sac_hsn_code' => $item->sac_hsn_code,
                'item_type' => $item->item_type,
                'status' => $item->status,
                'display_order' => $item->display_order,
            ]),
            'pricePlans' => $outlet->pricePlans->map(fn (OutletPricePlan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'start_date' => $plan->start_date->toDateString(),
                'end_date' => $plan->end_date->toDateString(),
                'status' => $plan->status,
                // Item id => price.
                'prices' => $plan->prices->mapWithKeys(fn ($price): array => [$price->outlet_item_id => (float) $price->price]),
            ]),
            'taxRates' => $outlet->taxRates->map(fn (OutletTaxRate $taxRate): array => [
                'id' => $taxRate->id,
                'name' => $taxRate->name,
                'rate' => (float) $taxRate->rate,
                'status' => $taxRate->status,
                'items_count' => $outlet->items->where('outlet_tax_rate_id', $taxRate->id)->count(),
            ]),
            'taxCategories' => $taxCategories,
            'itemTypes' => $this->options(OutletItem::ITEM_TYPES),
            'activityLogs' => self::logs($property, $outlet),
            // Tables tab: the floor plan and each table's ordering QR code.
            'tables' => $outlet->tables()->get()->map(fn (OutletTable $table): array => [
                ...$table->only(['id', 'name', 'seats', 'area', 'pos_x', 'pos_y', 'shape', 'status']),
                'order_url' => route('table-order.show', $table->qrToken()),
            ])->values(),
        ]);
    }

    /**
     * Logs an outlet change for the property's Activity Logs.
     */
    public static function log(Request $request, Property $property, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'action' => self::LOG_PREFIX.': '.$action,
            'date' => now(),
        ]);
    }

    /**
     * @return list<array{action: string, user: string|null, at: string|null}>
     */
    public static function logs(Property $property, ?Outlet $outlet = null): array
    {
        return ActivityLog::query()
            ->where('property_id', $property->id)
            ->whereLike('action', self::LOG_PREFIX.': '.($outlet ? "%{$outlet->name}%" : '%'))
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'action' => substr($log->action, strlen(self::LOG_PREFIX) + 2),
                'user' => $log->user?->name,
                'at' => $log->date?->toIso8601ZuluString(),
            ])
            ->all();
    }

    /**
     * The property's Tax Settings → Tax Categories (still in use).
     *
     * @return list<array{id: int, name: string}>
     */
    private function taxCategories(Property $property): array
    {
        $data = app(PropertySettings::class)->json($property, 'tax_settings_tax_category');

        return collect($data['categories'] ?? [])
            ->reject(fn (array $category): bool => (bool) ($category['deleted'] ?? false))
            ->map(fn (array $category): array => ['id' => (int) $category['id'], 'name' => (string) $category['name']])
            ->values()
            ->all();
    }

    /**
     * Network printers are a host name or IP address and a raw-print port (9100–9109).
     *
     * @return array{name: string, type: string, printer_setting: string, printer_host?: string|null, printer_port?: int|null, cash_drawer?: bool, kot_printer_setting?: string, kot_printer_host?: string|null, kot_printer_port?: int|null, display_order?: int|null, status: bool}
     */
    private function validateOutlet(Request $request): array
    {
        $host = ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:]+$/'];
        $port = ['nullable', 'integer', 'between:'.PosPrints::PRINTER_PORTS[0].','.PosPrints::PRINTER_PORTS[1]];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(OutletType::class)],
            'printer_setting' => ['required', Rule::in(array_keys(Outlet::PRINTER_SETTINGS))],
            'printer_host' => $host,
            'printer_port' => $port,
            'cash_drawer' => ['boolean'],
            'kot_printer_setting' => ['sometimes', 'required', Rule::in(array_keys(Outlet::PRINTER_SETTINGS))],
            'kot_printer_host' => $host,
            'kot_printer_port' => $port,
            'display_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['boolean'],
        ], [
            'printer_host.regex' => 'Enter the printer\'s host name or IP address.',
            'kot_printer_host.regex' => 'Enter the printer\'s host name or IP address.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outletPayload(Outlet $outlet): array
    {
        return [
            'id' => $outlet->id,
            'name' => $outlet->name,
            'type' => $outlet->type->value,
            'printer_setting' => $outlet->printer_setting,
            'printer_setting_label' => Outlet::PRINTER_SETTINGS[$outlet->printer_setting] ?? $outlet->printer_setting,
            'printer_host' => $outlet->printer_host,
            'printer_port' => $outlet->printer_port,
            'cash_drawer' => $outlet->cash_drawer,
            'qr_ordering' => $outlet->qr_ordering,
            'menu_url' => route('outlet-menu.show', $outlet->menuToken()),
            'kot_printer_setting' => $outlet->kot_printer_setting,
            'kot_printer_host' => $outlet->kot_printer_host,
            'kot_printer_port' => $outlet->kot_printer_port,
            'display_order' => $outlet->display_order,
            'status' => $outlet->status,
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }
}
