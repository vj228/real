<?php

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>yHome AI — Frames</title>
    <style>
        :root {
            --bg: #0f1419;
            --panel: #1a2332;
            --text: #f0f4f8;
            --muted: #8b9cb3;
            --accent: #3d8bfd;
            --accent-hover: #5a9fff;
            --error: #ff6b6b;
            --ok: #51cf66;
            --border: #2a3548;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
        }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 64px; }
        h1 { font-size: 1.75rem; font-weight: 700; margin: 0 0 8px; }
        .sub { color: var(--muted); margin: 0 0 28px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        label { display: block; font-size: 0.875rem; color: var(--muted); margin-bottom: 8px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        input[type="url"] {
            flex: 1 1 280px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 1rem;
        }
        button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover:not(:disabled) { background: var(--accent-hover); }
        button:disabled { opacity: 0.55; cursor: not-allowed; }
        button.secondary {
            background: transparent;
            color: var(--accent);
            border: 1px solid var(--accent);
        }
        button.secondary:hover:not(:disabled) { background: rgba(0,0,0,0.04); }
        .status {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 8px;
            display: none;
            white-space: pre-wrap;
        }
        .status.show { display: block; }
        .status.loading { background: rgba(61,139,253,.15); color: #9ec5ff; border: 1px solid rgba(61,139,253,.35); }
        .status.error { background: rgba(255,107,107,.12); color: #ffb4b4; border: 1px solid rgba(255,107,107,.35); }
        .status.ok { background: rgba(81,207,102,.12); color: #b2f2bb; border: 1px solid rgba(81,207,102,.35); }
        .meta { color: var(--muted); font-size: 0.9rem; margin-bottom: 16px; display: none; }
        .meta.show { display: block; }
        .listing {
            display: none; gap: 16px; align-items: stretch; margin-bottom: 24px;
        }
        .listing.show { display: flex; flex-wrap: wrap; }
        .listing-photo {
            flex: 0 0 220px; max-width: 100%;
            border-radius: 10px; overflow: hidden;
            border: 1px solid var(--border); background: #000;
            aspect-ratio: 4/3;
        }
        .listing-photo img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .listing-body { flex: 1 1 280px; min-width: 0; }
        .listing-body h2 { margin: 0 0 8px; font-size: 1.25rem; }
        .listing-body .price { font-size: 1.35rem; font-weight: 700; margin: 0 0 8px; color: var(--ok); }
        .listing-body .facts { color: var(--muted); margin: 0 0 10px; }
        .listing-body a { color: var(--accent); }
        .actions { display: none; margin-bottom: 20px; }
        .actions.show { display: block; }
        .analyze-log {
            display: none; margin-top: 14px; padding: 12px 14px;
            border-radius: 8px; border: 1px solid var(--border);
            background: var(--bg); font-size: 0.85rem; color: var(--muted);
            white-space: pre-wrap; max-height: 280px; overflow: auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            line-height: 1.45;
        }
        .analyze-log.show { display: block; }
        .analyze-log.loading { border-color: rgba(61,139,253,.35); color: #9ec5ff; }
        .analyze-log.ok { border-color: rgba(81,207,102,.35); color: #b2f2bb; }
        .analyze-log.error { border-color: rgba(255,107,107,.35); color: #ffb4b4; }
        .estimate { display: none; margin-bottom: 24px; }
        .estimate.show { display: block; }
        .estimate h2 { margin: 0 0 12px; font-size: 1.2rem; }
        .estimate-summary { margin: 0 0 18px; }
        .estimate-summary .overall {
            font-size: 1.35rem; font-weight: 700; margin: 0 0 12px;
        }
        .estimate-summary .budget-row {
            display: flex; flex-wrap: wrap; gap: 6px 16px;
            justify-content: space-between; padding: 6px 0;
            border-top: 1px solid var(--border); font-size: 0.95rem;
        }
        .estimate-summary .budget-row:last-child { font-weight: 700; }
        .estimate-summary .budget-row span:last-child { color: var(--text); }
        .estimate-disclaimer {
            margin: 14px 0 0; font-size: 0.8rem; color: var(--muted); line-height: 1.4;
        }
        .room-block { border-top: 1px solid var(--border); padding: 16px 0; }
        .room-block:first-of-type { border-top: none; }
        .room-head {
            display: flex; flex-wrap: wrap; gap: 8px 16px;
            margin-bottom: 8px; align-items: baseline;
        }
        .room-head strong { text-transform: capitalize; font-size: 1.05rem; }
        .room-head .score { margin-left: auto; font-weight: 700; font-variant-numeric: tabular-nums; }
        .score-bar {
            height: 8px; border-radius: 999px; background: #243044;
            overflow: hidden; margin: 0 0 8px;
        }
        .score-bar > span { display: block; height: 100%; border-radius: 999px; }
        .score-bar.green > span { background: #51cf66; }
        .score-bar.amber > span { background: #fcc419; }
        .score-bar.orange > span { background: #fd7e14; }
        .score-bar.red > span { background: #ff6b6b; }
        .score-meta { color: var(--muted); font-size: 0.85rem; margin: 0 0 6px; }
        .room-note { color: var(--muted); font-size: 0.9rem; margin: 0 0 10px; }
        .room-warn {
            color: #ffd8a8; font-size: 0.85rem; margin: 0 0 10px;
            padding: 8px 10px; border-radius: 8px;
            background: rgba(253,126,20,.12); border: 1px solid rgba(253,126,20,.35);
        }
        .work-section { margin: 12px 0 0; }
        .work-section h3 {
            margin: 0 0 8px; font-size: 0.85rem; text-transform: uppercase;
            letter-spacing: .04em; color: var(--muted); font-weight: 600;
        }
        .work-item {
            display: grid; grid-template-columns: 1fr auto;
            gap: 4px 12px; padding: 8px 0;
            border-top: 1px solid rgba(42,53,72,.8);
            font-size: 0.9rem;
        }
        .work-item .pri {
            display: inline-block; font-size: 0.75rem; color: var(--muted);
            text-transform: capitalize; margin-left: 6px;
        }
        .work-item .reason { grid-column: 1 / -1; color: var(--muted); font-size: 0.82rem; }
        .work-item .cost { font-weight: 600; white-space: nowrap; }
        .room-budget {
            margin-top: 10px; font-weight: 600; font-size: 0.95rem;
        }
        .room-imgs { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 4px; }
        .room-imgs img {
            width: 110px; height: 72px; object-fit: cover;
            border-radius: 6px; border: 1px solid var(--border); background: #000;
            cursor: zoom-in;
        }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .card img {
            display: block; width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #000;
            cursor: zoom-in;
        }
        .card .ts { padding: 8px 10px; font-size: 0.8rem; color: var(--muted); text-align: center; }
        .spinner {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid currentColor; border-right-color: transparent;
            border-radius: 50%; vertical-align: -2px; margin-right: 8px;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .lightbox {
            display: none; position: fixed; inset: 0; z-index: 1000;
            background: rgba(0, 0, 0, .88);
            align-items: center; justify-content: center;
            padding: 24px; cursor: zoom-out;
        }
        .lightbox.show { display: flex; }
        .lightbox img {
            max-width: min(96vw, 1200px); max-height: 92vh;
            object-fit: contain; border-radius: 8px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, .5);
            cursor: default;
        }
        .lightbox-close {
            position: absolute; top: 16px; right: 20px;
            background: transparent; border: none; color: #fff;
            font-size: 1.75rem; line-height: 1; padding: 8px 12px;
            cursor: pointer; opacity: .85;
        }
        .lightbox-close:hover { opacity: 1; background: transparent; }
        .queue {
            display: none; margin-bottom: 24px;
        }
        .queue.show { display: block; }
        .queue h2 { margin: 0 0 12px; font-size: 1.15rem; }
        .queue-empty { color: var(--muted); margin: 0; font-size: 0.92rem; }
        .queue-item {
            border-top: 1px solid var(--border);
            padding: 12px 0;
            display: grid;
            gap: 8px;
        }
        .queue-item:first-of-type { border-top: none; padding-top: 0; }
        .queue-item__top {
            display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: baseline;
        }
        .queue-item__top strong { font-size: 0.98rem; }
        .queue-badge {
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; padding: 2px 8px; border-radius: 999px;
            background: rgba(61,139,253,.15); color: #9ec5ff;
        }
        .queue-badge.pending { background: rgba(252,196,25,.15); color: #ffe066; }
        .queue-badge.processing { background: rgba(61,139,253,.15); color: #9ec5ff; }
        .queue-badge.processed { background: rgba(81,207,102,.15); color: #b2f2bb; }
        .queue-badge.failed { background: rgba(255,107,107,.15); color: #ffb4b4; }
        .queue-item__meta { color: var(--muted); font-size: 0.85rem; margin: 0; word-break: break-word; }
        .queue-item__actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .queue-item__actions button {
            padding: 8px 14px; font-size: 0.88rem;
        }
        .queue-item__actions .btn-secondary {
            background: transparent; border: 1px solid var(--border); color: var(--text);
        }
        .queue-item__actions .btn-secondary:hover { border-color: var(--accent); color: var(--accent); }
    </style>
</head>
<body>
<div class="wrap">
    <h1>yHome AI — Analysis (admin)</h1>
    <p class="sub">Admin tool to process videos and generate estimates. Public landing page: <code>/house.php?id=</code>. Listing source: <code>zillow_sale_listings</code>.</p>

    <div class="listing panel" id="listing"></div>
    <div class="queue panel" id="queue">
        <h2>User tour submissions</h2>
        <div id="queueList"></div>
    </div>
    <form class="panel" id="form" autocomplete="off">
        <label for="url">YouTube video URL</label>
        <div class="row">
            <input type="url" id="url" name="url" required placeholder="https://www.youtube.com/watch?v=..." inputmode="url">
            <button type="submit" id="btn">Process Video</button>
        </div>
        <div class="status" id="status" role="status" aria-live="polite"></div>
    </form>
    <div class="meta" id="meta"></div>
    <div class="actions" id="actions">
        <button type="button" id="analyzeBtn">Analyze renovation</button>
        <button type="button" id="pushFramesBtn" class="secondary">Push frames to prod</button>
        <div class="analyze-log" id="analyzeLog" role="status" aria-live="polite"></div>
    </div>
    <div class="estimate panel" id="estimate"></div>
    <div class="grid" id="grid"></div>
</div>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Enlarged frame" hidden>
    <button type="button" class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
    <img id="lightboxImg" alt="">
</div>

<script>
(function () {
    const form = document.getElementById('form');
    const urlInput = document.getElementById('url');
    const btn = document.getElementById('btn');
    const analyzeBtn = document.getElementById('analyzeBtn');
    const pushFramesBtn = document.getElementById('pushFramesBtn');
    const status = document.getElementById('status');
    const listingEl = document.getElementById('listing');
    const queueEl = document.getElementById('queue');
    const queueList = document.getElementById('queueList');
    const meta = document.getElementById('meta');
    const actions = document.getElementById('actions');
    const analyzeLog = document.getElementById('analyzeLog');
    const estimate = document.getElementById('estimate');
    const grid = document.getElementById('grid');
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightboxImg');
    const lightboxClose = document.getElementById('lightboxClose');
    let currentJobId = null;
    let currentListingId = null;
    let activeSubmissionId = null;
    let activeSubmissionEmail = '';

    function setAnalyzeLog(kind, lines) {
        const text = Array.isArray(lines) ? lines.join('\n') : String(lines || '');
        if (!text) {
            analyzeLog.className = 'analyze-log';
            analyzeLog.textContent = '';
            return;
        }
        analyzeLog.className = 'analyze-log show ' + (kind || '');
        analyzeLog.textContent = text;
        analyzeLog.scrollTop = analyzeLog.scrollHeight;
    }

    function formatGeminiLog(data) {
        const lines = Array.isArray(data.log) ? data.log.slice() : [];
        if (data.model) {
            lines.unshift('Model: ' + data.model);
        }
        if (data.source === 'database') {
            lines.unshift('Loaded from ai_analyses' + (data.analysis_db_id ? ' id=' + data.analysis_db_id : ''));
        }
        if (data.gemini && data.gemini.raw_text) {
            lines.push('—— Gemini JSON ——');
            lines.push(data.gemini.raw_text);
        } else if (data.gemini && data.gemini.rooms_raw) {
            lines.push('—— Gemini rooms_raw ——');
            lines.push(JSON.stringify(data.gemini.rooms_raw, null, 2));
        }
        return lines;
    }

    function openLightbox(src, alt) {
        if (!src) return;
        lightboxImg.src = src;
        lightboxImg.alt = alt || '';
        lightbox.hidden = false;
        lightbox.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('show');
        lightbox.hidden = true;
        lightboxImg.removeAttribute('src');
        document.body.style.overflow = '';
    }

    lightboxClose.addEventListener('click', function (e) {
        e.stopPropagation();
        closeLightbox();
    });
    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox || e.target === lightboxImg) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && lightbox.classList.contains('show')) closeLightbox();
    });
    document.addEventListener('click', function (e) {
        const t = e.target;
        if (!(t instanceof HTMLImageElement)) return;
        if (!t.closest('.room-imgs') && !t.closest('.card') && !t.closest('.listing-photo')) return;
        e.preventDefault();
        openLightbox(t.currentSrc || t.src, t.alt);
    });

    function money(n) {
        if (n === null || n === undefined || n === '') return '—';
        return '$' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function setStatus(kind, text) {
        status.className = 'status show ' + kind;
        status.innerHTML = kind === 'loading'
            ? '<span class="spinner" aria-hidden="true"></span>' + text
            : text;
    }

    function clearResults() {
        listingEl.className = 'listing panel';
        listingEl.innerHTML = '';
        meta.className = 'meta';
        meta.textContent = '';
        actions.className = 'actions';
        setAnalyzeLog('', '');
        estimate.className = 'estimate panel';
        estimate.innerHTML = '';
        grid.innerHTML = '';
        currentJobId = null;
    }

    function formatSec(sec) {
        const s = Math.max(0, Math.floor(Number(sec) || 0));
        return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }

    function setListingUrl(listingId) {
        if (!listingId) return;
        const next = '/analysis.php?id=' + encodeURIComponent(String(listingId));
        if (window.location.pathname + window.location.search !== next) {
            history.replaceState(null, '', next);
        }
    }

    function renderListing(listing) {
        if (!listing) {
            listingEl.className = 'listing panel';
            listingEl.innerHTML = '';
            return;
        }
        const facts = [];
        if (listing.beds != null) facts.push(listing.beds + ' bd');
        if (listing.baths != null) facts.push(listing.baths + ' ba');
        if (listing.sqft != null) facts.push(Number(listing.sqft).toLocaleString('en-US') + ' sqft');
        const photo = listing.img_src
            ? '<div class="listing-photo"><img src="' + listing.img_src + '" alt="' + (listing.address || 'Listing') + '"></div>'
            : '';
        const zillow = listing.detail_url
            ? '<p><a href="' + listing.detail_url + '" target="_blank" rel="noopener">View on Zillow</a></p>'
            : '';
        const zest = listing.zestimate != null ? '<span style="color:var(--muted);font-size:0.9rem"> · Zestimate ' + money(listing.zestimate) + '</span>' : '';
        listingEl.innerHTML = photo
            + '<div class="listing-body">'
            + '<h2>' + (listing.address || 'Listing #' + listing.id) + '</h2>'
            + '<p class="price">' + money(listing.list_price) + zest + '</p>'
            + (facts.length ? '<p class="facts">' + facts.join(' · ') + '</p>' : '')
            + '<p class="facts">Listing id ' + listing.id
            + (listing.zpid ? ' · zpid ' + listing.zpid : '') + '</p>'
            + zillow
            + '</div>';
        listingEl.className = 'listing panel show';
    }

    function scoreTone(score) {
        const s = Number(score) || 0;
        if (s >= 80) return 'green';
        if (s >= 60) return 'amber';
        if (s >= 40) return 'orange';
        return 'red';
    }

    function renderWorkGroups(work) {
        const groups = { required: [], recommended: [], optional: [] };
        (work || []).forEach(function (w) {
            const p = w.priority || 'recommended';
            if (!groups[p]) groups[p] = [];
            groups[p].push(w);
        });
        let html = '';
        [
            ['required', 'Required repairs'],
            ['recommended', 'Recommended improvements'],
            ['optional', 'Optional modernization'],
        ].forEach(function (pair) {
            const key = pair[0];
            const label = pair[1];
            const items = groups[key] || [];
            html += '<div class="work-section"><h3>' + label + '</h3>';
            if (!items.length) {
                html += '<p class="room-note">' + (key === 'required'
                    ? 'No obvious required repairs visible'
                    : 'None suggested') + '</p>';
            } else {
                items.forEach(function (w) {
                    html += '<div class="work-item">'
                        + '<div><strong>' + (w.title || w.code || 'Work') + '</strong>'
                        + '<span class="pri">' + (w.priority || '') + '</span></div>'
                        + '<div class="cost">' + money(w.estimate_low) + ' – ' + money(w.estimate_high) + '</div>'
                        + (w.reason ? '<div class="reason">' + w.reason + '</div>' : '')
                        + '</div>';
                });
            }
            html += '</div>';
        });
        return html;
    }

    function renderAnalysis(a) {
        if (!a || !Array.isArray(a.rooms) || !a.rooms.length) {
            estimate.className = 'estimate panel';
            estimate.innerHTML = '';
            return;
        }

        // Legacy good/fair/poor rows still render basically
        const hasScores = a.rooms.some(function (r) {
            return r.condition_score != null || (r.recommended_work && r.recommended_work.length);
        });

        let blocks = '';
        a.rooms.forEach(function (r) {
            let imgs = '';
            (r.images || []).forEach(function (img) {
                imgs += '<img src="' + img.url + '" alt="' + (r.room || '') + '" loading="lazy">';
            });

            if (!hasScores && r.condition) {
                blocks += '<div class="room-block">'
                    + '<div class="room-head"><strong>' + r.room + '</strong>'
                    + '<span class="score">' + r.condition + '</span></div>'
                    + '<p class="room-note">' + (r.note || r.summary || '—') + '</p>'
                    + (imgs ? '<div class="room-imgs">' + imgs + '</div>' : '')
                    + '<p class="room-budget">Room budget: ' + money(r.estimate_low) + ' – ' + money(r.estimate_high) + '</p>'
                    + '</div>';
                return;
            }

            const score = Number(r.condition_score != null ? r.condition_score : 0);
            const tone = scoreTone(score);
            const conf = Number(r.confidence != null ? r.confidence : 1);
            blocks += '<div class="room-block">'
                + '<div class="room-head"><strong>' + r.room + '</strong>'
                + '<span class="score">' + score + '/100'
                + (r.condition_label ? ' · ' + r.condition_label : '')
                + '</span></div>'
                + '<div class="score-bar ' + tone + '" aria-hidden="true"><span style="width:' + Math.max(0, Math.min(100, score)) + '%"></span></div>'
                + '<p class="score-meta">Visible condition</p>'
                + '<p class="room-note">' + (r.summary || r.note || '—') + '</p>'
                + (conf < 0.6 ? '<p class="room-warn">Limited visual information — estimate may be less accurate.</p>' : '')
                + (imgs ? '<div class="room-imgs">' + imgs + '</div>' : '<p class="room-note">No images mapped</p>')
                + renderWorkGroups(r.recommended_work || [])
                + '<p class="room-budget">Estimated ' + (r.room || 'room') + ' improvement budget: '
                + money(r.estimate_low) + ' – ' + money(r.estimate_high) + '</p>'
                + '</div>';
        });

        const overall = (hasScores && a.overall_score != null) ? Number(a.overall_score) : null;
        const summaryHtml = overall != null
            ? ('<div class="estimate-summary">'
                + '<p class="overall">Overall Visible Condition · ' + overall + '/100'
                + (a.overall_label ? ' (' + a.overall_label + ')' : '') + '</p>'
                + '<div class="budget-row"><span>Required Repairs</span><span>'
                + money(a.required_low) + ' – ' + money(a.required_high) + '</span></div>'
                + '<div class="budget-row"><span>Recommended Improvements</span><span>'
                + money(a.recommended_low) + ' – ' + money(a.recommended_high) + '</span></div>'
                + '<div class="budget-row"><span>Optional Modernization</span><span>'
                + money(a.optional_low) + ' – ' + money(a.optional_high) + '</span></div>'
                + '<div class="budget-row"><span>Potential Total Budget</span><span>'
                + money(a.total_low) + ' – ' + money(a.total_high) + '</span></div>'
                + '</div>')
            : ('<p class="total">' + money(a.total_low) + ' – ' + money(a.total_high) + '</p>');

        const disclaimer = a.disclaimer
            || 'AI estimate based on visible conditions in the provided images. Hidden plumbing, electrical, structural, HVAC, roofing, moisture, mold, foundation and other concealed conditions are not included. Actual contractor pricing may vary.';

        estimate.innerHTML = '<h2>Renovation estimate</h2>'
            + summaryHtml
            + blocks
            + '<p class="estimate-disclaimer">' + disclaimer + '</p>';
        estimate.className = 'estimate panel show';
    }

    function renderFrames(frames) {
        grid.innerHTML = '';
        (frames || []).forEach(function (f) {
            const card = document.createElement('div');
            card.className = 'card';
            const img = document.createElement('img');
            img.src = f.url;
            img.alt = 'Frame at ' + formatSec(f.time_sec);
            img.loading = 'lazy';
            const ts = document.createElement('div');
            ts.className = 'ts';
            ts.textContent = formatSec(f.time_sec);
            card.appendChild(img);
            card.appendChild(ts);
            grid.appendChild(card);
        });
    }

    function renderListingPage(data) {
        currentListingId = data.listing_id || (data.listing && data.listing.id) || null;
        currentJobId = data.job_id || null;
        activeSubmissionId = null;
        activeSubmissionEmail = '';
        renderListing(data.listing);
        loadQueue(currentListingId);
        const frames = Array.isArray(data.frames) ? data.frames : [];
        const a = data.analysis;
        if (a) {
            setStatus('ok', 'Loaded listing #' + currentListingId + ' from database'
                + (a.images_used ? ' · ' + a.images_used + ' images analyzed' : '') + '.');
            meta.className = 'meta show';
            meta.textContent = (a.video_title ? a.video_title + ' · ' : '')
                + (a.youtube_url ? a.youtube_url + ' · ' : '')
                + (a.model ? 'Model: ' + a.model + ' · ' : '')
                + (a.analyzed_at ? 'Analyzed: ' + a.analyzed_at : '');
            setAnalyzeLog('ok', formatGeminiLog(a));
            renderAnalysis(a);
        } else {
            setStatus('ok', 'Listing #' + currentListingId + ' loaded. No AI analysis saved yet.');
            meta.className = 'meta';
            meta.textContent = '';
            setAnalyzeLog('', '');
            estimate.className = 'estimate panel';
            estimate.innerHTML = '';
        }
        actions.className = currentJobId ? 'actions show' : 'actions';
        renderFrames(frames);
        setListingUrl(currentListingId);
    }

    async function loadQueue(listingId) {
        if (!listingId) {
            queueEl.className = 'queue panel';
            queueList.innerHTML = '';
            return;
        }
        try {
            const res = await fetch('/api/tour_submissions.php?listing_id=' + encodeURIComponent(listingId));
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid queue response' };
            });
            if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to load submissions');
            renderQueue(data.submissions || []);
        } catch (err) {
            queueEl.className = 'queue panel show';
            queueList.innerHTML = '<p class="queue-empty">' + (err.message || String(err)) + '</p>';
        }
    }

    function renderQueue(items) {
        queueEl.className = 'queue panel show';
        if (!items.length) {
            queueList.innerHTML = '<p class="queue-empty">No user submissions yet for this listing.</p>';
            return;
        }
        let html = '';
        items.forEach(function (s) {
            const label = s.source === 'upload'
                ? ('File · ' + (s.original_filename || 'video'))
                : ('YouTube · ' + (s.youtube_url || ''));
            const canProcess = s.status === 'pending' || s.status === 'failed';
            html += '<div class="queue-item" data-id="' + s.id + '">'
                + '<div class="queue-item__top">'
                + '<strong>#' + s.id + ' · ' + (s.contact_email || '—') + '</strong>'
                + '<span class="queue-badge ' + (s.status || '') + '">' + (s.status || '') + '</span>'
                + '</div>'
                + '<p class="queue-item__meta">' + label
                + (s.created_at ? ' · ' + s.created_at : '')
                + (s.job_id ? ' · job ' + s.job_id : '')
                + (s.error_message ? ' · ' + s.error_message : '')
                + '</p>'
                + '<div class="queue-item__actions">';
            if (canProcess) {
                html += '<button type="button" data-process-sub="' + s.id + '">Process Video</button>';
            }
            if (s.source === 'youtube' && s.youtube_url) {
                html += '<button type="button" class="btn-secondary" data-fill-url="' + encodeURIComponent(s.youtube_url) + '">Fill URL</button>';
            }
            if (s.job_id && s.status !== 'pending') {
                html += '<button type="button" class="btn-secondary" data-use-job="' + encodeURIComponent(s.job_id) + '">Use job for Analyze</button>';
            }
            html += '</div></div>';
        });
        queueList.innerHTML = html;
    }

    queueList.addEventListener('click', async function (e) {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.hasAttribute('data-fill-url')) {
            urlInput.value = decodeURIComponent(t.getAttribute('data-fill-url') || '');
            urlInput.focus();
            return;
        }
        if (t.hasAttribute('data-use-job')) {
            currentJobId = decodeURIComponent(t.getAttribute('data-use-job') || '');
            actions.className = currentJobId ? 'actions show' : 'actions';
            setStatus('ok', 'Using job ' + currentJobId + '. Click Analyze renovation.');
            return;
        }
        if (t.hasAttribute('data-process-sub')) {
            const sid = parseInt(t.getAttribute('data-process-sub') || '0', 10);
            if (!sid || !currentListingId) return;
            t.disabled = true;
            setStatus('loading', 'Processing user submission #' + sid + '…');
            try {
                const res = await fetch('/api/process_yhome_ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ submission_id: sid, listing_id: currentListingId }),
                });
                const data = await res.json().catch(function () {
                    return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
                });
                if (!res.ok || !data.ok) throw new Error(data.error || ('Process failed (HTTP ' + res.status + ')'));
                currentJobId = data.job_id || null;
                activeSubmissionId = sid;
                activeSubmissionEmail = data.email || '';
                actions.className = currentJobId ? 'actions show' : 'actions';
                renderFrames(data.frames || []);
                setStatus('ok', 'Frames ready from submission #' + sid
                    + (data.email ? ' · email ' + data.email : '')
                    + '. Click Analyze renovation.');
                await loadQueue(currentListingId);
            } catch (err) {
                setStatus('error', err.message || String(err));
                await loadQueue(currentListingId);
            } finally {
                t.disabled = false;
            }
        }
    });

    async function loadListing(listingId) {
        clearResults();
        setStatus('loading', 'Loading listing and analysis from database…');
        const res = await fetch('/api/listing_analysis.php?id=' + encodeURIComponent(listingId));
        const data = await res.json().catch(function () {
            return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
        });
        if (!res.ok || !data.ok) throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
        renderListingPage(data);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const url = urlInput.value.trim();
        if (!url) return;
        if (!currentListingId) {
            setStatus('error', 'Open a listing first with ?id= (zillow_sale_listings id), then process a video.');
            return;
        }
        btn.disabled = true;
        setStatus('loading', 'Downloading video and extracting frames… this can take a minute.');
        try {
            const res = await fetch('/api/process_yhome_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ url, listing_id: currentListingId }),
            });
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
            });
            if (!res.ok || !data.ok) throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
            currentJobId = data.job_id || null;
            actions.className = currentJobId ? 'actions show' : 'actions';
            renderFrames(data.frames || []);
            setStatus('ok', 'Frames ready. Click Analyze renovation to save results for listing #' + currentListingId + '.');
            setListingUrl(currentListingId);
        } catch (err) {
            setStatus('error', err.message || String(err));
        } finally {
            btn.disabled = false;
        }
    });

    analyzeBtn.addEventListener('click', async function () {
        if (!currentJobId) return;
        analyzeBtn.disabled = true;
        setStatus('loading', 'Analyzing rooms with Gemini…');
        setAnalyzeLog('loading', [
            'Locally scanning all frames for house interiors…',
            'Rejecting exterior / blank frames, then sending keepers (downscaled) to Gemini…',
            'Saving results to ai_analyses for listing #' + (currentListingId || '?') + '…',
        ]);
        try {
            const payload = { id: currentJobId };
            if (currentListingId) payload.listing_id = currentListingId;
            const res = await fetch('/api/analyze_yhome_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
            });
            if (!res.ok || !data.ok) {
                setAnalyzeLog('error', (data.log || []).concat([data.error || ('Request failed (HTTP ' + res.status + ')')]));
                throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
            }
            setStatus('ok', 'Analysis saved'
                + (data.analysis_db_id ? ' (ai_analyses id=' + data.analysis_db_id + ')' : '')
                + ' using ' + (data.images_used || '?') + ' frames.');
            setAnalyzeLog('ok', formatGeminiLog(data));
            const sync = data.prod_frames_sync || null;
            if (sync && sync.skipped) {
                setStatus('ok', status.textContent + ' Frames already on this host.');
            } else if (sync && sync.ok) {
                setStatus('ok', status.textContent + ' Pushed ' + (sync.uploaded || 0) + ' frames to prod.');
            } else if (sync && sync.error) {
                setStatus('error', 'Analysis saved, but frame upload failed: ' + sync.error);
            }
            renderAnalysis(data);
            activeSubmissionId = null;
            activeSubmissionEmail = '';
            if (currentListingId) {
                setListingUrl(currentListingId);
                loadQueue(currentListingId);
            }
        } catch (err) {
            setStatus('error', err.message || String(err));
            if (!analyzeLog.classList.contains('error')) {
                setAnalyzeLog('error', err.message || String(err));
            }
        } finally {
            analyzeBtn.disabled = false;
        }
    });

    pushFramesBtn.addEventListener('click', async function () {
        if (!currentJobId) return;
        pushFramesBtn.disabled = true;
        setStatus('loading', 'Uploading selected frames to production…');
        try {
            const res = await fetch('/api/push_job_frames.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ id: currentJobId }),
            });
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
            });
            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
            }
            const sync = data.prod_frames_sync || {};
            setStatus('ok', 'Pushed ' + (sync.uploaded || 0) + ' frames to production for job ' + currentJobId + '.');
        } catch (err) {
            setStatus('error', err.message || String(err));
        } finally {
            pushFramesBtn.disabled = false;
        }
    });

    const rawId = new URLSearchParams(window.location.search).get('id');
    if (rawId && /^\d+$/.test(rawId)) {
        loadListing(rawId).catch(function (err) {
            setStatus('error', err.message || String(err));
        });
    } else if (rawId) {
        setStatus('error', 'Use a numeric listing id from zillow_sale_listings, e.g. ?id=1');
    }
})();
</script>
</body>
</html>
