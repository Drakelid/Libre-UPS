@php
    // Show what the plugin actually uses, so invalid saved values are corrected the next time the form is saved.
    $current = \Drakelid\UpsBattery\Report\PluginSettings::fromArray($settings);
    $thresholdText = is_string($settings['thresholds'] ?? null) ? $settings['thresholds'] : '';
    $thresholdErrors = $current->thresholds->errors();
    $alertHints = \Drakelid\UpsBattery\Report\AlertRuleHint::forThresholds($current->thresholds);
    $recipientText = is_string($settings['report_recipients'] ?? null) ? $settings['report_recipients'] : '';
    $number = fn (float $value): string => \Drakelid\UpsBattery\Report\NumberFormat::plain($value);
    $t = fn (string $key) => trans('ups-battery::ups-battery.settings.'.$key, [], $current->language);
    $days = trans('ups-battery::ups-battery.settings.days', [], $current->language);
@endphp
<div class="container-fluid">
    <h3>{{ $t('title') }}</h3>
    <form method="post" action="{{ route('plugin.update', ['plugin' => $plugin_name]) }}" class="form-horizontal">
        @csrf
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-default-type">{{ $t('default_type') }}</label>
            <div class="col-sm-5">
                <input type="text" class="form-control" id="ub-default-type" name="settings[default_type]" value="{{ $current->defaultType }}" maxlength="64">
                <span class="help-block">{{ $t('default_type_help') }}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-default-class">{{ $t('default_class') }}</label>
            <div class="col-sm-5">
                <input type="text" class="form-control" id="ub-default-class" name="settings[default_class]" value="{{ $current->defaultClass }}" maxlength="64">
                <span class="help-block">{{ $t('default_class_help') }}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-default-limit">{{ $t('default_limit') }}</label>
            <div class="col-sm-5">
                <select class="form-control" id="ub-default-limit" name="settings[default_limit]">
                    @foreach ([10, 25, 50, 100, 0] as $option)
                        <option value="{{ $option }}" @selected($current->defaultLimit === $option)>
                            {{ $option === 0 ? trans('ups-battery::ups-battery.all', [], $current->language) : $option }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-thresholds">{{ $t('thresholds') }}</label>
            <div class="col-sm-5">
                <textarea class="form-control" id="ub-thresholds" name="settings[thresholds]" rows="5" spellcheck="false"
                          placeholder="runtime <10 <20&#10;load >90 >75&#10;charge <20 <50">{{ $thresholdText }}</textarea>
                <span class="help-block">{{ $t('thresholds_help') }}</span>
                @if ($thresholdErrors !== [])
                    <div class="alert alert-warning">
                        <strong>{{ $t('thresholds_errors') }}</strong>
                        <ul>
                            @foreach ($thresholdErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-refresh">{{ $t('refresh_seconds') }}</label>
            <div class="col-sm-5">
                <input type="number" class="form-control" id="ub-refresh" name="settings[refresh_seconds]" value="{{ $current->refreshSeconds }}" min="0" max="3600" step="30">
                <span class="help-block">{{ $t('refresh_seconds_help') }}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-stale">{{ $t('stale_minutes') }}</label>
            <div class="col-sm-5">
                <input type="number" class="form-control" id="ub-stale" name="settings[stale_minutes]" value="{{ $current->staleMinutes }}" min="1" max="1440">
                <span class="help-block">{{ $t('stale_minutes_help') }}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-language">{{ $t('language') }}</label>
            <div class="col-sm-5">
                <select class="form-control" id="ub-language" name="settings[language]">
                    <option value="en" @selected($current->language === 'en')>English</option>
                    <option value="nb" @selected($current->language === 'nb')>Norsk (bokmål)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-top-nav-setting">{{ $t('top_nav') }}</label>
            <div class="col-sm-5">
                <select class="form-control" id="ub-top-nav-setting" name="settings[top_nav]">
                    <option value="1" @selected($current->topNav)>{{ $t('top_nav_on') }}</option>
                    <option value="0" @selected(! $current->topNav)>{{ $t('top_nav_off') }}</option>
                </select>
            </div>
        </div>

        <h4>{{ $t('swap_title') }}</h4>
        <p class="text-muted">{{ $t('swap_help') }}</p>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-lifetime">{{ $t('battery_lifetime_months') }}</label>
            <div class="col-sm-2">
                <input type="number" class="form-control" id="ub-lifetime" name="settings[battery_lifetime_months]" value="{{ $current->batteryLifetimeMonths }}" min="6" max="240">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-swap-warn">{{ $t('swap_warn_days') }}</label>
            <div class="col-sm-2">
                <input type="number" class="form-control" id="ub-swap-warn" name="settings[swap_warn_days]" value="{{ $current->swapWarnDays }}" min="0" max="730">
            </div>
        </div>

        <h4>{{ $t('suspect_title') }}</h4>
        <p class="text-muted">{{ $t('suspect_help') }}</p>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-suspect-runtime">{{ $t('suspect_runtime') }}</label>
            <div class="col-sm-2">
                <input type="number" class="form-control" id="ub-suspect-runtime" name="settings[suspect_runtime]" value="{{ $number($current->suspectRule->maxRuntime) }}" min="1" max="1000" step="any">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-suspect-load">{{ $t('suspect_max_load') }}</label>
            <div class="col-sm-2">
                <input type="number" class="form-control" id="ub-suspect-load" name="settings[suspect_max_load]" value="{{ $number($current->suspectRule->maxLoad) }}" min="0" max="100" step="any">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-suspect-charge">{{ $t('suspect_min_charge') }}</label>
            <div class="col-sm-2">
                <input type="number" class="form-control" id="ub-suspect-charge" name="settings[suspect_min_charge]" value="{{ $number($current->suspectRule->minCharge) }}" min="0" max="100" step="any">
            </div>
        </div>

        <h4>{{ $t('report_title') }}</h4>
        <p class="text-muted">{{ $t('report_help') }}</p>
        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-5">
                <div class="checkbox">
                    <label><input type="checkbox" name="settings[report_enabled]" value="1" @checked($current->report->enabled)> {{ $t('report_enabled') }}</label>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-report-recipients">{{ $t('report_recipients') }}</label>
            <div class="col-sm-5">
                <textarea class="form-control" id="ub-report-recipients" name="settings[report_recipients]" rows="3" spellcheck="false">{{ $recipientText }}</textarea>
                <span class="help-block">{{ $t('report_recipients_help') }}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-report-day">{{ $t('report_day') }}</label>
            <div class="col-sm-3">
                <select class="form-control" id="ub-report-day" name="settings[report_day]">
                    @foreach ($days as $index => $name)
                        <option value="{{ $index }}" @selected($current->report->day === $index)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-report-time">{{ $t('report_time') }}</label>
            <div class="col-sm-2">
                <input type="time" class="form-control" id="ub-report-time" name="settings[report_time]" value="{{ $current->report->time }}">
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="ub-report-top">{{ $t('report_top') }}</label>
            <div class="col-sm-2">
                <select class="form-control" id="ub-report-top" name="settings[report_top]">
                    @foreach (\Drakelid\UpsBattery\Report\ReportSchedule::TOP_CHOICES as $option)
                        <option value="{{ $option }}" @selected($current->report->top === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-5">
                <button type="submit" class="btn btn-primary">{{ $t('save') }}</button>
            </div>
        </div>
    </form>

    <h4>{{ $t('alert_title') }}</h4>
    <p class="text-muted">{{ $t('alert_help') }}</p>
    @if ($alertHints === [])
        <p>{{ $t('alert_none') }}</p>
    @else
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th>{{ $t('alert_name') }}</th>
                    <th>{{ $t('alert_severity') }}</th>
                    <th>{{ $t('alert_query') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($alertHints as $hint)
                    <tr>
                        <td>{{ $hint['name'] }}</td>
                        <td>{{ $hint['severity'] }}</td>
                        <td><code>{{ $hint['rule'] }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
