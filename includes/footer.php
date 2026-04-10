
<div id="footer-trigger"></div>
<footer class="mkfb-footer">
    <div class="container">
        <div class="row gy-4">
            <div class="col-lg-3">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <svg width="28" height="28" viewBox="0 0 32 32" fill="none">
                        <circle cx="16" cy="16" r="15" stroke="#e8b84b" stroke-width="2"/>
                        <circle cx="16" cy="16" r="6" fill="#e8b84b"/>
                        <line x1="16" y1="1" x2="16" y2="7" stroke="#e8b84b" stroke-width="2"/>
                        <line x1="16" y1="25" x2="16" y2="31" stroke="#e8b84b" stroke-width="2"/>
                        <line x1="1" y1="16" x2="7" y2="16" stroke="#e8b84b" stroke-width="2"/>
                        <line x1="25" y1="16" x2="31" y2="16" stroke="#e8b84b" stroke-width="2"/>
                    </svg>
                    <span class="fw-bold text-white fs-5">MKFB</span>
                </div>
                <p class="text-light opacity-75 small">
                    Markus Kogler's Filmbewertungen – Ranke deine Lieblingsfilme im 1v1-Duell und entdecke dein persönliches Ranking.
                </p>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="text-white fw-semibold mb-3">Navigation</h6>
                <ul class="list-unstyled small">
                    <li><a href="/index.php#projekt"   class="footer-link">Das Projekt</a></li>
                    <li><a href="/index.php#features"  class="footer-link">Features</a></li>
                    <li><a href="/index.php#demo"      class="footer-link">Demo</a></li>
                    <li><a href="/team.php" class="footer-link">Das Team</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="text-white fw-semibold mb-3">Konto</h6>
                <ul class="list-unstyled small">
                    <li><a href="/register.php" class="footer-link">Registrieren</a></li>
                    <li><a href="/login.php"    class="footer-link">Anmelden</a></li>
                    <li><a href="/charts.php"   class="footer-link">Filmdatenbank</a></li>
                    <?php if ($loggedIn ?? false): ?>
                    <li><a href="/import.php"   class="footer-link">Film-Import</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="text-white fw-semibold mb-3">Rechtliches</h6>
                <ul class="list-unstyled small">
                    <li><a href="/impressum.php"          class="footer-link">Impressum</a></li>
                    <li><a href="/datenschutz.php"         class="footer-link">Datenschutz</a></li>
                    <li><a href="/nutzungsbedingungen.php" class="footer-link">Nutzungsbedingungen</a></li>
                    <li><a href="/kontakt.php"             class="footer-link">Kontakt</a></li>
                </ul>
            </div>
            <div class="col-lg-3">
                <h6 class="text-white fw-semibold mb-3">Über das Projekt</h6>
                <p class="text-light opacity-75 small">
                    Inspiriert von <a href="https://www.flickchart.com" target="_blank" class="footer-link">Flickchart</a> –
                    das beste Filmranking-System der Welt, jetzt auf Deutsch.
                </p>
                <p class="text-light opacity-50 small mb-0">
                    Filmdaten: <a href="https://www.themoviedb.org" target="_blank" class="footer-link">TMDB</a>
                </p>
            </div>
        </div>
        <hr class="border-secondary my-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <span class="text-light opacity-50 small">&copy; <?= date('Y') ?> Markus Kogler's Filmbewertungen</span>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <a href="/impressum.php"          class="footer-link opacity-50 small">Impressum</a>
                <a href="/datenschutz.php"         class="footer-link opacity-50 small">Datenschutz</a>
                <a href="/nutzungsbedingungen.php" class="footer-link opacity-50 small">Nutzungsbedingungen</a>
                <a href="/kontakt.php"             class="footer-link opacity-50 small">Kontakt</a>
                <span class="text-light opacity-50 small">Made with <i class="bi bi-heart-fill text-danger"></i> & PHP</span>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/js/main.js"></script>


<script>
// Globale updateHdrCounters – überschreibt alle Seiten-Definitionen.
// Läuft als letztes Script, daher ab jetzt bei jedem AJAX-Vote aktiv.
window.updateHdrCounters = function(totalDuels, uniqueFilms) {
    var dc = document.getElementById('hdr-duels-count');
    var fc = document.getElementById('hdr-films-count');
    if (dc && totalDuels !== undefined) {
        var n = parseInt(totalDuels, 10);
        var formatted = isNaN(n) ? String(totalDuels) : n.toLocaleString('de-DE');
        if (dc.textContent !== formatted) {
            dc.textContent = formatted;
            var badge = document.getElementById('hdr-badge-duels');
            if (badge) {
                badge.style.transition = 'background 0.15s';
                badge.style.background = 'rgba(232,184,75,.4)';
                setTimeout(function() { badge.style.background = ''; }, 500);
            }
        }
    }
    if (fc && uniqueFilms !== undefined) fc.textContent = uniqueFilms;
};
</script>

<?php if ($loggedIn ?? false): ?>
<!-- ── Notizblock-Widget ─────────────────────────────────────────────────────── -->
<style>
#mkfb-notiz-btn {
    position: fixed; bottom: 22px; right: 22px; z-index: 1055;
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg, #e8b84b, #c4942a);
    border: none; cursor: pointer; box-shadow: 0 3px 12px rgba(0,0,0,.4);
    display: flex; align-items: center; justify-content: center;
    color: #1a1a1a; font-size: 1.1rem; transition: transform .18s, box-shadow .18s;
}
#mkfb-notiz-btn:hover { transform: scale(1.1); box-shadow: 0 5px 18px rgba(0,0,0,.5); }
#mkfb-notiz-btn .notiz-badge {
    position: absolute; top: -4px; right: -4px;
    width: 14px; height: 14px; border-radius: 50%;
    background: #e74c3c; border: 2px solid #14325a;
    display: none;
}
#mkfb-notiz-panel {
    position: fixed; bottom: 76px; right: 22px; z-index: 1054;
    width: 380px; background: #1e3a5f;
    border: 1px solid rgba(232,184,75,.3); border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
    display: none; flex-direction: column; overflow: hidden;
    max-height: calc(100vh - 120px);
}
#mkfb-notiz-panel.open { display: flex; }
.notiz-head {
    background: rgba(232,184,75,.1); border-bottom: 1px solid rgba(232,184,75,.2);
    padding: .55rem .85rem; display: flex; align-items: center; gap: .5rem;
    color: #e8b84b; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; flex-shrink: 0;
}
.notiz-head span { flex: 1; }
.notiz-head-btn { background: none; border: none; color: rgba(255,255,255,.4); cursor: pointer; font-size: .85rem; padding: 0 2px; line-height: 1; }
.notiz-head-btn:hover { color: #e8b84b; }
#mkfb-notiz-ta {
    flex: 1; background: transparent; border: none; resize: none;
    color: #e0e0e0; font-size: .85rem; line-height: 1.55; padding: .75rem .85rem;
    min-height: 260px; outline: none; font-family: inherit;
}
#mkfb-notiz-ta::placeholder { color: rgba(255,255,255,.2); }
.notiz-foot {
    border-top: 1px solid rgba(255,255,255,.07);
    padding: .45rem .85rem; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.notiz-status { font-size: .72rem; color: rgba(255,255,255,.3); }
.notiz-link { font-size: .75rem; color: rgba(232,184,75,.6); text-decoration: none; }
.notiz-link:hover { color: #e8b84b; }
</style>

<button id="mkfb-notiz-btn" title="Notizblock" aria-label="Notizblock öffnen">
    <i class="bi bi-pencil-fill"></i>
    <span class="notiz-badge" id="mkfb-notiz-badge"></span>
</button>

<div id="mkfb-notiz-panel" role="dialog" aria-label="Notizblock">
    <div class="notiz-head">
        <i class="bi bi-pencil-fill"></i>
        <span>Notizblock</span>
        <button class="notiz-head-btn" id="mkfb-notiz-clear" title="Alles löschen"><i class="bi bi-trash3"></i></button>
        <button class="notiz-head-btn" id="mkfb-notiz-close" title="Schließen"><i class="bi bi-x-lg"></i></button>
    </div>
    <textarea id="mkfb-notiz-ta" placeholder="Filme notieren, die du einordnen möchtest…&#10;z.B. Inception&#10;The Dark Knight&#10;…"></textarea>
    <div class="notiz-foot">
        <span class="notiz-status" id="mkfb-notiz-status">Wird geladen…</span>
        <a href="/film-einordnen.php" class="notiz-link"><i class="bi bi-arrow-right-circle me-1"></i>Filme einordnen</a>
    </div>
</div>

<script>
(function () {
    const btn    = document.getElementById('mkfb-notiz-btn');
    const panel  = document.getElementById('mkfb-notiz-panel');
    const ta     = document.getElementById('mkfb-notiz-ta');
    const status = document.getElementById('mkfb-notiz-status');
    const badge  = document.getElementById('mkfb-notiz-badge');
    const CSRF   = <?= json_encode(csrfToken()) ?>;

    let saveTimer = null;
    let loaded    = false;

    function updateBadge() {
        badge.style.display = ta.value.trim() ? '' : 'none';
    }

    function setStatus(msg) { status.textContent = msg; }

    function save() {
        const fd = new FormData();
        fd.append('action',     'save');
        fd.append('csrf_token', CSRF);
        fd.append('content',    ta.value);
        setStatus('Speichern…');
        fetch('/api-notiz.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { setStatus(d.ok ? 'Gespeichert' : 'Fehler'); updateBadge(); })
            .catch(() => setStatus('Fehler'));
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 900);
    }

    function load() {
        setStatus('Wird geladen…');
        fetch('/api-notiz.php?action=load')
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    ta.value = d.content;
                    setStatus(d.content ? 'Geladen' : 'Leer');
                    updateBadge();
                    loaded = true;
                }
            })
            .catch(() => setStatus('Ladefehler'));
    }

    btn.addEventListener('click', () => {
        const open = panel.classList.toggle('open');
        if (open && !loaded) load();
    });

    document.getElementById('mkfb-notiz-close').addEventListener('click', () => {
        panel.classList.remove('open');
    });

    document.getElementById('mkfb-notiz-clear').addEventListener('click', () => {
        if (!ta.value.trim() || confirm('Notizen löschen?')) {
            ta.value = '';
            updateBadge();
            save();
        }
    });

    ta.addEventListener('input', scheduleSave);

    // Pfeiltasten im Textarea nicht an Bewertungs-Seiten durchreichen
    ta.addEventListener('keydown', e => {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' ||
            e.key === 'ArrowUp'   || e.key === 'ArrowDown') {
            e.stopPropagation();
        }
    });

    // Badge beim Laden der Seite ohne Panel öffnen prüfen
    fetch('/api-notiz.php?action=load')
        .then(r => r.json())
        .then(d => { if (d.ok && d.content.trim()) { badge.style.display = ''; loaded = false; ta.value = d.content; } });
})();
</script>
<?php endif; ?>

<script>
(function() {
    const trigger = document.getElementById('footer-trigger');
    const footer  = document.querySelector('.mkfb-footer');
    if (!trigger || !footer) return;
    let hideTimer;
    function show() { clearTimeout(hideTimer); footer.classList.add('footer-visible'); }
    function hide() { hideTimer = setTimeout(() => footer.classList.remove('footer-visible'), 400); }
    trigger.addEventListener('mouseenter', show);
    trigger.addEventListener('mouseleave', hide);
    footer.addEventListener('mouseenter', show);
    footer.addEventListener('mouseleave', hide);
})();
</script>

<!-- ── Filmhandlung-Tooltip ─────────────────────────────────────────────── -->
<style>
#mkfb-overview-tip {
    position: fixed;
    z-index: 9999;
    max-width: 320px;
    background: rgba(10,25,47,.97);
    border: 1px solid rgba(232,184,75,.35);
    border-radius: 8px;
    padding: 12px 14px;
    color: rgba(255,255,255,.88);
    font-size: .82rem;
    line-height: 1.55;
    pointer-events: none;
    box-shadow: 0 8px 32px rgba(0,0,0,.6);
    display: none;
    transition: opacity .12s;
}
#mkfb-overview-tip .tip-title {
    font-weight: 700;
    color: #e8b84b;
    margin-bottom: 5px;
    font-size: .875rem;
}
</style>

<div id="mkfb-overview-tip">
    <div class="tip-title" id="mkfb-tip-title"></div>
    <div id="mkfb-tip-body"></div>
</div>

<script>
(function () {
    // Kein Tooltip auf Touch-Geräten (Handy / Tablet)
    if (window.matchMedia('(hover: none)').matches) return;

    const tip   = document.getElementById('mkfb-overview-tip');
    const title = document.getElementById('mkfb-tip-title');
    const body  = document.getElementById('mkfb-tip-body');
    let showTimer = null;

    function findOverview(el) {
        let node = el;
        for (let i = 0; i < 6; i++) {
            if (!node) break;
            if (node.dataset && node.dataset.overview) return node;
            node = node.parentElement;
        }
        return null;
    }

    document.addEventListener('mouseover', function (e) {
        const src = findOverview(e.target);
        if (!src) return;
        const text = src.dataset.overview;
        if (!text || text.length < 5) return;
        clearTimeout(showTimer);
        showTimer = setTimeout(function () {
            title.textContent = src.dataset.overviewTitle || '';
            body.textContent  = text;
            tip.style.display = 'block';
        }, 300);
    });

    document.addEventListener('mouseout', function (e) {
        const src = findOverview(e.target);
        if (!src) return;
        clearTimeout(showTimer);
        tip.style.display = 'none';
    });

    document.addEventListener('mousemove', function (e) {
        if (tip.style.display === 'none') return;
        const vw = window.innerWidth, vh = window.innerHeight;
        const tw = tip.offsetWidth + 20, th = tip.offsetHeight + 20;
        let x = e.clientX + 16, y = e.clientY + 16;
        if (x + tw > vw) x = e.clientX - tw + 4;
        if (y + th > vh) y = e.clientY - th + 4;
        tip.style.left = x + 'px';
        tip.style.top  = y + 'px';
    });
})();
</script>
</body>
</html>
