<?php

namespace App\Services;

use App\Http\Controllers\Concerns\CalculatesRoomTaxes;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Settings → Privacy & Terms → GST invoice check: whether the property's set-up issues tax
 * invoices GST rule 46 accepts: its own GSTIN (matching its state), legal name and place of
 * supply, CGST + SGST at a GST slab, and invoice / credit note numbers that are consecutive
 * serials of at most 16 characters. Each check passes, warns or fails with what to fix.
 */
class GstCompliance
{
    use CalculatesRoomTaxes;

    /** GSTIN format: 2-digit state code, PAN, entity number, Z, check character. */
    private const GSTIN_PATTERN = '/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/';

    /** GST slab rates (combined) a room tax may add up to. */
    private const SLABS = [0, 5, 12, 18, 28];

    /** Rule 46(b): an invoice number has at most 16 characters. */
    private const MAX_NUMBER_LENGTH = 16;

    public function __construct(private BookingPrints $prints) {}

    /**
     * @return list<array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}>
     */
    public function check(Property $property): array
    {
        $gstin = $this->gstin($property);
        $components = $this->activeRoomTaxComponents($property);

        return [
            $this->gstinCheck($gstin),
            $this->stateCheck($property, $gstin),
            $this->legalNameCheck($property),
            $this->taxCheck($components),
            $this->numberLengthCheck($property),
            $this->serialCheck($property),
            ['key' => 'sac', 'label' => 'SAC code on invoices', 'status' => 'pass', 'detail' => 'Room invoices print SAC '.Gstr1SalesRegisterReport::ACCOMMODATION_SAC.' (room or unit accommodation services).'],
        ];
    }

    /**
     * The property's GSTIN from Invoice Settings → Statutory IDs (the entry named GST…).
     */
    public function gstin(Property $property): ?string
    {
        $entry = collect($this->prints->invoiceSettings($property)['statutory_ids'])
            ->first(fn (array $id): bool => str_contains(strtoupper((string) ($id['display_name'] ?? '')), 'GST'));

        $value = strtoupper(trim((string) ($entry['display_value'] ?? '')));

        return $value === '' ? null : $value;
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function gstinCheck(?string $gstin): array
    {
        return match (true) {
            $gstin === null => ['key' => 'gstin', 'label' => 'Property GSTIN', 'status' => 'fail', 'detail' => 'Add the GSTIN under Invoice Settings → Statutory IDs (display name "GSTIN"); a tax invoice must show it.'],
            preg_match(self::GSTIN_PATTERN, $gstin) !== 1 => ['key' => 'gstin', 'label' => 'Property GSTIN', 'status' => 'fail', 'detail' => "\"{$gstin}\" is not a valid GSTIN (15 characters: state code, PAN, entity number, Z, check character)."],
            default => ['key' => 'gstin', 'label' => 'Property GSTIN', 'status' => 'pass', 'detail' => "{$gstin} prints on every invoice."],
        };
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function stateCheck(Property $property, ?string $gstin): array
    {
        $state = $property->state?->name;
        $code = $state === null ? null : collect(Gstr1SalesRegisterReport::STATE_CODES)->first(fn (string $code, string $name): bool => strcasecmp($name, $state) === 0);

        return match (true) {
            $state === null => ['key' => 'state', 'label' => 'Place of supply', 'status' => 'fail', 'detail' => 'Set the property\'s state (Property settings): it is the place of supply on every room invoice.'],
            $code === null => ['key' => 'state', 'label' => 'Place of supply', 'status' => 'warn', 'detail' => "\"{$state}\" isn't an Indian state or union territory with a GST state code."],
            $gstin !== null && ! str_starts_with($gstin, $code) => ['key' => 'state', 'label' => 'Place of supply', 'status' => 'fail', 'detail' => 'The GSTIN starts with state code '.substr($gstin, 0, 2).", but the property is in {$state} ({$code})."],
            default => ['key' => 'state', 'label' => 'Place of supply', 'status' => 'pass', 'detail' => "{$code}-{$state}"],
        };
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function legalNameCheck(Property $property): array
    {
        return filled($property->property_legal_name)
            ? ['key' => 'legal_name', 'label' => 'Legal name', 'status' => 'pass', 'detail' => (string) $property->property_legal_name]
            : ['key' => 'legal_name', 'label' => 'Legal name', 'status' => 'warn', 'detail' => 'Set the legal name the GSTIN is registered to (Property settings); invoices show the trading name until then.'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function taxCheck(array $components): array
    {
        $rates = collect($components)->mapWithKeys(fn (array $component): array => [strtoupper((string) ($component['component_choice'] ?? '')) => $component]);
        $percent = fn (string $name): ?float => ($rates[$name]['tax_value_type'] ?? 'percentage') === 'percentage' && ! ($rates[$name]['define_tax_slab'] ?? false)
            ? (float) ($rates[$name]['tax_value'] ?? 0)
            : null;

        if ($rates->isEmpty()) {
            return ['key' => 'tax', 'label' => 'Room tax', 'status' => 'fail', 'detail' => 'No room tax rule is active (Tax settings): add CGST and SGST.'];
        }

        if (! $rates->has('CGST') || ! $rates->has('SGST')) {
            return ['key' => 'tax', 'label' => 'Room tax', 'status' => $rates->has('IGST') ? 'warn' : 'fail', 'detail' => 'Rooms are supplied where the property is, so room tax is CGST + SGST (not IGST or another tax).'];
        }

        if ($percent('CGST') === null || $percent('SGST') === null) {
            return ['key' => 'tax', 'label' => 'Room tax', 'status' => 'pass', 'detail' => 'CGST + SGST by tariff slab.'];
        }

        $combined = $percent('CGST') + $percent('SGST');

        return match (true) {
            abs($percent('CGST') - $percent('SGST')) >= 0.001 => ['key' => 'tax', 'label' => 'Room tax', 'status' => 'fail', 'detail' => 'CGST and SGST must be the same rate.'],
            ! in_array($combined, self::SLABS, false) => ['key' => 'tax', 'label' => 'Room tax', 'status' => 'fail', 'detail' => "CGST + SGST add up to {$combined}%, which isn't a GST slab."],
            default => ['key' => 'tax', 'label' => 'Room tax', 'status' => 'pass', 'detail' => "CGST {$percent('CGST')}% + SGST {$percent('SGST')}% = {$combined}%."],
        };
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function numberLengthCheck(Property $property): array
    {
        $nextInvoice = Invoice::nextInvoiceNumber($property);
        $lastNote = CreditNote::query()->where('property_id', $property->id)->latest('id')->value('credit_note_number');
        $tooLong = array_filter([$nextInvoice, $lastNote], fn (?string $number): bool => $number !== null && mb_strlen($number) > self::MAX_NUMBER_LENGTH);

        return $tooLong === []
            ? ['key' => 'number_length', 'label' => 'Number length', 'status' => 'pass', 'detail' => "The next invoice is {$nextInvoice} (".mb_strlen($nextInvoice).' characters, at most 16).']
            : ['key' => 'number_length', 'label' => 'Number length', 'status' => 'fail', 'detail' => implode(', ', $tooLong).' is longer than 16 characters: shorten the prefix in Invoice Settings → Invoice / Credit Note Tracker Number.'];
    }

    /**
     * This financial year's invoices: their serials must run on without gaps or repeats.
     *
     * @return array{key: string, label: string, status: 'pass'|'warn'|'fail', detail: string}
     */
    private function serialCheck(Property $property): array
    {
        $local = $property->localTime();
        $yearStart = CarbonImmutable::create($local->month >= 4 ? $local->year : $local->year - 1, 4, 1, 0, 0, 0, $property->timezoneName());

        $serials = Invoice::query()
            ->where('property_id', $property->id)
            ->where('invoice_generation_date', '>=', $yearStart->utc())
            ->pluck('invoice_number')
            ->groupBy(fn (string $number): string => (string) preg_replace('/\d+\D*$/', '', $number))
            ->map(fn ($numbers) => $numbers->map(fn (string $number): int => (int) (preg_match('/(\d+)\D*$/', $number, $match) ? $match[1] : 0))->sort()->values());

        $gaps = $serials->map(fn ($numbers): int => $numbers->isEmpty() ? 0 : ($numbers->last() - $numbers->first() + 1) - $numbers->unique()->count())->filter();

        return match (true) {
            $serials->isEmpty() => ['key' => 'serials', 'label' => 'Consecutive invoice numbers', 'status' => 'pass', 'detail' => 'No invoices yet this financial year (from '.$yearStart->format('d M Y').').'],
            $gaps->isNotEmpty() => ['key' => 'serials', 'label' => 'Consecutive invoice numbers', 'status' => 'warn', 'detail' => 'This financial year\'s series '.$gaps->keys()->map(fn (string $prefix): string => $prefix === '' ? '(no prefix)' : $prefix)->implode(', ').' skip '.$gaps->sum().' number(s) — usually invoices numbered before serial numbering; explain them in GSTR-1 table 13 (documents issued).'],
            default => ['key' => 'serials', 'label' => 'Consecutive invoice numbers', 'status' => 'pass', 'detail' => $serials->flatten()->count().' invoice(s) this financial year, numbered without gaps.'],
        };
    }
}
