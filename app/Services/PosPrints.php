<?php

namespace App\Services;

use App\Enums\OutletBillStatus;
use App\Enums\PaymentMode;
use App\Models\Currency;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletBillItem;
use App\Models\OutletKot;
use App\Models\OutletKotItem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * POS printing: a bill or kitchen ticket as print data, as an HTML page sized for its paper
 * (A4, 80mm or 58mm thermal; printed from the browser), or as raw ESC/POS bytes, which go to the
 * outlet's network printer or are downloaded for a print agent.
 */
class PosPrints
{
    /** @var list<string> */
    public const PAPERS = ['a4', 'thermal_80', 'thermal_58'];

    /** Raw-print ports a network receipt printer listens on (JetDirect 9100 and its siblings). */
    public const PRINTER_PORTS = [9100, 9109];

    public function __construct(private BookingPrints $bookingPrints) {}

    /**
     * @return array<string, mixed>
     */
    public function billData(OutletBill $bill): array
    {
        $bill->loadMissing(['outlet', 'property.currency', 'items', 'booking', 'subBooking.inventoryUnit', 'payment', 'creator']);
        $property = $bill->property;
        $discountRatio = (float) $bill->sub_total > 0 ? ((float) $bill->sub_total - (float) $bill->discount) / (float) $bill->sub_total : 0.0;
        $payment = $bill->payment;
        $settlement = [];

        if ($bill->status === OutletBillStatus::Posted) {
            $room = $bill->subBooking?->inventoryUnit->name ?? 'room';
            $settlement[] = ['label' => "Charged to {$room}".($bill->booking ? " ({$bill->booking->booking_ref_id})" : ''), 'amount' => (float) $bill->total - (float) ($payment->total_txn_amount ?? 0)];
        }

        if ($payment !== null) {
            $settlement[] = ['label' => 'Paid by '.($payment->payment_mode->value ?? 'Others').($payment->receipt_number ? " (Receipt {$payment->receipt_number})" : ''), 'amount' => (float) $payment->total_txn_amount];
        }

        return [
            'property' => $this->header($bill->outlet),
            'outlet' => $bill->outlet->name,
            'title' => match ($bill->status) {
                OutletBillStatus::Open => 'Pro-forma Bill',
                OutletBillStatus::Void => 'Void Bill',
                default => 'Bill',
            },
            'is_void' => $bill->status === OutletBillStatus::Void,
            'bill_number' => $bill->bill_number,
            'date' => $property->localTime($bill->closed_at ?? $bill->created_at)->format('d M Y H:i'),
            'business_date' => $bill->business_date->format('d M Y'),
            'table_number' => $bill->table_number,
            'guest_name' => $bill->guest_name,
            'cashier' => $bill->creator?->name,
            'currency' => Currency::forProperty($property)['code'],
            'lines' => $bill->items->map(fn (OutletBillItem $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'amount' => (float) $line->amount,
            ])->values()->all(),
            'sub_total' => (float) $bill->sub_total,
            'discount' => (float) $bill->discount,
            'taxes' => $this->taxes($bill, $discountRatio),
            'tax_total' => (float) $bill->tax_total,
            'total' => (float) $bill->total,
            'settlement' => $settlement,
            'notes' => $bill->notes,
            'void_reason' => $bill->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function kotData(OutletKot $kot): array
    {
        $kot->loadMissing(['outlet.property', 'bill.subBooking.inventoryUnit', 'items', 'creator']);
        $bill = $kot->bill;

        return [
            'outlet' => $kot->outlet->name,
            'kot_number' => $kot->kot_number,
            'bill_number' => $bill->bill_number,
            'is_cancellation' => $kot->isCancellation(),
            'date' => $kot->outlet->property->localTime($kot->created_at)->format('d M Y H:i'),
            'table_number' => $bill->table_number,
            'guest_name' => $bill->guest_name,
            'room' => $bill->subBooking?->inventoryUnit?->name,
            'waiter' => $kot->creator?->name,
            'lines' => $kot->items->map(fn (OutletKotItem $line): array => ['name' => $line->name, 'quantity' => $line->quantity])->values()->all(),
            'notes' => $kot->notes,
        ];
    }

    /**
     * The paper to print on: the one asked for, else the outlet's setting.
     */
    public function paper(?string $requested, string $outletSetting): string
    {
        return in_array($requested, self::PAPERS, true) ? $requested : (in_array($outletSetting, self::PAPERS, true) ? $outletSetting : 'a4');
    }

    /**
     * The bill as an A4 PDF (download and e-mail).
     */
    public function billPdf(OutletBill $bill): PdfBuilder
    {
        $bill->loadMissing('outlet');

        return pdf()
            ->view('prints.pos.bill', ['data' => $this->billData($bill), 'paper' => 'a4', 'autoPrint' => false, 'pdf' => true])
            ->format(Format::A4)
            ->margins(10, 10, 10, 10, 'mm')
            ->name(Str::slug("{$bill->outlet->name} bill {$bill->bill_number}").'.pdf');
    }

    /**
     * Whether printing the bill's receipt should open the cash drawer: the outlet has one and
     * the bill was paid in cash.
     */
    public function opensDrawer(OutletBill $bill): bool
    {
        $bill->loadMissing(['outlet', 'payment']);

        return $bill->outlet->cash_drawer
            && $bill->status !== OutletBillStatus::Void
            && $bill->payment?->payment_mode === PaymentMode::Cash;
    }

    /**
     * Just the drawer kick, to open the cash drawer without a sale.
     */
    public function drawerKick(): string
    {
        return (new EscPos(48))->openDrawer()->bytes();
    }

    public function billEscPos(OutletBill $bill, string $paper, bool $openDrawer = false): string
    {
        $data = $this->billData($bill);
        $printer = new EscPos(Outlet::receiptColumns($paper) ?? 48);
        $wide = $printer->columns >= 48;
        $money = fn (float $amount): string => number_format($amount, 2);

        $printer->align('center')->bold()->text($data['property']['name'])->bold(false);

        if ($data['property']['address'] !== '') {
            $printer->text($data['property']['address']);
        }

        foreach ($data['property']['statutory_ids'] as $statutoryId) {
            $printer->text("{$statutoryId['label']}: {$statutoryId['value']}");
        }

        $printer->bold()->text($data['outlet'])->text(strtoupper($data['title']))->bold(false);

        if ($data['is_void']) {
            $printer->large()->text('*** VOID ***', (int) ($printer->columns / 2))->large(false);
        }

        $printer->align('left')->rule()
            ->pair("Bill #{$data['bill_number']}", $data['date']);

        if ($data['table_number']) {
            $printer->text("Table: {$data['table_number']}");
        }

        if ($data['guest_name']) {
            $printer->text("Guest: {$data['guest_name']}");
        }

        $widths = $wide ? [5, 10, 11] : [4, 10];
        $printer->rule()->bold()->row($wide ? ['Item', 'Qty', 'Rate', 'Amount'] : ['Item', 'Qty', 'Amount'], $widths)->bold(false);

        foreach ($data['lines'] as $line) {
            $printer->row($wide
                ? [$line['name'], (string) $line['quantity'], $money($line['unit_price']), $money($line['amount'])]
                : [$line['name'], (string) $line['quantity'], $money($line['amount'])], $widths);
        }

        $printer->rule()->pair('Sub Total', $money($data['sub_total']));

        if ($data['discount'] > 0) {
            $printer->pair('Discount', '-'.$money($data['discount']));
        }

        foreach ($data['taxes'] as $tax) {
            $printer->pair($tax['label'], $money($tax['amount']));
        }

        $printer->bold()->pair("Total ({$data['currency']})", $money($data['total']))->bold(false);

        if ($data['settlement'] !== []) {
            $printer->rule();

            foreach ($data['settlement'] as $settlement) {
                $printer->pair($settlement['label'], $money($settlement['amount']));
            }
        }

        if ($data['void_reason']) {
            $printer->text("Void: {$data['void_reason']}");
        }

        if ($data['notes']) {
            $printer->text($data['notes']);
        }

        if ($data['cashier']) {
            $printer->text("Cashier: {$data['cashier']}");
        }

        $printer->align('center')->feed()->text('Thank you!')->feed(3);

        if ($openDrawer && $this->opensDrawer($bill)) {
            $printer->openDrawer();
        }

        return $printer->cut()->bytes();
    }

    public function kotEscPos(OutletKot $kot, string $paper): string
    {
        $data = $this->kotData($kot);
        $printer = new EscPos(Outlet::receiptColumns($paper) ?? 48);
        $half = (int) ($printer->columns / 2);

        $printer->align('center')->bold()->text($data['outlet'])
            ->large()->text(($data['is_cancellation'] ? 'CANCEL ' : '')."KOT #{$data['kot_number']}", $half)->large(false)->bold(false)
            ->align('left')->rule()
            ->pair("Bill #{$data['bill_number']}", $data['date']);

        if ($data['table_number']) {
            $printer->bold()->text("Table: {$data['table_number']}")->bold(false);
        }

        if ($data['room']) {
            $printer->text("Room: {$data['room']}");
        }

        if ($data['guest_name']) {
            $printer->text("Guest: {$data['guest_name']}");
        }

        $printer->rule()->large();

        foreach ($data['lines'] as $line) {
            $printer->text(($line['quantity'] < 0 ? 'CANCEL ' : '').abs($line['quantity'])." x {$line['name']}", $half);
        }

        $printer->large(false)->rule();

        if ($data['notes']) {
            $printer->bold()->text($data['notes'])->bold(false);
        }

        if ($data['waiter']) {
            $printer->text("By: {$data['waiter']}");
        }

        return $printer->feed(3)->cut()->bytes();
    }

    /**
     * Sends raw ESC/POS bytes to a network printer. Only the raw-print ports are allowed and
     * never this server's own or link-local addresses, so a printer setting can't reach other
     * services.
     *
     * @throws ValidationException when the printer can't be reached
     */
    public function send(string $host, int $port, string $bytes): void
    {
        $address = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) || $port < self::PRINTER_PORTS[0] || $port > self::PRINTER_PORTS[1]) {
            throw ValidationException::withMessages(['printer' => "The printer address {$host}:{$port} isn't allowed."]);
        }

        $target = str_contains($address, ':') ? "[{$address}]" : $address;
        $socket = @stream_socket_client("tcp://{$target}:{$port}", $errorCode, $errorMessage, 5);

        if ($socket === false) {
            throw ValidationException::withMessages(['printer' => "Couldn't reach the printer at {$host}:{$port}".($errorMessage ? " ({$errorMessage})" : '').'.']);
        }

        stream_set_timeout($socket, 5);
        $written = @fwrite($socket, $bytes);
        fclose($socket);

        if ($written !== strlen($bytes)) {
            throw ValidationException::withMessages(['printer' => "The printer at {$host}:{$port} stopped taking the print."]);
        }
    }

    /**
     * @return array{name: string, address: string, statutory_ids: list<array{label: string, value: string}>}
     */
    private function header(Outlet $outlet): array
    {
        $property = $outlet->property;

        return [
            'name' => $property->name,
            'address' => $this->bookingPrints->propertyAddress($property, 'permanent'),
            'statutory_ids' => array_values(collect($this->bookingPrints->invoiceSettings($property)['statutory_ids'])
                ->filter(fn (array $statutoryId): bool => filled($statutoryId['display_name'] ?? null) && filled($statutoryId['display_value'] ?? null))
                ->map(fn (array $statutoryId): array => ['label' => (string) $statutoryId['display_name'], 'value' => (string) $statutoryId['display_value']])
                ->all()),
        ];
    }

    /**
     * Tax by rate, after the discount; rounding lands on the largest so they add up to the bill's tax.
     *
     * @return list<array{label: string, amount: float}>
     */
    private function taxes(OutletBill $bill, float $discountRatio): array
    {
        $byRate = $bill->items
            ->filter(fn (OutletBillItem $line): bool => (float) $line->tax_amount > 0)
            ->groupBy(fn (OutletBillItem $line): string => rtrim(rtrim(number_format((float) $line->tax_rate, 2, '.', ''), '0'), '.'))
            ->map(fn ($lines): float => round($lines->sum(fn (OutletBillItem $line): float => (float) $line->tax_amount * $discountRatio), 2))
            ->sortKeys();

        if ($byRate->isEmpty()) {
            return [];
        }

        $largest = $byRate->sort()->keys()->last();
        $byRate[$largest] = round($byRate[$largest] + (float) $bill->tax_total - $byRate->sum(), 2);

        return array_values($byRate->map(fn (float $amount, string $rate): array => ['label' => "Tax @ {$rate}%", 'amount' => $amount])->all());
    }
}
