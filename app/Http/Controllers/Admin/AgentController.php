<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Agent;
use App\Models\AgentRateCode;
use App\Models\Country;
use App\Models\Property;
use App\Services\ApplicationSettings;
use App\Services\CurrentProperty;
use App\Services\PermissionGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Databases → Agents: the agencies a property takes bookings from and their contacts,
 * with the Add/Update Agent form.
 */
class AgentController extends Controller
{
    /** @var array<string, string> */
    private const CSV_COLUMNS = [
        'agency_name' => 'Agency Name', 'agency_code' => 'Agency Code', 'agency_class' => 'Agency Class', 'source_label' => 'Source',
        'contact_name' => 'Agent/Contact', 'mobile' => 'Mobile', 'email' => 'E-mail', 'address' => 'Address', 'city' => 'City',
    ];

    public function index(Request $request): Response
    {
        $property = $this->selectedProperty($request);
        $filters = $this->validatedFilters($request);

        $agents = $property
            ? $this->filteredQuery($property, $filters)->paginate(15)->withQueryString()->through(fn (Agent $agent): array => $this->row($agent))
            : null;

        return Inertia::render('admin/databases/Agents', [
            'property' => $property?->only(['id', 'name', 'country_id']),
            'agents' => $agents,
            'filters' => $filters,
            'agencies' => $property ? $property->agencies()->orderBy('name')->get(['id', 'name', 'source', 'agent_rate_code_id', 'address', 'city']) : [],
            'rateCodes' => $property ? $property->agentRateCodes()->orderBy('name')->get(['id', 'name', 'is_active']) : [],
            'sources' => collect(Agency::SOURCES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $property = $this->selectedProperty($request);
        abort_if(! $property, 404);

        $agents = $this->filteredQuery($property, $this->validatedFilters($request))->get();

        return response()->streamDownload(function () use ($agents): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new RuntimeException('Unable to open the output stream.');
            }

            fputcsv($output, array_values(self::CSV_COLUMNS));

            foreach ($agents as $agent) {
                $row = $this->row($agent);
                fputcsv($output, array_map(fn (string $key): string => (string) ($row[$key] ?? ''), array_keys(self::CSV_COLUMNS)));
            }

            fclose($output);
        }, 'agents-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The booking form's agent picker: contacts matching the agency, code, name, phone or e-mail.
     */
    public function search(Request $request, Property $property): JsonResponse
    {
        $term = (string) $request->validate(['q' => ['nullable', 'string', 'max:255']])['q'];

        $agents = $this->filteredQuery($property, array_filter(['q' => trim($term)]))
            ->limit(20)
            ->get()
            ->map(fn (Agent $agent): array => $agent->toOption());

        return response()->json(['agents' => $agents]);
    }

    /**
     * Adds an agent — from this page, or (as JSON) from the booking form's agent picker.
     */
    public function store(Request $request, Property $property): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request, $property);

        $agent = DB::transaction(function () use ($property, $data): Agent {
            return $this->agencyFor($property, $data)->agents()->create($this->agentAttributes($data));
        });

        if ($request->expectsJson()) {
            return response()->json(['agent' => $agent->load('agency')->toOption()], 201);
        }

        return back()->with('success', "Agent {$agent->contact_name} added.");
    }

    public function update(Request $request, Property $property, Agent $agent): RedirectResponse
    {
        abort_unless($agent->agency->property_id === $property->id, 404);

        $data = $this->validated($request, $property);

        DB::transaction(function () use ($property, $data, $agent): void {
            $agent->update([...$this->agentAttributes($data), 'agency_id' => $this->agencyFor($property, $data)->id]);
        });

        return back()->with('success', "Agent {$agent->contact_name} updated.");
    }

    /**
     * Soft deletes an agent: it leaves the list and the booking form's picker; bookings made
     * through it keep it. "Show deleted" on the list restores it.
     */
    public function destroy(Property $property, Agent $agent): RedirectResponse
    {
        abort_unless($agent->agency->property_id === $property->id, 404);

        $agent->delete();

        return back()->with('success', "Agent {$agent->contact_name} deleted.");
    }

    public function restore(Property $property, int $agent): RedirectResponse
    {
        $trashed = Agent::onlyTrashed()->with('agency')->findOrFail($agent);
        abort_unless($trashed->agency->property_id === $property->id, 404);

        $trashed->restore();

        return back()->with('success', "Agent {$trashed->contact_name} restored.");
    }

    /**
     * The agency named on the form — an existing one (matched case-insensitively) or a new
     * one — carrying the form's source, rate code and address.
     *
     * @param  array<string, mixed>  $data
     */
    private function agencyFor(Property $property, array $data): Agency
    {
        $name = trim($data['agency_name']);
        $agency = $property->agencies()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        // Roles and Permissions: a new agency needs "Can add Agency" (or "Add Agency" in a reservation).
        if ($agency === null) {
            $gate = app(PermissionGate::class);
            abort_unless($gate->allows(request()->user(), 'database.agents.add_agency') || $gate->allows(request()->user(), 'reservation.add_agency'), 403, "You don't have permission to add an agency.");
        }

        $agency ??= $property->agencies()->make(['name' => $name]);

        $agency->fill([
            'source' => $data['source'],
            'agent_rate_code_id' => $data['agent_rate_code_id'] ?? null,
            'address' => $data['address'] ?? $agency->address,
            'city' => $data['city'] ?? $agency->city,
        ])->save();

        return $agency;
    }

    /**
     * @param  array{contact_name: string, phone_country_code?: string|null, phone?: string|null, emails?: list<string|null>}  $data
     * @return array{contact_name: string, phone_country_code: string|null, phone: string|null, emails: list<string>}
     */
    private function agentAttributes(array $data): array
    {
        $phone = $data['phone'] ?? null;

        return [
            'contact_name' => trim($data['contact_name']),
            'phone_country_code' => $data['phone_country_code'] ?? null,
            'phone' => $phone !== null && filled($phone) ? preg_replace('/\s+/', '', $phone) : null,
            'emails' => array_values(collect($data['emails'] ?? [])->map(fn (?string $email): string => trim((string) $email))->filter()->unique()->all()),
        ];
    }

    /**
     * @return array{source: string, agency_name: string, agent_rate_code_id?: int|string|null, contact_name: string, phone_country_code?: string|null, phone?: string|null, emails?: list<string|null>, address?: string|null, city?: string|null}
     */
    private function validated(Request $request, Property $property): array
    {
        return $request->validate([
            'source' => ['required', Rule::in(array_keys(Agency::SOURCES))],
            'agency_name' => ['required', 'string', 'max:255'],
            'agent_rate_code_id' => ['nullable', 'integer', Rule::exists(AgentRateCode::class, 'id')->where('property_id', $property->id)],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone_country_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 ]+$/'],
            'emails' => ['array', 'max:10'],
            'emails.*' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
        ], [
            'agency_name.required' => 'Pick an agency or type a new agency name.',
            'phone.regex' => 'Use digits only.',
            'emails.*.email' => 'Enter a valid e-mail address.',
        ]);
    }

    /**
     * @return array{q?: string, letter?: string, trashed?: bool}
     */
    private function validatedFilters(Request $request): array
    {
        return array_filter($request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'letter' => ['nullable', 'string', 'size:1', 'alpha'],
            'trashed' => ['nullable', 'boolean'],
        ]), fn (mixed $value): bool => filled($value) && $value !== false && $value !== '0');
    }

    /**
     * Agents of the property's agencies, by agency name, narrowed by the search box (agency,
     * code, contact, phone or e-mail) and the A–Z bar (agency name's first letter). With
     * `trashed`, the deleted agents instead (to restore one).
     *
     * @param  array{q?: string, letter?: string, trashed?: bool}  $filters
     * @return Builder<Agent>
     */
    private function filteredQuery(Property $property, array $filters): Builder
    {
        return Agent::query()
            ->when((bool) ($filters['trashed'] ?? false), fn (Builder $query) => $query->onlyTrashed())
            ->select('agents.*')
            ->join('agencies', 'agencies.id', '=', 'agents.agency_id')
            ->where('agencies.property_id', $property->id)
            ->with(['agency.rateCode:id,name', 'createdBy:id,name', 'updatedBy:id,name'])
            ->when($filters['letter'] ?? null, fn (Builder $query, string $letter) => $query->whereLike('agencies.name', strtoupper($letter).'%'))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(fn (Builder $inner) => $inner
                    ->whereLike('agencies.name', $like)
                    ->orWhereLike('agencies.code', $like)
                    ->orWhereLike('agents.contact_name', $like)
                    ->orWhereLike('agents.phone', $like)
                    ->orWhereLikeText('agents.emails', $like));
            })
            ->orderBy('agencies.name')
            ->orderBy('agents.contact_name');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Agent $agent): array
    {
        $agency = $agent->agency;

        return [
            'id' => $agent->id,
            'agency_id' => $agency->id,
            'agency_name' => $agency->name,
            'agency_code' => $agency->code,
            'agency_class' => $agency->rateCode?->name,
            'agent_rate_code_id' => $agency->agent_rate_code_id,
            'source' => $agency->source,
            'source_label' => Agency::SOURCES[$agency->source] ?? $agency->source,
            'contact_name' => $agent->contact_name,
            'phone_country_code' => $agent->phone_country_code,
            'phone' => $agent->phone,
            'mobile' => $agent->phone,
            'emails' => $agent->emails ?? [],
            'email' => implode(', ', $agent->emails ?? []),
            'address' => $agency->address,
            'city' => $agency->city,
            'created_at' => $agent->created_at?->toIso8601ZuluString(),
            'created_by' => $agent->createdBy?->name,
            'updated_at' => $agent->updated_at?->toIso8601ZuluString(),
            'updated_by' => $agent->updatedBy?->name,
            'deleted_at' => $agent->deleted_at?->toIso8601ZuluString(),
        ];
    }

    private function selectedProperty(Request $request): ?Property
    {
        $properties = app(ApplicationSettings::class)->orderedProperties(['id', 'name', 'abbreviation', 'country_id']);

        return app(CurrentProperty::class)->selected($request, $properties);
    }
}
