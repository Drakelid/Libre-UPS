<a href="{{ route('ups-battery.report') }}">
    <i class="fa fa-battery-half fa-fw fa-lg" aria-hidden="true"></i> {{ trans('ups-battery::ups-battery.title', [], $locale ?? 'en') }}
</a>
@if ($topNav ?? true)
    {{-- LibreNMS only renders plugin menu hooks inside Overview > Plugins, so the top-level entry is
         copied into the navigation bar once the page has loaded. Without JavaScript only the entry above remains. --}}
    <script>
        (function () {
            function addTopNav() {
                var bar = document.querySelector('#navHeaderCollapse > ul.navbar-nav:not(.navbar-right)');
                if (!bar || document.getElementById('ub-top-nav')) { return; }
                var item = document.createElement('li');
                item.id = 'ub-top-nav';
                if (window.location.pathname.indexOf('/plugin/ups-battery/') !== -1) { item.className = 'active'; }
                var link = document.createElement('a');
                link.href = @json(\Drakelid\UpsBattery\Report\Urls::relative(route('ups-battery.report')));
                var icon = document.createElement('i');
                icon.className = 'fa fa-battery-half fa-fw fa-lg fa-nav-icons';
                icon.setAttribute('aria-hidden', 'true');
                var label = document.createElement('span');
                label.className = 'hidden-sm';
                label.textContent = @json(trans('ups-battery::ups-battery.title', [], $locale ?? 'en'));
                link.appendChild(icon);
                link.appendChild(document.createTextNode(' '));
                link.appendChild(label);
                item.appendChild(link);
                bar.appendChild(item);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addTopNav);
            } else {
                addTopNav();
            }
        })();
    </script>
@endif
