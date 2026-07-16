{{-- Shared client-side table tools for report tables: text filter + click-to-
     sort headers (numeric-aware, "—"/comma/% tolerant). Window-guarded so it
     binds once no matter how many tables render. Event delegation → works for
     every current and future [data-rpt-filter] input / th[data-sort]. --}}
<script>
if (!window.__rptTables) { window.__rptTables = true;
    // One visibility pass per table: text filter × group mode ("all" vs
    // "one per domain" — dup rows are marked lazily from data-domain, row
    // order is rank-sorted so first-per-domain ≡ the old grouped sample).
    function rptApply(id) {
        var box = document.getElementById(id);
        if (!box) return;
        var input = document.querySelector('[data-rpt-filter="' + id + '"]');
        var q = (input ? input.value : '').toLowerCase();
        var grouped = box.getAttribute('data-rpt-mode') === 'one';
        if (grouped && !box.__dupMarked) {
            var seen = {};
            box.querySelectorAll('tbody tr').forEach(function (tr) {
                var d = tr.getAttribute('data-domain') || '';
                if (d !== '' && seen[d]) tr.setAttribute('data-dup', '1');
                seen[d] = true;
            });
            box.__dupMarked = true;
        }
        var shown = 0;
        box.querySelectorAll('tbody tr').forEach(function (tr) {
            // data-search carries the FULL row text (untruncated anchors/URLs)
            // so matches work even when the visible cell is clipped.
            var hay = (tr.getAttribute('data-search') || tr.textContent).toLowerCase();
            var hit = (q === '' || hay.indexOf(q) > -1) && !(grouped && tr.getAttribute('data-dup'));
            tr.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        var badge = document.querySelector('[data-rpt-count="' + id + '"]');
        if (badge) badge.textContent = (q === '' && !grouped) ? badge.getAttribute('data-total') : shown.toLocaleString();
        var empty = document.querySelector('[data-rpt-empty="' + id + '"]');
        if (empty) {
            empty.classList.toggle('hidden', !(q !== '' && shown === 0));
            // Stale drill-down results from a previous query → reset the area.
            if (empty.getAttribute('data-results-for') !== q) {
                empty.removeAttribute('data-results-for');
                var out = empty.querySelector('[data-anchor-results]');
                if (out) out.innerHTML = '';
                var b = empty.querySelector('[data-anchor-fetch]');
                if (b) {
                    b.classList.remove('hidden');
                    b.disabled = false;
                    if (b.getAttribute('data-label')) b.textContent = b.getAttribute('data-label');
                }
            }
            // AUTO drill-down — only for exact anchors set by an anchor click
            // (never for free-typed queries: each index fetch is a paid call).
            if (q !== '' && shown === 0 && input && input.getAttribute('data-exact') === input.value) {
                var ab = empty.querySelector('[data-anchor-fetch]');
                if (ab && !ab.disabled && !ab.classList.contains('hidden') && empty.getAttribute('data-auto-for') !== q) {
                    empty.setAttribute('data-auto-for', q);
                    ab.click();
                }
            }
        }
    }
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.matches || !el.matches('[data-rpt-filter]')) return;
        // Real typing invalidates the "exact anchor" marker set by an anchor
        // click — free-form queries must never auto-trigger paid fetches.
        if (e.isTrusted) el.removeAttribute('data-exact');
        rptApply(el.getAttribute('data-rpt-filter'));
    });
    // Group-mode toggle buttons.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-rpt-group]');
        if (!btn) return;
        var id = btn.getAttribute('data-rpt-group');
        var box = document.getElementById(id);
        if (!box) return;
        box.setAttribute('data-rpt-mode', btn.getAttribute('data-mode'));
        document.querySelectorAll('[data-rpt-group="' + id + '"]').forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('bg-white', on); b.classList.toggle('shadow-sm', on);
            b.classList.toggle('text-slate-900', on); b.classList.toggle('text-slate-500', !on);
        });
        rptApply(id);
    });
    // Anchor drill-down: when the local sample has no rows for the query,
    // fetch the actual links from the index (tiny cached API call) and
    // render them into the empty-state area.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-anchor-fetch]');
        if (!btn) return;
        var wrap = btn.closest('[data-rpt-empty]');
        var input = document.querySelector('[data-rpt-filter="' + btn.getAttribute('data-anchor-fetch') + '"]');
        var q = input ? input.value.trim() : '';
        if (q === '' || !wrap) return;
        if (!btn.getAttribute('data-label')) btn.setAttribute('data-label', btn.textContent.trim());
        wrap.setAttribute('data-results-for', q);
        btn.disabled = true;
        btn.textContent = btn.getAttribute('data-loading') || 'Fetching…';
        fetch(btn.getAttribute('data-endpoint') + '&anchor=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                var out = wrap.querySelector('[data-anchor-results]');
                if (!d || !out) { btn.textContent = btn.getAttribute('data-failed') || 'Nothing found in the index either'; return; }
                btn.classList.add('hidden');
                if (!d.rows.length) {
                    out.innerHTML = '<p class="mt-3 text-xs">' + (wrap.getAttribute('data-none-text') || 'The index has no live links for this exact anchor anymore.') + '</p>';
                    return;
                }
                var esc = function (s) { var el = document.createElement('div'); el.textContent = s; return el.innerHTML; };
                out.innerHTML = '<div class="mt-4 space-y-2 text-left">' + d.rows.map(function (r) {
                    return '<div class="rounded-lg bg-white px-3 py-2 text-xs shadow-sm ring-1 ring-slate-200">'
                        + '<a href="' + esc(r.url_from) + '" target="_blank" rel="nofollow noopener" class="break-all font-medium text-orange-600 hover:underline">' + esc(r.url_from) + '</a>'
                        + '<span class="ml-2 rounded-full px-1.5 py-0.5 text-[10px] font-medium ' + (r.dofollow ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500') + '">' + (r.dofollow ? 'dofollow' : 'nofollow') + '</span>'
                        + (r.url_to ? '<div class="mt-0.5 truncate text-slate-400">→ ' + esc(r.url_to) + '</div>' : '')
                        + '</div>';
                }).join('') + '</div>';
            })
            .catch(function () { btn.disabled = false; btn.textContent = btn.getAttribute('data-retry') || 'Try again'; });
    });
    // Click an anchor (in the Anchor texts table) → search the backlinks
    // table for it: fills the filter input, triggers it, scrolls there.
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('[data-anchor-search]');
        if (!el) return;
        var target = el.getAttribute('data-target');
        var input = document.querySelector('[data-rpt-filter="' + target + '"]');
        if (!input) return;
        input.value = el.getAttribute('data-anchor-search');
        // Marks the query as an exact known anchor → zero local matches may
        // AUTO-fetch this anchor's links from the index (see rptApply).
        input.setAttribute('data-exact', input.value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        var box = document.getElementById(target);
        (box || input).scrollIntoView({ behavior: 'smooth', block: 'start' });
        input.focus({ preventScroll: true });
    });
    document.addEventListener('click', function (e) {
        var th = e.target.closest && e.target.closest('th[data-sort]');
        if (!th) return;
        var table = th.closest('table');
        var tbody = table.querySelector('tbody');
        var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
        var dir = th.getAttribute('data-dir') === 'desc' ? 'asc' : 'desc';
        table.querySelectorAll('th[data-sort]').forEach(function (h) {
            h.removeAttribute('data-dir');
            var i = h.querySelector('.sort-ico'); if (i) i.textContent = '↕';
        });
        th.setAttribute('data-dir', dir);
        var ico = th.querySelector('.sort-ico'); if (ico) ico.textContent = dir === 'asc' ? '↑' : '↓';
        var num = function (t) {
            t = t.replace(/[,%\s]/g, '');
            if (t === '' || t === '—') return null;
            var v = parseFloat(t);
            return isNaN(v) ? null : v;
        };
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (a, b) {
            var ta = (a.children[idx] ? a.children[idx].textContent : '').trim();
            var tb = (b.children[idx] ? b.children[idx].textContent : '').trim();
            var na = num(ta), nb = num(tb), r;
            if (na !== null && nb !== null) r = na - nb;
            else if (na !== null) r = 1;         // numbers before "—"/text
            else if (nb !== null) r = -1;
            else r = ta.localeCompare(tb);
            return dir === 'asc' ? r : -r;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    });
}
</script>
