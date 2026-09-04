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
        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 20px 64px;
        }
        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 0 8px;
        }
        .sub {
            color: var(--muted);
            margin: 0 0 28px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        label {
            display: block;
            font-size: 0.875rem;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        input[type="url"] {
            flex: 1 1 280px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 1rem;
        }
        input[type="url"]:focus {
            outline: 2px solid var(--accent);
            border-color: transparent;
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
        button:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .status {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 8px;
            display: none;
            white-space: pre-wrap;
        }
        .status.show { display: block; }
        .status.loading {
            background: rgba(61, 139, 253, 0.15);
            color: #9ec5ff;
            border: 1px solid rgba(61, 139, 253, 0.35);
        }
        .status.error {
            background: rgba(255, 107, 107, 0.12);
            color: #ffb4b4;
            border: 1px solid rgba(255, 107, 107, 0.35);
        }
        .status.ok {
            background: rgba(81, 207, 102, 0.12);
            color: #b2f2bb;
            border: 1px solid rgba(81, 207, 102, 0.35);
        }
        .meta {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
        }
        .meta.show { display: block; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 14px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }
        .card img {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            background: #000;
        }
        .card .ts {
            padding: 8px 10px;
            font-size: 0.8rem;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
            text-align: center;
        }
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            vertical-align: -2px;
            margin-right: 8px;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="wrap">
    <h1>yHome AI — Frames</h1>
    <p class="sub">Paste a YouTube URL to process, or open a saved job with <code>?id=...</code>.</p>

    <form class="panel" id="form" autocomplete="off">
        <label for="url">YouTube video URL</label>
        <div class="row">
            <input type="url" id="url" name="url" required
                   placeholder="https://www.youtube.com/watch?v=..."
                   inputmode="url">
            <button type="submit" id="btn">Process Video</button>
        </div>
        <div class="status" id="status" role="status" aria-live="polite"></div>
    </form>

    <div class="meta" id="meta"></div>
    <div class="grid" id="grid"></div>
</div>

<script>
(function () {
    const form = document.getElementById('form');
    const urlInput = document.getElementById('url');
    const btn = document.getElementById('btn');
    const status = document.getElementById('status');
    const meta = document.getElementById('meta');
    const grid = document.getElementById('grid');

    function setStatus(kind, text) {
        status.className = 'status show ' + kind;
        status.innerHTML = kind === 'loading'
            ? '<span class="spinner" aria-hidden="true"></span>' + text
            : text;
    }

    function clearResults() {
        meta.className = 'meta';
        meta.textContent = '';
        grid.innerHTML = '';
    }

    function formatSec(sec) {
        const s = Math.max(0, Math.floor(Number(sec) || 0));
        const m = Math.floor(s / 60);
        const r = s % 60;
        return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
    }

    function setJobUrl(jobId) {
        if (!jobId) return;
        const next = '/yhome_ai.php?id=' + encodeURIComponent(jobId);
        if (window.location.pathname + window.location.search !== next) {
            history.replaceState(null, '', next);
        }
    }

    function renderJob(data) {
        const frames = Array.isArray(data.frames) ? data.frames : [];
        setStatus('ok', 'Showing ' + frames.length + ' frames'
            + (data.duration_sec ? ' from a ' + formatSec(data.duration_sec) + ' video' : '')
            + '.');
        meta.className = 'meta show';
        meta.textContent = (data.title ? data.title + ' · ' : '')
            + 'Job: ' + (data.job_id || '—')
            + (data.video_id ? ' · Video ID: ' + data.video_id : '')
            + (data.duration_sec ? ' · Duration: ' + formatSec(data.duration_sec) : '')
            + ' · Frames: ' + frames.length;

        grid.innerHTML = '';
        frames.forEach(function (f) {
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

    async function loadJob(jobId) {
        clearResults();
        setStatus('loading', 'Loading saved frames…');
        const res = await fetch('/api/yhome_ai_job.php?id=' + encodeURIComponent(jobId), {
            headers: { 'Accept': 'application/json' },
        });
        const data = await res.json().catch(function () {
            return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
        });
        if (!res.ok || !data.ok) {
            throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
        }
        setJobUrl(data.job_id || jobId);
        renderJob(data);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const url = urlInput.value.trim();
        if (!url) return;

        clearResults();
        btn.disabled = true;
        setStatus('loading', 'Downloading video and extracting frames… this can take a minute.');

        try {
            const res = await fetch('/api/process_yhome_ai.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ url }),
            });
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
            });

            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
            }

            setJobUrl(data.job_id);
            renderJob(data);
        } catch (err) {
            setStatus('error', err.message || String(err));
        } finally {
            btn.disabled = false;
        }
    });

    const params = new URLSearchParams(window.location.search);
    const jobId = params.get('id');
    if (jobId) {
        loadJob(jobId).catch(function (err) {
            setStatus('error', err.message || String(err));
        });
    }
})();
</script>
</body>
</html>
