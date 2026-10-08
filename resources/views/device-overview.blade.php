@php
    $t = fn (string $key, array $replace = []) => trans('ups-battery::ups-battery.'.$key, $replace, $locale ?? 'en');
    $stale = fn (?string $iso): bool => $iso !== null && (time() - (int) strtotime($iso)) > ($staleMinutes ?? 30) * 60;
    $rowClass = fn ($severity): string => match ($severity->value) {
        'critical' => 'danger',
        'warning' => 'warning',
        default => '',
    };
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed device-overview">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-battery-half fa-fw fa-lg" aria-hidden="true"></i> {{ $t('device_card.title') }}
                    <span class="pull-right">
                        <a href="{{ route('ups-battery.report') }}">{{ $t('device_card.open_report') }}</a>
                    </span>
                </h3>
            </div>
            @if (($suspect ?? null) === true)
                <div class="alert alert-warning" style="margin: 8px;" role="alert">
                    <i class="fa fa-exclamation-triangle fa-fw" aria-hidden="true"></i> {{ $t('device_card.suspect') }}
                </div>
            @endif
            <table class="table table-condensed table-hover">
                <thead>
                    <tr>
                        <th>{{ $t('device_card.sensor') }}</th>
                        <th>{{ $t('device_card.value') }}</th>
                        <th>{{ $t('device_card.updated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="{{ $rowClass($row->severity) }}">
                            <td>
                                @if ($row->sensorUrl !== '')
                                    <a href="{{ $row->sensorUrl }}">{{ $row->sensorDescr }}</a>
                                @else
                                    {{ $row->sensorDescr }}
                                @endif
                            </td>
                            <td>
                                <strong>{{ $row->valueFormatted }}</strong>
                                @if ($row->trendUrl !== '')
                                    <a href="{{ $row->trendUrl }}" title="{{ $t('trend') }}" style="margin-left: 6px; opacity: .6;">
                                        <i class="fa fa-line-chart" aria-hidden="true"></i><span class="sr-only">{{ $t('trend') }}</span>
                                    </a>
                                @endif
                            </td>
                            <td @class(['text-warning' => $stale($row->lastUpdate)])>
                                @if ($row->lastUpdate !== null)
                                    @if ($stale($row->lastUpdate))
                                        <i class="fa fa-exclamation-triangle" aria-hidden="true" title="{{ $t('stale', ['minutes' => (int) round((time() - (int) strtotime($row->lastUpdate)) / 60)]) }}"></i>
                                    @endif
                                    {{ \Illuminate\Support\Carbon::parse($row->lastUpdate)->diffForHumans() }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
