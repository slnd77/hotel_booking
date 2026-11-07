<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\OutletBillStatus;
use App\Enums\PaymentMode;
use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletKot;
use App\Models\OutletTable;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Services\OutletBills;
use App\Services\PermissionGate;
use App\Services\PosPrints;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The POS bill actions shared by the admin panel and the API: the same validation, the same
 * POS permissions (discount, price override, reducing what the kitchen has, cancel / undo push
 * to room / undo settle), App\Services\OutletBills to do the work and App\Services\PosPrints to
 * print bills and kitchen tickets.
 */
trait HandlesOutletBills
{
    protected function openBill(Request $request, Outlet $outlet): OutletBill
    {
        abort_unless($outlet->status, 422, "{$outlet->name} is switched off.");

        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:255'],
            'table_number' => ['nullable', 'string', 'max:20'],
            // A table of the outlet's plan.
            'outlet_table_id' => ['nullable', 'integer', Rule::exists(OutletTable::class, 'id')->where('outlet_id', $outlet->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(required: false),
        ]);

        $bills = app(OutletBills::class);
        $bill = $bills->open($outlet, $request->user(), $data);

        if (! empty($data['items'])) {
            $bill = $this->applyItems($request, $bill, $data);
        }

        return $bill;
    }

    protected function updateBill(Request $request, OutletBill $bill): OutletBill
    {
        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:255'],
            'table_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(required: true),
        ]);

        if ($bill->status === OutletBillStatus::Open) {
            $bill->update(['guest_name' => $data['guest_name'] ?? null, 'table_number' => $data['table_number'] ?? null, 'notes' => $data['notes'] ?? null]);
        }

        return $this->applyItems($request, $bill, $data);
    }

    protected function postBillToRoom(Request $request, Property $property, OutletBill $bill): OutletBill
    {
        $data = $request->validate([
            'sub_booking_id' => ['required', 'integer', Rule::exists(SubBooking::class, 'id')],
            'sub_folio_id' => ['nullable', 'integer', Rule::exists(SubFolio::class, 'id')],
            'payment' => ['nullable', 'array'],
            'payment.payment_mode' => ['required_with:payment', 'string', Rule::in(array_column(PaymentMode::cases(), 'value'))],
            'payment.amount' => ['required_with:payment', 'numeric', 'min:0.01'],
            'payment.reference' => ['nullable', 'string', 'max:255'],
        ]);

        $room = SubBooking::query()->with('booking')->findOrFail($data['sub_booking_id']);
        abort_unless($room->booking?->property_id === $property->id, 404);

        $payment = isset($data['payment']) ? [
            'payment_mode' => PaymentMode::from($data['payment']['payment_mode']),
            'amount' => (float) $data['payment']['amount'],
            'reference' => $data['payment']['reference'] ?? null,
        ] : null;

        return app(OutletBills::class)->postToRoom(
            $bill,
            $room,
            isset($data['sub_folio_id']) ? SubFolio::query()->with('folio')->findOrFail($data['sub_folio_id']) : null,
            $payment,
            $request->user(),
        );
    }

    /**
     * Settles with one payment mode, or with several payments (split between payers or modes)
     * that add up to the bill.
     */
    protected function settleBill(Request $request, OutletBill $bill): OutletBill
    {
        $modes = Rule::in(array_column(PaymentMode::cases(), 'value'));
        $data = $request->validate([
            'payment_mode' => ['required_without:payments', 'nullable', 'string', $modes],
            'reference' => ['nullable', 'string', 'max:255'],
            'payments' => ['nullable', 'array', 'min:1', 'max:20'],
            'payments.*.payment_mode' => ['required', 'string', $modes],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.payer' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($data['payments'])) {
            return app(OutletBills::class)->settleSplit($bill, array_map(fn (array $payment): array => [
                'payment_mode' => PaymentMode::from($payment['payment_mode']),
                'amount' => (float) $payment['amount'],
                'reference' => $payment['reference'] ?? null,
                'payer' => $payment['payer'] ?? null,
            ], array_values($data['payments'])), $request->user());
        }

        return app(OutletBills::class)->settle($bill, PaymentMode::from($data['payment_mode']), $data['reference'] ?? null, $request->user());
    }

    /**
     * Splits an open bill: the quantities given per line move to a new bill.
     */
    protected function splitBill(Request $request, OutletBill $bill): OutletBill
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        return app(OutletBills::class)->split($bill, collect($data['items'])->mapWithKeys(fn (array $line): array => [(int) $line['id'] => (int) $line['quantity']])->all(), $request->user());
    }

    /**
     * Cancelling an open bill, undoing a push to room and undoing a settlement are separate
     * POS permissions. An open bill's kitchen tickets are cancelled on a new ticket, printed on
     * the kitchen printer when the outlet has one.
     */
    protected function voidBill(Request $request, OutletBill $bill): OutletBill
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        app(PermissionGate::class)->require($request->user(), match ($bill->status) {
            OutletBillStatus::Posted => 'pos.undo_push_to_room',
            OutletBillStatus::Settled => 'pos.undo_settle',
            default => 'pos.cancel_order',
        });

        $lastKotId = $bill->kots()->max('id');
        $bill = app(OutletBills::class)->void($bill, $data['reason']);
        $cancellation = $bill->kots()->where('id', '>', (int) $lastKotId)->first();

        if ($cancellation !== null) {
            $this->printOnKitchenPrinter($cancellation);
        }

        return $bill;
    }

    /**
     * Sends the bill's changes to the kitchen on a new ticket, printed straight away when the
     * outlet has a network kitchen printer.
     *
     * @return array{kot: OutletKot, printed: bool, print_error: string|null}
     */
    protected function sendBillToKitchen(Request $request, OutletBill $bill): array
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $kot = app(OutletBills::class)->sendKot($bill, $request->user(), $data['notes'] ?? null);

        return ['kot' => $kot, ...$this->printOnKitchenPrinter($kot)];
    }

    /**
     * A bill as a print page for its paper (format=html), or as ESC/POS bytes (format=escpos).
     */
    protected function billPrintout(Request $request, OutletBill $bill): Response
    {
        $prints = app(PosPrints::class);
        [$paper, $format] = $this->printOptions($request, $bill->outlet->printer_setting);

        if ($format === 'escpos') {
            return $this->escPosDownload($prints->billEscPos($bill, $paper, $request->boolean('drawer')), "bill-{$bill->bill_number}.bin");
        }

        return response()->view('prints.pos.bill', ['data' => $prints->billData($bill), 'paper' => $paper, 'autoPrint' => $request->boolean('autoprint')]);
    }

    /**
     * A kitchen ticket as a print page or as ESC/POS bytes; printing it marks it printed.
     */
    protected function kotPrintout(Request $request, OutletKot $kot): Response
    {
        $prints = app(PosPrints::class);
        [$paper, $format] = $this->printOptions($request, $kot->outlet->kot_printer_setting);
        $kot->update(['printed_at' => $kot->printed_at ?? now()]);

        if ($format === 'escpos') {
            return $this->escPosDownload($prints->kotEscPos($kot, $paper), "kot-{$kot->kot_number}.bin");
        }

        return response()->view('prints.pos.kot', ['data' => $prints->kotData($kot), 'paper' => $paper, 'autoPrint' => $request->boolean('autoprint')]);
    }

    /**
     * Prints the bill on the outlet's network printer.
     *
     * @throws ValidationException when the outlet has none or it can't be reached
     */
    protected function printBillOnPrinter(Request $request, OutletBill $bill): void
    {
        $outlet = $bill->outlet;
        $prints = app(PosPrints::class);

        if (! $outlet->printer_host) {
            throw ValidationException::withMessages(['printer' => "{$outlet->name} has no network printer. Add one under Settings → Outlets."]);
        }

        $paper = $request->validate(['paper' => ['nullable', Rule::in(PosPrints::PAPERS)]])['paper'] ?? null;
        $prints->send($outlet->printer_host, $outlet->printer_port ?? 9100, $prints->billEscPos($bill, $prints->paper($paper, $outlet->printer_setting), $request->boolean('drawer')));
    }

    /**
     * A settled bill's receipt prints on the outlet's network printer when it has one, opening
     * the cash drawer for a cash payment. The sale stands if the printer can't be reached.
     *
     * @return string|null why it didn't print
     */
    protected function printReceiptAfterSettle(OutletBill $bill): ?string
    {
        $outlet = $bill->outlet;

        if (! $outlet->printer_host) {
            return null;
        }

        $prints = app(PosPrints::class);

        try {
            $prints->send($outlet->printer_host, $outlet->printer_port ?? 9100, $prints->billEscPos($bill, $prints->paper(null, $outlet->printer_setting), openDrawer: true));
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * Opens the outlet's cash drawer without a sale (logged): on the network printer, or as the
     * ESC/POS drawer kick (format=escpos) for a printer connected to the browser.
     *
     * @throws ValidationException when the outlet has no drawer, or no printer to open it with
     */
    protected function openCashDrawer(Request $request, Outlet $outlet): ?Response
    {
        $prints = app(PosPrints::class);
        $direct = $request->validate(['format' => ['nullable', Rule::in(['escpos'])]])['format'] ?? null;

        if (! $outlet->cash_drawer) {
            throw ValidationException::withMessages(['printer' => "{$outlet->name} has no cash drawer. Tick it under Settings → Outlets."]);
        }

        if ($direct === null) {
            if (! $outlet->printer_host) {
                throw ValidationException::withMessages(['printer' => "{$outlet->name} has no network printer to open the drawer with."]);
            }

            $prints->send($outlet->printer_host, $outlet->printer_port ?? 9100, $prints->drawerKick());
        }

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'property_id' => $outlet->property_id,
            'action' => "POS: cash drawer of {$outlet->name} opened without a sale",
            'date' => now(),
        ]);

        return $direct ? $this->escPosDownload($prints->drawerKick(), 'drawer.bin') : null;
    }

    /**
     * Prints the ticket on the outlet's network kitchen printer.
     *
     * @throws ValidationException when the outlet has none or it can't be reached
     */
    protected function printKotOnPrinter(OutletKot $kot): void
    {
        if (! $kot->outlet->kot_printer_host) {
            throw ValidationException::withMessages(['printer' => "{$kot->outlet->name} has no kitchen printer. Add one under Settings → Outlets."]);
        }

        $result = $this->printOnKitchenPrinter($kot);

        if ($result['print_error'] !== null) {
            throw ValidationException::withMessages(['printer' => $result['print_error']]);
        }
    }

    /**
     * Prints the ticket on the kitchen printer when the outlet has one. A printer that can't be
     * reached doesn't undo the ticket; it's reported so it can be printed from the browser.
     *
     * @return array{printed: bool, print_error: string|null}
     */
    private function printOnKitchenPrinter(OutletKot $kot): array
    {
        $outlet = $kot->outlet;

        if (! $outlet->kot_printer_host) {
            return ['printed' => false, 'print_error' => null];
        }

        $prints = app(PosPrints::class);

        try {
            $prints->send($outlet->kot_printer_host, $outlet->kot_printer_port ?? 9100, $prints->kotEscPos($kot, $prints->paper(null, $outlet->kot_printer_setting)));
        } catch (ValidationException $exception) {
            return ['printed' => false, 'print_error' => collect($exception->errors())->flatten()->first()];
        }

        $kot->update(['printed_at' => now()]);

        return ['printed' => true, 'print_error' => null];
    }

    /**
     * @return array{0: string, 1: string} the paper and the format (html or escpos)
     */
    private function printOptions(Request $request, string $outletSetting): array
    {
        $data = $request->validate([
            'paper' => ['nullable', Rule::in(PosPrints::PAPERS)],
            'format' => ['nullable', Rule::in(['html', 'escpos'])],
        ]);

        return [app(PosPrints::class)->paper($data['paper'] ?? null, $outletSetting), $data['format'] ?? 'html'];
    }

    private function escPosDownload(string $bytes, string $fileName): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function itemRules(bool $required): array
    {
        return [
            'items' => [$required ? 'present' : 'nullable', 'array', 'max:200'],
            'items.*.outlet_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyItems(Request $request, OutletBill $bill, array $data): OutletBill
    {
        $gate = app(PermissionGate::class);
        $discount = (float) ($data['discount'] ?? 0);
        $overridesPrice = collect($data['items'] ?? [])->contains(fn (array $line): bool => isset($line['unit_price']));

        if ($discount > 0) {
            $gate->require($request->user(), 'pos.apply_discount', "You don't have permission to give a discount.");
        }

        if ($overridesPrice) {
            $gate->require($request->user(), 'pos.override_item_price', "You don't have permission to change an item's price.");
        }

        if ($this->reducesSentItems($bill, $data['items'] ?? [])) {
            $gate->require($request->user(), 'pos.reduce_item_quantity', "These items are already with the kitchen; you don't have permission to reduce them.");
        }

        return app(OutletBills::class)->setItems($bill, array_values($data['items'] ?? []), $discount, $overridesPrice);
    }

    /**
     * Whether the new lines have fewer of an item than the kitchen tickets already sent.
     *
     * @param  list<array{outlet_item_id: int|string, quantity: int|string}>  $lines
     */
    private function reducesSentItems(OutletBill $bill, array $lines): bool
    {
        $sent = app(OutletBills::class)->sentToKitchen($bill);

        if ($sent->isEmpty()) {
            return false;
        }

        $wanted = collect($lines)->groupBy(fn (array $line): int => (int) $line['outlet_item_id'])->map(fn ($group): int => (int) $group->sum('quantity'));

        return $sent->contains(fn (array $line): bool => $line['outlet_item_id'] !== null && $wanted->get($line['outlet_item_id'], 0) < $line['quantity']);
    }
}
