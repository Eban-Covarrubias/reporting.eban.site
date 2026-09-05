<script>
    (function () {
        try {
            var saved = localStorage.getItem('theme');
            if (saved === 'light' || saved === 'dark') {
                document.documentElement.setAttribute('data-theme', saved);
            }
        } catch (e) {}
    })();
</script>
<noscript><style>.theme-toggle { display: none !important; }</style></noscript>
<button id="themeToggle" class="theme-toggle" type="button" aria-label="Toggle light/dark mode">&#9728;&#65039;</button>
<script>
    (function () {
        var btn = document.getElementById('themeToggle');
        function current() {
            return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        }
        function render() {
            btn.textContent = current() === 'light' ? '\u{1F319}' : '\u{2600}\u{FE0F}';
        }
        render();
        btn.addEventListener('click', function () {
            var next = current() === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
            render();
        });
    })();
</script>
