<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>yHome.ai — Text us about any home</title>
    <meta name="description" content="Text yHome.ai to ask about a listing — price, condition, schools, open house — and get quick answers by SMS.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .home-page { padding-bottom: 48px; }

        .home-hero {
            padding: 0 0 72px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.12), transparent 52%),
                radial-gradient(ellipse 80% 60% at 100% 10%, rgba(200, 167, 107, 0.1), transparent 48%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 70%, #edf6f1 100%);
        }

        .home-hero__inner { padding-top: 24px; }

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

        .home-hero__visual { min-width: 0; }

        .home-hero__visual img {
            display: block;
            width: 100%;
            height: clamp(240px, 42vw, 460px);
            object-fit: cover;
        }

        @media (min-width: 900px) {
            .home-hero__visual img { height: min(520px, 58vh); }
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
            gap: 14px;
        }

        .home-text-cta {
            display: inline-block;
            padding: 18px 24px;
            border: 2px solid var(--accent, #2e9d78);
            border-radius: 14px;
            background: #ebf8f1;
            color: var(--accent-dark, #176948);
            font-size: clamp(1.15rem, 2.4vw, 1.4rem);
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: -0.01em;
            text-decoration: none;
            box-shadow: 0 12px 28px rgba(46, 157, 120, 0.22);
        }

        .home-text-cta:hover { background: #dcf2e6; }
        .home-text-cta strong { white-space: nowrap; }

        .home-text-consent {
            margin: 0;
            max-width: 32rem;
            font-size: 0.82rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .home-hero__secondary {
            margin-top: 4px;
            font-weight: 700;
            color: var(--accent-dark);
            text-decoration: none;
        }

        .home-hero__secondary:hover { text-decoration: underline; }

        .home-section { padding: 72px 0; }

        .home-section--soft {
            background: linear-gradient(180deg, #f7fbf8 0%, #edf6f1 100%);
        }

        .home-section__inner { max-width: 40rem; }
        .home-section__inner--wide { max-width: 52rem; }

        .home-kicker {
            margin: 0 0 12px;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }

        .home-section h2,
        .home-value__copy h2 {
            margin: 0 0 16px;
            font-size: clamp(1.75rem, 3.6vw, 2.4rem);
            line-height: 1.15;
            letter-spacing: -0.035em;
            font-weight: 800;
        }

        .home-section p.lead,
        .home-value__copy .lead {
            margin: 0;
            font-size: clamp(1.02rem, 2vw, 1.12rem);
            color: var(--muted);
            line-height: 1.6;
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

        .home-value__media img {
            display: block;
            width: 100%;
            height: clamp(220px, 36vw, 360px);
            object-fit: cover;
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
            gap: 10px;
            min-width: 0;
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

        .home-step p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .home-final { text-align: left; max-width: 36rem; }

        .home-final .home-text-cta { margin-top: 24px; }

        .home-disclaimer { padding: 0 0 40px; }

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

        .home-footer-links a:hover { color: var(--accent-dark); }

        .home-reveal { animation: homeFadeUp 0.7s ease both; }
        .home-reveal--2 { animation-delay: 0.08s; }
        .home-reveal--3 { animation-delay: 0.16s; }

        @keyframes homeFadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .home-reveal { animation: none; }
        }
    </style>
</head>
<body>
<main class="home-page">
    <section class="home-hero">
        <div class="container home-hero__inner">
            <header class="site-header">
                <a class="site-logo" href="/">yHome.ai</a>
                <div class="site-header-actions">
                    <a href="/houses.php" class="button button-nav-cta">Browse homes</a>
                </div>
            </header>

            <div class="home-hero__grid">
                <div class="home-hero__copy">
                    <h1 class="home-hero__title home-reveal">Text us about any home.</h1>
                    <p class="home-hero__sub home-reveal home-reveal--2">Ask about price, condition, schools, or open house — get quick answers by SMS. No app. No signup.</p>
                    <div class="home-hero__actions home-reveal home-reveal--3">
                        <a class="home-text-cta" href="sms:+16263238836">Text <strong>+1 (626) 323-8836</strong></a>
                        <p class="home-text-consent">By texting, you agree to receive SMS replies from yhome.ai. Msg &amp; data rates may apply. Reply STOP to opt out.</p>
                        <a class="home-hero__secondary" href="/houses.php">Or browse Arcadia listings</a>
                    </div>
                </div>
                <div class="home-hero__visual home-reveal home-reveal--2">
                    <img
                        src="https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg?auto=compress&cs=tinysrgb&w=1400"
                        alt="Buyer looking at a home"
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
                    <p class="home-kicker">Why text yHome</p>
                    <h2>Answers about the home you’re looking at.</h2>
                    <p class="lead">Start with the street address. We pull listing facts we have, answer what we can, and flag what still needs the listing agent.</p>
                </div>
                <div class="home-value__media">
                    <img
                        src="https://images.pexels.com/photos/1080721/pexels-photo-1080721.jpeg?auto=compress&cs=tinysrgb&w=1200"
                        alt="Home exterior"
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
                        <span class="home-step__num">Step 1</span>
                        <h3>Text our number</h3>
                        <p>Say hi from your phone, or tap Text on any yHome listing page.</p>
                    </article>
                    <article class="home-step">
                        <span class="home-step__num">Step 2</span>
                        <h3>Send the address</h3>
                        <p>Tell us which home — for example, 323 Santa Rosa Rd.</p>
                    </article>
                    <article class="home-step">
                        <span class="home-step__num">Step 3</span>
                        <h3>Ask anything</h3>
                        <p>Price, beds, last sold, schools, open house — we reply by text.</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="home-section" id="get-started">
        <div class="container">
            <div class="home-final">
                <p class="home-kicker">Ready when you are</p>
                <h2>Looking at a home right now?</h2>
                <p class="lead">Text the address and your first question.</p>
                <a class="home-text-cta" href="sms:+16263238836">Text <strong>+1 (626) 323-8836</strong></a>
            </div>
        </div>
    </section>

    <section class="home-disclaimer">
        <div class="container">
            <p>yHome replies use listing data we have on file and general neighborhood knowledge. Answers are not a substitute for a professional inspection, appraisal, or advice from the listing agent. Message frequency varies.</p>
            <nav class="home-footer-links" aria-label="Legal">
                <a href="/privacy.php">Privacy</a>
                <a href="/terms.php">Terms</a>
            </nav>
        </div>
    </section>
</main>
</body>
</html>
