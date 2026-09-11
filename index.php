<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers/marketing_track.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>yHome — Know the renovation cost before you make an offer</title>
    <meta name="description" content="Upload a house walkthrough video and get an AI-powered renovation cost estimate from yHome before you make an offer.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .home-page {
            padding-bottom: 48px;
        }

        .home-hero {
            padding: 0 0 72px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.12), transparent 52%),
                radial-gradient(ellipse 80% 60% at 100% 10%, rgba(200, 167, 107, 0.1), transparent 48%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 70%, #edf6f1 100%);
        }

        .home-hero__inner {
            padding-top: 24px;
        }

        .home-hero__grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 36px;
            align-items: center;
            margin-top: 8px;
        }

        @media (min-width: 900px) {
            .home-hero__grid {
                grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
                gap: 48px;
            }
        }

        .home-hero__copy {
            max-width: 38rem;
            padding-top: 4px;
        }

        .home-hero__visual {
            min-width: 0;
        }

        .home-hero__visual img {
            display: block;
            width: 100%;
            height: clamp(240px, 42vw, 460px);
            object-fit: cover;
            border-radius: 0;
            box-shadow: none;
        }

        @media (min-width: 900px) {
            .home-hero__visual img {
                height: min(520px, 58vh);
            }
        }

        .home-hero__title {
            margin: 0 0 16px;
            font-size: clamp(2rem, 5vw, 3.1rem);
            line-height: 1.12;
            letter-spacing: -0.04em;
            font-weight: 800;
        }

        .home-hero__sub {
            margin: 0 0 28px;
            font-size: clamp(1.05rem, 2.2vw, 1.2rem);
            color: var(--muted);
            line-height: 1.55;
            max-width: 34rem;
        }

        .home-hero__actions {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 16px;
        }

        .home-hero__actions .button {
            border-radius: 999px;
            padding: 18px 32px;
            font-size: 1.08rem;
            font-weight: 800;
            min-height: 56px;
            box-shadow: 0 16px 32px rgba(46, 157, 120, 0.28);
        }

        .home-section {
            padding: 72px 0;
        }

        .home-section--soft {
            background: linear-gradient(180deg, #f7fbf8 0%, #edf6f1 100%);
        }

        .home-section__inner {
            max-width: 40rem;
        }

        .home-section__inner--wide {
            max-width: 52rem;
        }

        .home-value {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 32px;
            align-items: center;
        }

        @media (min-width: 860px) {
            .home-value {
                grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
                gap: 48px;
            }
        }

        .home-value__copy h2 {
            margin: 0 0 16px;
            font-size: clamp(1.75rem, 3.6vw, 2.4rem);
            line-height: 1.15;
            letter-spacing: -0.035em;
            font-weight: 800;
        }

        .home-value__copy .lead {
            margin: 0;
            font-size: clamp(1.02rem, 2vw, 1.12rem);
            color: var(--muted);
            line-height: 1.6;
        }

        .home-value__media img {
            display: block;
            width: 100%;
            height: clamp(220px, 36vw, 360px);
            object-fit: cover;
        }

        .home-rooms {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 28px;
        }

        .home-rooms img {
            display: block;
            width: 100%;
            height: clamp(88px, 16vw, 140px);
            object-fit: cover;
        }

        .home-kicker {
            margin: 0 0 12px;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }

        .home-section h2 {
            margin: 0 0 16px;
            font-size: clamp(1.75rem, 3.6vw, 2.4rem);
            line-height: 1.15;
            letter-spacing: -0.035em;
            font-weight: 800;
        }

        .home-section p.lead {
            margin: 0;
            font-size: clamp(1.02rem, 2vw, 1.12rem);
            color: var(--muted);
            line-height: 1.6;
        }

        .home-steps {
            display: grid;
            grid-template-columns: 1fr;
            gap: 28px;
            margin-top: 40px;
        }

        @media (min-width: 760px) {
            .home-steps {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 32px;
            }
        }

        .home-step {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
        }

        .home-step__visual {
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            background: var(--surface-strong);
            border: none;
            border-radius: 0;
            padding: 0;
        }

        .home-step__visual img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .home-step__num {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }

        .home-step h3 {
            margin: 0;
            font-size: 1.2rem;
            letter-spacing: -0.02em;
            font-weight: 800;
            line-height: 1.25;
        }

        .home-example {
            margin-top: 36px;
            max-width: 28rem;
            display: grid;
            gap: 0;
        }

        .home-example__row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
            font-size: 1.02rem;
        }

        .home-example__row:first-child {
            border-top: 1px solid var(--border);
        }

        .home-example__row dt {
            margin: 0;
            color: var(--muted);
            font-weight: 600;
        }

        .home-example__row dd {
            margin: 0;
            font-weight: 800;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        .home-example__row--total {
            border-bottom: none;
            padding-top: 18px;
        }

        .home-example__row--total dt,
        .home-example__row--total dd {
            color: var(--text);
            font-size: 1.12rem;
        }

        .home-example__note {
            margin: 14px 0 0;
            font-size: 0.88rem;
            color: var(--muted);
        }

        .home-final {
            text-align: left;
            max-width: 36rem;
        }

        .home-final .button {
            margin-top: 24px;
            border-radius: 999px;
            padding: 18px 32px;
            font-size: 1.05rem;
            font-weight: 800;
            box-shadow: 0 14px 28px rgba(46, 157, 120, 0.25);
        }

        .home-disclaimer {
            padding: 0 0 40px;
        }

        .home-disclaimer p {
            margin: 0 auto;
            max-width: 40rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.55;
        }

        .home-footer-links {
            margin-top: 18px;
            text-align: center;
            font-size: 0.86rem;
        }

        .home-footer-links a {
            color: var(--muted);
            text-decoration: none;
            margin: 0 10px;
        }

        .home-footer-links a:hover {
            color: var(--accent-dark);
        }

        .home-reveal {
            animation: homeFadeUp 0.7s ease both;
        }

        .home-reveal--2 { animation-delay: 0.08s; }
        .home-reveal--3 { animation-delay: 0.16s; }

        @keyframes homeFadeUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .home-reveal {
                animation: none;
            }
        }
    </style>
</head>
<body>
<main class="home-page">
    <section class="home-hero">
        <div class="container home-hero__inner">
            <header class="site-header">
                <a class="site-logo" href="/">yHome</a>
                <div class="site-header-actions">
                    <a href="/houses.php" class="button button-nav-cta" data-cta-id="nav_upload_walkthrough">Upload Walkthrough</a>
                </div>
            </header>

            <div class="home-hero__grid">
                <div class="home-hero__copy">
                    <h1 class="home-hero__title home-reveal">Know the renovation cost before you make an offer.</h1>
                    <p class="home-hero__sub home-reveal home-reveal--2">Upload a house walkthrough video and get an AI-powered renovation cost estimate.</p>
                    <div class="home-hero__actions home-reveal home-reveal--3">
                        <a href="/houses.php" class="button button-primary" data-cta-id="hero_upload_walkthrough">Upload Walkthrough</a>
                    </div>
                </div>
                <div class="home-hero__visual home-reveal home-reveal--2">
                    <img
                        src="https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg?auto=compress&cs=tinysrgb&w=1400"
                        alt="Bright living room from a home walkthrough"
                        width="1400"
                        height="1050"
                        decoding="async"
                        fetchpriority="high"
                    >
                </div>
            </div>
        </div>
    </section>

    <section class="home-section">
        <div class="container">
            <div class="home-value">
                <div class="home-value__copy">
                    <p class="home-kicker">Why it matters</p>
                    <h2>See the real cost of the home.</h2>
                    <p class="lead">The asking price is only part of the cost. yhome helps you estimate potential kitchen, bathroom, flooring, paint, and other renovation expenses before you decide what to offer.</p>
                    <div class="home-rooms" aria-hidden="true">
                        <img src="https://images.pexels.com/photos/2724749/pexels-photo-2724749.jpeg?auto=compress&cs=tinysrgb&w=700" alt="" width="700" height="525" loading="lazy" decoding="async">
                        <img src="https://images.pexels.com/photos/1457842/pexels-photo-1457842.jpeg?auto=compress&cs=tinysrgb&w=700" alt="" width="700" height="525" loading="lazy" decoding="async">
                        <img src="https://images.pexels.com/photos/1454804/pexels-photo-1454804.jpeg?auto=compress&cs=tinysrgb&w=700" alt="" width="700" height="525" loading="lazy" decoding="async">
                    </div>
                </div>
                <div class="home-value__media">
                    <img
                        src="https://images.pexels.com/photos/1080721/pexels-photo-1080721.jpeg?auto=compress&cs=tinysrgb&w=1200"
                        alt="Kitchen interior that may need renovation"
                        width="1200"
                        height="900"
                        loading="lazy"
                        decoding="async"
                    >
                </div>
            </div>
        </div>
    </section>

    <section class="home-section home-section--soft" id="how-it-works">
        <div class="container">
            <div class="home-section__inner home-section__inner--wide">
                <p class="home-kicker">How it works</p>
                <h2>Three simple steps</h2>
                <div class="home-steps">
                    <article class="home-step">
                        <div class="home-step__visual">
                            <img src="https://images.pexels.com/photos/8293778/pexels-photo-8293778.jpeg?auto=compress&cs=tinysrgb&w=900" alt="Recording a home tour on a phone" width="900" height="675" loading="lazy" decoding="async">
                        </div>
                        <span class="home-step__num">Step 1</span>
                        <h3>Record the home</h3>
                    </article>
                    <article class="home-step">
                        <div class="home-step__visual">
                            <img src="https://images.pexels.com/photos/4050315/pexels-photo-4050315.jpeg?auto=compress&cs=tinysrgb&w=900" alt="Uploading a walkthrough video" width="900" height="675" loading="lazy" decoding="async">
                        </div>
                        <span class="home-step__num">Step 2</span>
                        <h3>Upload your walkthrough</h3>
                    </article>
                    <article class="home-step">
                        <div class="home-step__visual">
                            <img src="https://images.pexels.com/photos/8292886/pexels-photo-8292886.jpeg?auto=compress&cs=tinysrgb&w=900" alt="Reviewing renovation costs before an offer" width="900" height="675" loading="lazy" decoding="async">
                        </div>
                        <span class="home-step__num">Step 3</span>
                        <h3>Get your renovation estimate</h3>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="home-section">
        <div class="container">
            <div class="home-section__inner">
                <p class="home-kicker">Example</p>
                <h2>A $900K house may really be a $970K decision.</h2>
                <dl class="home-example">
                    <div class="home-example__row">
                        <dt>Asking Price</dt>
                        <dd>$900,000</dd>
                    </div>
                    <div class="home-example__row">
                        <dt>Estimated Renovation</dt>
                        <dd>$70,000</dd>
                    </div>
                    <div class="home-example__row home-example__row--total">
                        <dt>Potential All-in Cost</dt>
                        <dd>$970,000</dd>
                    </div>
                </dl>
                <p class="home-example__note">Illustrative example only — not a real property listing.</p>
            </div>
        </div>
    </section>

    <section class="home-section home-section--soft" id="get-estimate">
        <div class="container">
            <div class="home-final">
                <p class="home-kicker">Ready when you are</p>
                <h2>Touring a home?</h2>
                <p class="lead">Upload your walkthrough before you make an offer.</p>
                <a href="/houses.php" class="button button-primary" data-cta-id="final_get_renovation_estimate">Get Renovation Estimate</a>
            </div>
        </div>
    </section>

    <section class="home-disclaimer">
        <div class="container">
            <p>yHome provides preliminary AI estimates based on visible conditions in your walkthrough video. Estimates are not a substitute for a professional home inspection or contractor quote. Hidden issues and local pricing can differ substantially from what appears on camera.</p>
            <nav class="home-footer-links" aria-label="Legal">
                <a href="/privacy.php">Privacy</a>
                <a href="/terms.php">Terms</a>
            </nav>
        </div>
    </section>
</main>

<script>window.YHOME_MARKETING_VISIT_ID=<?= json_encode($GLOBALS['_marketing_visit_id'] ?? null) ?>;</script>
<script src="/js/cta_track.js" defer></script>
</body>
</html>
