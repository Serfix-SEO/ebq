/**
 * Snipping-tool-style region capture for the "Report a bug" modal.
 *
 * snip() shows a fullscreen crosshair overlay; the user drags a rectangle
 * (mouse or touch), Esc cancels. On release the page is rendered with
 * modern-screenshot (SVG foreignObject — the browser rasterizes, so
 * Tailwind 4's oklch() colors render correctly; html2canvas would crash
 * on them) and cropped to the selection.
 *
 * All overlay styling is inline on plain DOM nodes: no Tailwind classes
 * (nothing new to build) and the overlay is removed before capture.
 *
 * Resolves { dataUrl, viewport } or null when cancelled / selection tiny.
 */
export async function snip() {
    const rect = await selectRegion();
    if (!rect) return null;

    // Progress pill so the page never feels dead while rendering (1-3s on
    // heavy dashboards). Excluded from the capture via the filter below.
    const pill = el('div', {
        position: 'fixed', top: '16px', left: '50%', transform: 'translateX(-50%)',
        zIndex: '2147483001', background: '#0F172A', color: '#fff',
        padding: '6px 14px', borderRadius: '9999px', fontSize: '12px',
        fontFamily: 'Inter, Arial, sans-serif', boxShadow: '0 4px 12px rgba(0,0,0,0.25)',
    });
    pill.dataset.ebqCaptureIgnore = '1';
    pill.textContent = document.documentElement.lang === 'ar' ? 'جارٍ الالتقاط…' : 'Capturing…';
    document.body.appendChild(pill);

    try {
        return await capture(rect);
    } finally {
        pill.remove();
    }
}

async function capture(rect) {
    // Let the overlay removal + modal hide actually paint before rendering.
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    const { domToCanvas } = await import('modern-screenshot');
    const scale = Math.min(window.devicePixelRatio || 1, 2);
    const full = await domToCanvas(document.body, {
        scale,
        // Keep the progress pill (and anything else marked) out of the shot.
        filter: (node) => !(node instanceof Element && node.dataset?.ebqCaptureIgnore),
        // Alpine shorthand attributes (@click, :class, x-on:…) are INVALID
        // XML attribute names: XMLSerializer emits broken SVG and the whole
        // foreignObject render silently comes back transparent (which a JPEG
        // then shows as solid black). Strip them from every cloned node —
        // the clone is style-inlined already, so behavior attrs are dead
        // weight anyway.
        onCloneEachNode: (cloned) => {
            if (!(cloned instanceof Element)) return;
            for (const attr of [...cloned.attributes]) {
                if (/[@:]/.test(attr.name) && !/^xml/.test(attr.name)) {
                    cloned.removeAttribute(attr.name);
                }
            }
        },
    });

    // Crop: overlay coords are viewport-relative; the render is of the whole
    // document, so translate by the scroll offsets.
    const sx = (rect.x + window.scrollX) * scale;
    const sy = (rect.y + window.scrollY) * scale;
    const sw = rect.w * scale;
    const sh = rect.h * scale;

    const MAX_W = 1600;
    const outW = Math.min(sw, MAX_W);
    const outH = Math.round(sh * (outW / sw));

    const out = document.createElement('canvas');
    out.width = Math.max(1, Math.round(outW));
    out.height = Math.max(1, outH);
    out.getContext('2d').drawImage(full, sx, sy, sw, sh, 0, 0, out.width, out.height);

    return {
        dataUrl: out.toDataURL('image/jpeg', 0.85),
        viewport: `${window.innerWidth}x${window.innerHeight}@${window.devicePixelRatio || 1}`,
    };
}

function selectRegion() {
    return new Promise((resolve) => {
        const Z = 2147483000;
        const overlay = el('div', {
            position: 'fixed', inset: '0', zIndex: String(Z),
            cursor: 'crosshair', background: 'rgba(15,23,42,0.25)',
            touchAction: 'none', userSelect: 'none',
        });
        const marquee = el('div', {
            position: 'fixed', zIndex: String(Z + 1), display: 'none',
            border: '2px solid #F26419', background: 'rgba(242,100,25,0.10)',
            boxShadow: '0 0 0 100000px rgba(15,23,42,0.25)', pointerEvents: 'none',
        });
        // Once the marquee starts, its huge box-shadow provides the dimming.
        overlay.appendChild(marquee);
        document.body.appendChild(overlay);

        let startX = 0, startY = 0, dragging = false;

        const cleanup = (result) => {
            window.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(result);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') { e.stopPropagation(); cleanup(null); }
        };
        window.addEventListener('keydown', onKey, true);

        overlay.addEventListener('pointerdown', (e) => {
            dragging = true;
            startX = e.clientX; startY = e.clientY;
            overlay.setPointerCapture(e.pointerId);
            overlay.style.background = 'transparent';
            Object.assign(marquee.style, { display: 'block', left: `${startX}px`, top: `${startY}px`, width: '0px', height: '0px' });
        });
        overlay.addEventListener('pointermove', (e) => {
            if (!dragging) return;
            const x = Math.min(startX, e.clientX), y = Math.min(startY, e.clientY);
            const w = Math.abs(e.clientX - startX), h = Math.abs(e.clientY - startY);
            Object.assign(marquee.style, { left: `${x}px`, top: `${y}px`, width: `${w}px`, height: `${h}px` });
        });
        overlay.addEventListener('pointerup', (e) => {
            if (!dragging) return;
            const x = Math.min(startX, e.clientX), y = Math.min(startY, e.clientY);
            const w = Math.abs(e.clientX - startX), h = Math.abs(e.clientY - startY);
            cleanup(w < 8 || h < 8 ? null : { x, y, w, h });
        });
    });
}

function el(tag, styles) {
    const node = document.createElement(tag);
    Object.assign(node.style, styles);
    return node;
}
