@php
    // Show what the plugin actually uses, so invalid saved values are corrected the next time the form is saved.
    $current = \Drakelid\UpsBattery\Report\PluginSettings::fromArray($settings);
    $thresholdText = is_string($settings['thresholds'] ?? null) ? $settings['thresholds'] : '';
    $thresholdErrors = \Drakelid\UpsBattery\Report\Thresholds::parse($thresholdText)->errors();
    $t = fn (string $key) => trans('ups-battery::ups-battery.settings.'.$key, [], $current->language);
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
            <div class="col-sm-offset-3 col-sm-5">
                <button type="submit" class="btn btn-primary">{{ $t('save') }}</button>
            </div>
        </div>
    </form>
</div>
