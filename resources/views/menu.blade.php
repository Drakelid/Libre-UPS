<a href="{{ route('ups-battery.report') }}">
    <i class="fa fa-battery-half fa-fw fa-lg" aria-hidden="true"></i> {{ trans('ups-battery::ups-battery.title', [], $locale ?? 'en') }}
</a>
@if ($topNav ?? true)
    {{-- LibreNMS only renders plugin menu hooks inside Overview > Plugins, so this entry is copied into the
         navigation bar once the page has loaded. The copy takes the link as written above (an absolute URL):
         LibreNMS pages carry <base href>, which can send a host-relative link to another host. --}}
    <script>
        (function () {
            var source = document.currentScript && document.currentScript.parentNode.querySelector('a');
            if (!source) { return; }

            function addTopNav() {
                var bar = document.querySelector('#navHeaderCollapse > ul.navbar-nav:not(.navbar-right)');
                if (!bar || document.getElementById('ub-top-nav')) { return; }
                var item = document.createElement('li');
                item.id = 'ub-top-nav';
                if (window.location.pathname.indexOf('/plugin/ups-battery/') !== -1) { item.className = 'active'; }
                var link = document.createElement('a');
                link.setAttribute('href', source.getAttribute('href'));
                var icon = document.createElement('i');
                icon.className = 'fa fa-battery-half fa-fw fa-lg fa-nav-icons';
                icon.setAttribute('aria-hidden', 'true');
                var label = document.createElement('span');
                label.className = 'hidden-sm';
                label.textContent = source.textContent.trim();
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
