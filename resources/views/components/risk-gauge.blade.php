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
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>

    @if ($showLabel)
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-2xl font-semibold text-slate-900 tabular-nums">{{ round($numeric) }}</span>
            <x-status-badge :status="$riskBand" />
        </div>
    @endif

    {{-- role="meter" is the honest element here: it is a measurement in a known
         range, not a task in progress. Screen readers announce the value and
         the range rather than reading a decorative bar. --}}
    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100"
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
        <dl class="mt-3 space-y-1 text-xs">
            @foreach (collect($factors)->sortDesc() as $name => $contribution)
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-slate-600">{{ Str::headline($name) }}</dt>
                    <dd class="tabular-nums text-slate-900">{{ round((float) $contribution, 1) }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
