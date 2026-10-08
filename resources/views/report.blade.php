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
    #ups-battery .ub-exit-kiosk { display: none; }
    #ups-battery.ub-kiosk .ub-controls { display: none; }
    #ups-battery.ub-kiosk .ub-exit-kiosk { display: block; position: fixed; top: 60px; right: 16px; z-index: 1500; }
    #ups-battery.ub-kiosk #ub-table { font-size: 1.6em; }
    #ups-battery.ub-kiosk #ub-summary { font-size: 1.3em; }
    #ups-battery .ub-trend { margin-left: 6px; opacity: .6; }
    #ups-battery .ub-trend:hover { opacity: 1; }
    #ups-battery .ub-cards { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
    #ups-battery .ub-card { flex: 1 1 140px; max-width: 220px; border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; }
    #ups-battery .ub-card-value { font-size: 1.8em; font-weight: bold; line-height: 1.2; }
    #ups-battery .ub-card-label { font-size: .9em; opacity: .8; }
    #ups-battery .ub-card.ub-danger { border-color: #d9534f; color: #d9534f; }
    #ups-battery .ub-card.ub-warn { border-color: #f0ad4e; color: #c77c0e; }
    #ups-battery .ub-details > td { background: rgba(127, 127, 127, .06); padding: 10px 12px; }
    #ups-battery .ub-details ul { list-style: none; margin: 0; padding: 0; }
    #ups-battery .ub-detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
    #ups-battery .ub-group { border: 1px solid rgba(127, 127, 127, .3); border-radius: 4px; padding: 6px 10px; min-width: 0; }
    #ups-battery .ub-group h5 { margin: 0 0 4px; font-weight: bold; }
    #ups-battery .ub-group h5 small { font-weight: normal; }
    #ups-battery .ub-item { display: flex; justify-content: space-between; gap: 8px; padding: 1px 0; border-bottom: 1px dotted rgba(127, 127, 127, .25); }
    #ups-battery .ub-item:last-child { border-bottom: 0; }
    #ups-battery .ub-item-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .85; }
    #ups-battery .ub-item-value { font-weight: bold; white-space: nowrap; }
    #ups-battery .ub-item-warning { color: #c77c0e; }
    #ups-battery .ub-item-critical { color: #d9534f; }
    #ups-battery .ub-item-warning .ub-item-name, #ups-battery .ub-item-critical .ub-item-name { opacity: 1; }
    #ups-battery .ub-item-group { opacity: .7; margin-right: 6px; white-space: nowrap; }
    #ups-battery .ub-problems { border: 1px solid #d9534f; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; max-width: 640px; }
    #ups-battery .ub-problems .ub-item-name { flex: 1; }
    #ups-battery .ub-more { padding: 0; margin-top: 2px; }
    #ups-battery tr[class*="ub-sev-"] > td:first-child { border-left: 4px solid transparent; }
    #ups-battery tr.ub-sev-critical > td:first-child { border-left-color: #d9534f; }
    #ups-battery tr.ub-sev-warning > td:first-child { border-left-color: #f0ad4e; }
    #ups-battery tr.ub-sev-ok > td:first-child { border-left-color: #5cb85c; }
    #ups-battery .ub-toggle, #ups-battery .ub-edit { padding: 0 4px; }
    #ups-battery.ub-kiosk .ub-card-value { font-size: 2.6em; }
    #ups-battery .ub-card-button { cursor: pointer; }
    #ups-battery .ub-card-button:hover, #ups-battery .ub-card-button:focus { background: rgba(127, 127, 127, .08); outline: none; }
    #ups-battery .ub-card.ub-active { box-shadow: inset 0 0 0 2px #337ab7; }
    #ups-battery .ub-bar { height: 4px; min-width: 50px; margin-top: 3px; border-radius: 2px; background: rgba(127, 127, 127, .25); overflow: hidden; }
    #ups-battery .ub-bar > span { display: block; height: 100%; background: #5cb85c; }
    #ups-battery .ub-bar-warning > span { background: #f0ad4e; }
    #ups-battery .ub-bar-critical > span { background: #d9534f; }
    #ups-battery .ub-timeline-box { border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; max-width: 760px; }
    #ups-battery .ub-timeline-title { font-weight: bold; margin-bottom: 4px; }
    #ups-battery .ub-timeline { display: flex; gap: 4px; align-items: flex-end; }
    #ups-battery .ub-tl-col { flex: 1 1 0; text-align: center; font-size: .85em; min-width: 0; }
    #ups-battery .ub-tl-count { height: 1.3em; font-weight: bold; }
    #ups-battery .ub-tl-bar-box { height: 60px; display: flex; align-items: flex-end; justify-content: center; }
    #ups-battery .ub-tl-bar { width: 70%; min-height: 1px; background: #337ab7; border-radius: 2px 2px 0 0; }
    #ups-battery .ub-tl-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: .8; }
    #ups-battery td .label { display: inline-block; margin-bottom: 2px; }
</style>
@endpush

@section('content')
<div class="container-fluid" id="ups-battery">
    <button type="button" id="ub-kiosk-exit" class="btn btn-default btn-sm ub-exit-kiosk">
        <i class="fa fa-compress fa-fw" aria-hidden="true"></i> {{ $t('kiosk.exit') }}
    </button>

    <div class="ub-controls">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-battery-half fa-fw" aria-hidden="true"></i> {{ $t('title') }}</h3>
            </div>
            <div class="panel-body">
                <form class="form-inline" id="ub-filters" onsubmit="return false;">
                    <div class="form-group">
                        <label class="radio-inline"><input type="radio" name="ub-view" id="ub-view-ups" value="ups"> {{ $t('view.ups') }}</label>
                        <label class="radio-inline"><input type="radio" name="ub-view" id="ub-view-single" value="single"> {{ $t('view.single') }}</label>
                        <label class="radio-inline"><input type="radio" name="ub-view" id="ub-view-matrix" value="matrix"> {{ $t('view.matrix') }}</label>
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
                    <div class="form-group">
                        <label for="ub-q" class="sr-only">{{ $t('filters.search') }}</label>
                        <input type="search" id="ub-q" class="form-control input-sm" maxlength="100"
                               placeholder="{{ $t('filters.search_placeholder') }}">
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
                    <a id="ub-csv" class="btn btn-default btn-sm" href="#">
                        <i class="fa fa-download fa-fw" aria-hidden="true"></i> {{ $t('filters.export') }}
                    </a>
                    <a id="ub-csv-all" class="btn btn-default btn-sm" href="#">
                        <i class="fa fa-download fa-fw" aria-hidden="true"></i> {{ $t('filters.export_all') }}
                    </a>
                    <button type="button" id="ub-kiosk" class="btn btn-default btn-sm">
                        <i class="fa fa-expand fa-fw" aria-hidden="true"></i> {{ $t('filters.kiosk') }}
                    </button>
                </form>
                <form class="form-inline" id="ub-views-form" style="margin-top: 10px;" onsubmit="return false;">
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
    <div id="ub-cards" class="ub-cards" style="display: none;"></div>
    <div id="ub-timeline-box" class="ub-timeline-box" style="display: none;">
        <div class="ub-timeline-title">{{ $t('ups.timeline_title') }}</div>
        <div id="ub-timeline" class="ub-timeline"></div>
        <p id="ub-timeline-empty" class="text-muted small" style="display: none;">{{ $t('ups.timeline_empty') }}</p>
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
