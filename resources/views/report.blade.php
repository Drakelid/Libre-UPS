@extends('layouts.librenmsv1')

@php
    $t = fn (string $key) => trans('ups-battery::ups-battery.'.$key, [], $locale);
    $url = fn (string $route) => \Drakelid\UpsBattery\Report\Urls::relative(route($route));

    $config = [
        'defaults' => $defaults,
        'initial' => $initial,
        'matrixDefaults' => $matrixDefaults,
        'defaultView' => $defaultView,
        'refreshSeconds' => $refreshSeconds,
        'staleMinutes' => $staleMinutes,
        // Relative URLs keep the page working behind proxies / under a different host name or sub-directory.
        'urls' => [
            'page' => $url('ups-battery.report'),
            'data' => $url('ups-battery.data'),
            'matrix' => $url('ups-battery.matrix'),
            'options' => $url('ups-battery.options'),
            'ups' => $url('ups-battery.ups'),
            'battery' => $url('ups-battery.battery'),
            'views' => $url('ups-battery.views'),
            'saveView' => $url('ups-battery.views.save'),
            'deleteView' => $url('ups-battery.views.delete'),
        ],
        'i18n' => trans('ups-battery::ups-battery', [], $locale),
    ];
@endphp

@section('title', $t('title'))

@push('styles')
<style>
    /* Colours that work on LibreNMS' light and dark themes: grey as translucent black/white, status colours as tints. */
    #ups-battery {
        --ub-red: #d9534f; --ub-orange: #e8962e; --ub-green: #4cae4c; --ub-blue: #3a87c8;
        --ub-red-soft: rgba(217, 83, 79, .14); --ub-orange-soft: rgba(232, 150, 46, .16); --ub-green-soft: rgba(76, 174, 76, .15); --ub-blue-soft: rgba(58, 135, 200, .14);
        --ub-line: rgba(127, 127, 127, .28); --ub-line-soft: rgba(127, 127, 127, .16); --ub-surface: rgba(127, 127, 127, .06);
        --ub-radius: 6px;
    }

    /* ---- header and filters ---- */
    #ups-battery .ub-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
    #ups-battery .ub-heading .panel-title { margin-right: auto; font-size: 1.15em; font-weight: 600; }
    #ups-battery .ub-switch input[type="radio"] { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
    #ups-battery .ub-switch .btn.active { font-weight: 600; box-shadow: inset 0 -2px 0 var(--ub-blue); }
    #ups-battery .ub-switch .btn:focus-within { outline: 2px solid var(--ub-blue); outline-offset: -2px; }
    #ups-battery .ub-actions { display: flex; flex-wrap: wrap; gap: 4px; }
    #ups-battery .ub-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; }
    #ups-battery .ub-filters .form-group { margin: 0; }
    #ups-battery .ub-filters label { margin-right: 4px; font-weight: normal; opacity: .75; }
    #ups-battery .ub-views { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--ub-line-soft); }

    /* ---- summary cards and timeline ---- */
    #ups-battery .ub-overview { display: flex; flex-wrap: wrap; gap: 12px; align-items: stretch; margin-bottom: 14px; }
    #ups-battery .ub-cards { display: flex; flex-wrap: wrap; gap: 10px; flex: 3 1 520px; align-content: flex-start; }
    #ups-battery .ub-card {
        flex: 1 1 140px; max-width: 220px; min-height: 78px; display: flex; flex-direction: column; justify-content: center;
        padding: 10px 14px; border: 1px solid var(--ub-line-soft); border-left: 4px solid var(--ub-line); border-radius: var(--ub-radius);
        background: var(--ub-surface); transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease;
    }
    #ups-battery .ub-card-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    #ups-battery .ub-card-value { font-size: 1.9em; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; }
    #ups-battery .ub-card-label { font-size: .75em; text-transform: uppercase; letter-spacing: .04em; opacity: .7; margin-top: 2px; }
    #ups-battery .ub-card-icon { font-size: 1.4em; opacity: .35; }
    #ups-battery .ub-card.ub-danger { border-left-color: var(--ub-red); background: var(--ub-red-soft); }
    #ups-battery .ub-card.ub-danger .ub-card-value, #ups-battery .ub-card.ub-danger .ub-card-icon { color: var(--ub-red); opacity: 1; }
    #ups-battery .ub-card.ub-warn { border-left-color: var(--ub-orange); background: var(--ub-orange-soft); }
    #ups-battery .ub-card.ub-warn .ub-card-value, #ups-battery .ub-card.ub-warn .ub-card-icon { color: var(--ub-orange); opacity: 1; }
    #ups-battery .ub-card-button { cursor: pointer; }
    #ups-battery .ub-card-button:hover, #ups-battery .ub-card-button:focus { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0, 0, 0, .15); outline: none; }
    #ups-battery .ub-card-button:focus-visible { box-shadow: 0 0 0 2px var(--ub-blue); }
    #ups-battery .ub-card.ub-active { box-shadow: 0 0 0 2px var(--ub-blue); }

    #ups-battery .ub-timeline-box { flex: 2 1 320px; padding: 10px 14px; border: 1px solid var(--ub-line-soft); border-radius: var(--ub-radius); background: var(--ub-surface); }
    #ups-battery .ub-timeline-title { font-size: .75em; text-transform: uppercase; letter-spacing: .04em; opacity: .8; margin-bottom: 6px; font-weight: 600; }
    #ups-battery .ub-timeline { display: flex; gap: 4px; align-items: flex-end; border-bottom: 1px solid var(--ub-line); }
    #ups-battery .ub-tl-col { flex: 1 1 0; text-align: center; font-size: .8em; min-width: 0; }
    #ups-battery .ub-tl-count { height: 1.3em; font-weight: 700; font-variant-numeric: tabular-nums; }
    #ups-battery .ub-tl-bar-box { height: 64px; display: flex; align-items: flex-end; justify-content: center; }
    #ups-battery .ub-tl-bar { width: 70%; max-width: 28px; min-height: 2px; background: var(--ub-blue); border-radius: 3px 3px 0 0; opacity: .85; }
    #ups-battery .ub-tl-soon .ub-tl-bar { background: var(--ub-orange); opacity: 1; }
    #ups-battery .ub-tl-empty .ub-tl-bar { background: var(--ub-line-soft); }
    #ups-battery .ub-tl-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: .7; padding-top: 3px; }
    #ups-battery .ub-tl-col:first-child .ub-tl-label { font-weight: 700; opacity: 1; }

    /* ---- table ---- */
    #ups-battery #ub-table > thead > tr > th.ub-help { cursor: help; text-decoration: underline dotted rgba(127, 127, 127, .6); text-underline-offset: 3px; }
    #ups-battery #ub-table > thead > tr > th {
        white-space: nowrap; font-size: .78em; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .85;
        border-bottom: 2px solid var(--ub-line); vertical-align: bottom;
    }
    #ups-battery #ub-table > tbody > tr > td { vertical-align: middle; border-top-color: var(--ub-line-soft); }
    #ups-battery #ub-table .ub-num { text-align: right; font-variant-numeric: tabular-nums; }
    #ups-battery #ub-table .ub-num .ub-bar { margin-left: auto; }
    #ups-battery tr[class*="ub-sev-"] > td:first-child { border-left: 4px solid transparent; }
    #ups-battery tr.ub-sev-critical > td:first-child { border-left-color: var(--ub-red); }
    #ups-battery tr.ub-sev-warning > td:first-child { border-left-color: var(--ub-orange); }
    #ups-battery tr.ub-sev-ok > td:first-child { border-left-color: var(--ub-green); }
    #ups-battery tr.text-muted > td { opacity: .65; }
    #ups-battery .ub-host a { font-weight: 600; }
    #ups-battery .ub-host small { display: block; opacity: .65; }
    #ups-battery .ub-sub { font-size: .82em; opacity: .75; margin-top: 3px; white-space: nowrap; }
    #ups-battery .ub-toggle, #ups-battery .ub-edit { padding: 0 4px; }
    #ups-battery .ub-toggle .fa { transition: transform .15s ease; }
    #ups-battery .ub-trend { margin-left: 6px; opacity: .5; }
    #ups-battery .ub-trend:hover { opacity: 1; }

    /* Pills: rounded labels; "soft" ones (attention, countdown) are tinted instead of solid. */
    #ups-battery td .label { display: inline-block; margin: 1px 0; }
    #ups-battery .ub-pill { border-radius: 999px; padding: .3em .7em; font-weight: 600; letter-spacing: .01em; }
    #ups-battery .ub-pill-soft { border: 1px solid transparent; }
    #ups-battery .ub-pill-soft.label-danger { background: var(--ub-red-soft); color: var(--ub-red); border-color: rgba(217, 83, 79, .35); }
    #ups-battery .ub-pill-soft.label-warning { background: var(--ub-orange-soft); color: var(--ub-orange); border-color: rgba(232, 150, 46, .4); }
    #ups-battery .ub-pill-soft.label-success { background: var(--ub-green-soft); color: var(--ub-green); border-color: rgba(76, 174, 76, .35); }
    #ups-battery .ub-pill-soft.label-default { background: var(--ub-surface); color: inherit; border-color: var(--ub-line); }
    #ups-battery .ub-dot { display: inline-block; width: .55em; height: .55em; border-radius: 50%; background: currentColor; margin-right: .4em; vertical-align: middle; }
    #ups-battery .label-danger .ub-dot { animation: ub-pulse 1.4s ease-in-out infinite; }
    @keyframes ub-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) {
        #ups-battery .label-danger .ub-dot { animation: none; }
        #ups-battery .ub-card, #ups-battery .ub-toggle .fa { transition: none; }
    }

    /* Bars: charge, load and battery life used. */
    #ups-battery .ub-bar { height: 5px; min-width: 50px; max-width: 110px; margin-top: 4px; border-radius: 3px; background: var(--ub-line-soft); overflow: hidden; }
    #ups-battery .ub-bar > span { display: block; height: 100%; border-radius: 3px; background: var(--ub-green); }
    #ups-battery .ub-bar-warning > span { background: var(--ub-orange); }
    #ups-battery .ub-bar-critical > span { background: var(--ub-red); }

    /* ---- details of a UPS ---- */
    #ups-battery .ub-details > td { background: var(--ub-surface); padding: 12px 14px; }
    #ups-battery .ub-details ul { list-style: none; margin: 0; padding: 0; }
    #ups-battery .ub-detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
    #ups-battery .ub-group { border: 1px solid var(--ub-line-soft); border-radius: var(--ub-radius); padding: 8px 12px; min-width: 0; background: var(--ub-surface); }
    #ups-battery .ub-group h5 { margin: 0 0 6px; font-size: .78em; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .85; }
    #ups-battery .ub-group h5 small { font-weight: normal; }
    #ups-battery .ub-item { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; border-bottom: 1px solid var(--ub-line-soft); }
    #ups-battery .ub-item:last-child { border-bottom: 0; }
    #ups-battery .ub-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .8; }
    #ups-battery .ub-item-value { font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
    #ups-battery .ub-item-warning { color: var(--ub-orange); }
    #ups-battery .ub-item-critical { color: var(--ub-red); }
    #ups-battery .ub-item-warning .ub-item-name, #ups-battery .ub-item-critical .ub-item-name { opacity: 1; }
    #ups-battery .ub-item-group { opacity: .7; margin-right: 6px; white-space: nowrap; }
    #ups-battery .ub-problems { border: 1px solid rgba(217, 83, 79, .45); border-left: 4px solid var(--ub-red); border-radius: var(--ub-radius); background: var(--ub-red-soft); padding: 8px 12px; margin-bottom: 10px; max-width: 680px; }
    #ups-battery .ub-problems .ub-item-name { flex: 1; }
    #ups-battery .ub-more { padding: 0; margin-top: 4px; }

    /* ---- summary line and kiosk ---- */
    #ups-battery #ub-summary { margin: 4px 0 8px; }
    #ups-battery .ub-focus { margin-left: 8px; }
    #ups-battery .ub-focus button { padding: 0 4px; vertical-align: baseline; }
    #ups-battery .ub-dash { opacity: .4; }
    #ups-battery .ub-exit-kiosk { display: none; }
    #ups-battery.ub-kiosk .ub-controls { display: none; }
    #ups-battery.ub-kiosk .ub-exit-kiosk { display: block; position: fixed; top: 60px; right: 16px; z-index: 1500; }
    #ups-battery.ub-kiosk #ub-table { font-size: 1.5em; }
    #ups-battery.ub-kiosk #ub-summary { font-size: 1.3em; }
    #ups-battery.ub-kiosk .ub-card-value { font-size: 2.6em; }</style>
@endpush

@section('content')
<div class="container-fluid" id="ups-battery">
    <button type="button" id="ub-kiosk-exit" class="btn btn-default btn-sm ub-exit-kiosk">
        <i class="fa fa-compress fa-fw" aria-hidden="true"></i> {{ $t('kiosk.exit') }}
    </button>

    <div class="ub-controls">
        <div class="panel panel-default">
            <div class="panel-heading ub-heading">
                <h3 class="panel-title"><i class="fa fa-battery-half fa-fw" aria-hidden="true"></i> {{ $t('title') }}</h3>
                <div class="btn-group btn-group-sm ub-switch" role="radiogroup" aria-label="{{ $t('view.label') }}">
                    <label class="btn btn-default"><input type="radio" name="ub-view" id="ub-view-ups" value="ups"> <i class="fa fa-th-list fa-fw" aria-hidden="true"></i> {{ $t('view.ups') }}</label>
                    <label class="btn btn-default"><input type="radio" name="ub-view" id="ub-view-single" value="single"> <i class="fa fa-sort-amount-asc fa-fw" aria-hidden="true"></i> {{ $t('view.single') }}</label>
                    <label class="btn btn-default"><input type="radio" name="ub-view" id="ub-view-matrix" value="matrix"> <i class="fa fa-table fa-fw" aria-hidden="true"></i> {{ $t('view.matrix') }}</label>
                </div>
                <div class="ub-actions">
                    <a id="ub-csv" class="btn btn-default btn-sm" href="#" title="{{ $t('filters.export') }}">
                        <i class="fa fa-download fa-fw" aria-hidden="true"></i> {{ $t('filters.export') }}
                    </a>
                    <a id="ub-csv-all" class="btn btn-default btn-sm" href="#">
                        <i class="fa fa-download fa-fw" aria-hidden="true"></i> {{ $t('filters.export_all') }}
                    </a>
                    <button type="button" id="ub-kiosk" class="btn btn-default btn-sm">
                        <i class="fa fa-expand fa-fw" aria-hidden="true"></i> {{ $t('filters.kiosk') }}
                    </button>
                </div>
            </div>
            <div class="panel-body">
                <form class="form-inline ub-filters" id="ub-filters" onsubmit="return false;">
                    <div class="form-group">
                        <label for="ub-q" class="sr-only">{{ $t('filters.search') }}</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-addon"><i class="fa fa-search" aria-hidden="true"></i></span>
                            <input type="search" id="ub-q" class="form-control" maxlength="100"
                                   placeholder="{{ $t('filters.search_placeholder') }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ub-type">{{ $t('filters.type') }}</label>
                        <select id="ub-type" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group" id="ub-class-group">
                        <label for="ub-class">{{ $t('filters.metric') }}</label>
                        <select id="ub-class" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group" id="ub-classes-group" style="display: none;">
                        <label>{{ $t('filters.metrics') }}</label>
                        <span id="ub-classes"></span>
                    </div>
                    <div class="form-group">
                        <label for="ub-os">{{ $t('filters.os') }}</label>
                        <select id="ub-os" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group">
                        <label for="ub-group">{{ $t('filters.group') }}</label>
                        <select id="ub-group" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group" id="ub-aggregate-group">
                        <label for="ub-aggregate">{{ $t('filters.aggregate') }}</label>
                        <select id="ub-aggregate" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group">
                        <label for="ub-limit">{{ $t('filters.limit') }}</label>
                        <select id="ub-limit" class="form-control input-sm"></select>
                    </div>
                    <div class="form-group" id="ub-sensor-group">
                        <label for="ub-sensor" class="sr-only">{{ $t('filters.sensor') }}</label>
                        <input type="search" id="ub-sensor" class="form-control input-sm" maxlength="100"
                               placeholder="{{ $t('filters.sensor_placeholder') }}" title="{{ $t('filters.sensor') }}">
                    </div>
                    <div class="form-group" id="ub-suspect-group" style="display: none;">
                        <label class="checkbox-inline" title="{{ $t('suspect.tooltip') }}">
                            <input type="checkbox" id="ub-suspect"> {{ $t('filters.suspect') }}
                        </label>
                    </div>
                    <div class="form-group" id="ub-attention-group" style="display: none;">
                        <label class="checkbox-inline"><input type="checkbox" id="ub-attention"> {{ $t('filters.attention') }}</label>
                    </div>
                    <div class="form-group" id="ub-refresh-group">
                        <label class="checkbox-inline"><input type="checkbox" id="ub-refresh"> {{ $t('filters.refresh') }}</label>
                    </div>
                </form>
                <form class="form-inline ub-views" id="ub-views-form" onsubmit="return false;">
                    <div class="form-group">
                        <label for="ub-views" class="sr-only">{{ $t('views.choose') }}</label>
                        <select id="ub-views" class="form-control input-sm"></select>
                    </div>
                    <button type="button" id="ub-view-save" class="btn btn-default btn-sm">
                        <i class="fa fa-save fa-fw" aria-hidden="true"></i> {{ $t('views.save') }}
                    </button>
                    <button type="button" id="ub-view-delete" class="btn btn-default btn-sm" disabled>
                        <i class="fa fa-trash fa-fw" aria-hidden="true"></i> {{ $t('views.delete') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div id="ub-error" class="alert alert-danger" style="display: none;" role="alert"></div>
    <p id="ub-hint" class="text-muted" style="display: none;">{{ $t('view.matrix_hint') }}</p>
    <div class="ub-overview">
        <div id="ub-cards" class="ub-cards" style="display: none;"></div>
        <div id="ub-timeline-box" class="ub-timeline-box" style="display: none;">
            <div class="ub-timeline-title"><i class="fa fa-calendar fa-fw" aria-hidden="true"></i> {{ $t('ups.timeline_title') }}</div>
            <div id="ub-timeline" class="ub-timeline"></div>
            <p id="ub-timeline-empty" class="text-muted small" style="display: none;">{{ $t('ups.timeline_empty') }}</p>
        </div>
    </div>
    <p id="ub-summary" class="text-muted" aria-live="polite"></p>

    <div class="table-responsive">
        <table id="ub-table" class="table table-condensed table-hover">
            <thead>
                <tr id="ub-head"></tr>
            </thead>
            <tbody id="ub-body"></tbody>
        </table>
    </div>
</div>

{{-- The page's data for report.js; JSON_HEX_* keep it safe inside a script element. --}}
<script type="application/json" id="ub-config">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
{{-- Loaded from this page's own origin: a plain src="/plugin/..." would be resolved against LibreNMS' <base href>,
     which can name another host than the one in the address bar. --}}
<script>
    (function () {
        var script = document.createElement('script');
        script.src = window.location.origin + @json($scriptUrl);
        document.body.appendChild(script);
    })();
</script>
@endpush
