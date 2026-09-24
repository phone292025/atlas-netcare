@props(['score', 'band' => null, 'showLabel' => true, 'factors' => null])

{{--
    A 0-100 predictive risk score, as a bar with its band.

    Usage:
        <x-risk-gauge :score="$pc->risk_score" :band="$pc->risk_band" />

    With the reasons, on an asset detail page:

        <x-risk-gauge :score="$assessment->score"
                      :band="$assessment->band"
                      :factors="$assessment->factors" />

    Never nest a Blade comment inside this block. The inner closing token ends
    the outer comment, and every example below it becomes live template code —
    which is exactly how this component first broke.

    THE FACTORS ARE THE POINT. "78" on its own says little; "78, because it
    is five years old and has not been inspected in six weeks" is what
    ADR-0007 chose a weighted model to be able to say. Pass `factors`
    wherever there is room for them.

    `band` is optional: RiskBand::fromScore() derives it from the score when it
    is not supplied, so a caller holding only a number still gets the right
    colour. Pass it when you already have it, to save the config lookup.

    This is Tone's third surface. A gauge fill is solid rather than tinted
    because nothing sits on top of it — see Tone::solid().

    @see docs/modules/M7-predictive-and-reports.md
    @see docs/decisions/ADR-0007-weighted-risk-not-ml.md
--}}

@php
    $numeric = (float) $score;
    $clamped = max(0, min(100, $numeric));

    $riskBand = $band instanceof \App\Enums\RiskBand
        ? $band
        : \App\Enums\RiskBand::fromScore($numeric);

    $fill = \App\Support\Tone::solid($riskBand->tone());

    // Each factor's most possible points, so its bar shows how much of its own
    // share it is using. The scorer drops a factor it cannot measure and
    // spreads the weights over the rest (RiskScoringService::combine()), so a
    // share is the factor's weight over the weights that were measured.
    $weights = collect(config('atlas.risk_weights', []))->collapse()->map(fn ($weight) => (float) $weight);
    $measured = $weights->only(array_keys((array) $factors))->sum();
    $caps = $weights->map(fn ($weight) => $measured > 0 ? $weight / $measured * 100 : 0.0);
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>

    @if ($showLabel)
        <div class="flex items-center justify-between gap-3">
            <span class="text-[32px] font-semibold leading-none tracking-tight text-fg tabular-nums">
                {{ round($numeric) }}<span class="ml-1 text-sm font-normal text-fg-subtle">/ 100</span>
            </span>
            <x-status-badge :status="$riskBand" />
        </div>
    @endif

    {{-- role="meter" is the honest element here: it is a measurement in a known
         range, not a task in progress. Screen readers announce the value and
         the range rather than reading a decorative bar. --}}
    <div class="h-2 w-full overflow-hidden rounded-full bg-sunken"
         role="meter"
         aria-valuenow="{{ round($numeric) }}"
         aria-valuemin="0"
         aria-valuemax="100"
         aria-label="Risk score {{ round($numeric) }} out of 100, {{ $riskBand->label() }}">
        <div class="h-full rounded-full transition-[width] duration-300 {{ $fill }}"
             style="width: {{ $clamped }}%"></div>
    </div>

    @if (filled($factors))
        {{-- factors is the JSON column from risk_assessments: a name => weighted
             contribution map. Sorted heaviest first, because the top one or two
             are what someone would actually act on. --}}
        <dl class="mt-5 space-y-3 text-xs">
            @foreach (collect($factors)->sortDesc() as $name => $contribution)
                @php
                    $points = (float) $contribution;
                    $cap = $caps->get($name) ?: max(1, $points);
                @endphp
                <div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-fg-muted">{{ \App\Models\RiskAssessment::factorLabel($name) }}</dt>
                        <dd class="tabular-nums text-fg">
                            {{ round($points, 1) }}<span class="text-fg-subtle"> of {{ round($cap, 1) }}</span>
                        </dd>
                    </div>
                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                        <div class="h-full rounded-full {{ $fill }} opacity-70" style="width: {{ (int) round(min(1, $points / $cap) * 100) }}%"></div>
                    </div>
                </div>
            @endforeach
        </dl>
    @endif
</div>
