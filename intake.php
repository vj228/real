<?php
declare(strict_types=1);
require_once __DIR__ . '/marketing_track.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>yHome Intake — Home Report</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="intake-page">
    <section class="intake-shell">
        <header class="intake-header">
            <a class="site-logo" href="/" data-cta-id="intake_logo_home">yHome</a>
            <p class="intake-rating">Built for 500,000+ yHome Homebuyers</p>
        </header>

        <div class="intake-progress" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span>
        </div>

        <section class="intake-intro-card">
            <img class="intake-hero-image" src="https://images.pexels.com/photos/7578860/pexels-photo-7578860.jpeg?auto=compress&cs=tinysrgb&w=1200" alt="Professional real estate agent meeting buyers outside a home" width="1200" height="720" decoding="async">
            <h1>Before You Make an Offer, Start Here</h1>
            <p>Enter the address and a few details. We’ll show you the real monthly cost, financial pressure, and whether this is a smart decision — before you commit.</p>
        </section>

        <section class="section section-soft intake-form-section" id="budget-form">
            <div class="form-layout intake-form-layout">
                <div class="form-card">
                    <form id="affordability-form" novalidate>
                        <div class="address-check" id="address-check">
                            <label>
                                <span>Home you’re considering</span>
                                <input type="text" name="propertyAddress" placeholder="Paste a Zillow, Redfin, or property address" required>
                                <small>Takes about 60 seconds • No credit check</small>
                            </label>
                            <button type="button" class="button button-primary button-full" id="start-report-button" data-cta-id="intake_address_start">Check Before You Offer</button>
                            <p class="form-message" id="address-message" aria-live="polite"></p>

                            <div class="value-preview">
                                <h3>What You’ll Know Before You Offer:</h3>
                                <ul>
                                    <li>Your true monthly cost (not just the mortgage)</li>
                                    <li>Whether this decision is comfortable or financially tight</li>
                                    <li>The risks most buyers miss before making an offer</li>
                                    <li>A clear “move forward or reconsider” signal</li>
                                </ul>
                                <p class="trust-copy">Every wrong home decision can cost you tens of thousands over time. This helps you avoid that.</p>
                            </div>
                        </div>

                        <div class="quiz-shell is-hidden" id="quiz-shell">
                            <div class="assessment-meta">
                                <span class="assessment-progress" id="assessment-progress">Step 1 of 3</span>
                                <div class="progress-bar" aria-hidden="true">
                                    <span class="progress-bar-fill" id="progress-bar-fill"></span>
                                </div>
                            </div>

                            <div class="assessment-step is-active" data-step="1">
                                <h3 class="step-heading">Tell us about your situation</h3>
                                <div class="form-grid">
                                    <label>
                                        <span>Annual income</span>
                                        <input type="number" name="annualIncome" min="0" step="1000" placeholder="e.g. 120000" required>
                                    </label>
                                    <label>
                                        <span>Monthly debt</span>
                                        <input type="number" name="monthlyDebt" min="0" step="50" placeholder="e.g. 500" required>
                                    </label>
                                </div>
                            </div>

                            <div class="assessment-step" data-step="2">
                                <h3 class="step-heading">Your buying setup</h3>
                                <div class="form-grid">
                                    <label>
                                        <span>House offer price</span>
                                        <input type="number" name="offerPrice" min="0" step="1000" placeholder="e.g. 550000" required>
                                    </label>
                                    <label>
                                        <span>Down payment</span>
                                        <input type="number" name="downPayment" min="0" step="1000" placeholder="e.g. 40000" required>
                                    </label>
                                    <label>
                                        <span>Monthly HOA <em>(optional)</em></span>
                                        <input type="number" name="hoa" min="0" step="25" placeholder="e.g. 250">
                                    </label>
                                    <label>
                                        <span>Interest rate % <em>(optional, default 6.5)</em></span>
                                        <input type="number" name="interestRate" min="0" step="0.1" value="6.5" placeholder="e.g. 6.5">
                                    </label>
                                    <label>
                                        <span>Property tax rate % <em>(optional, default 1.1)</em></span>
                                        <input type="number" name="propertyTaxRate" min="0" step="0.1" value="1.1" placeholder="e.g. 1.1">
                                    </label>
                                    <label>
                                        <span>Credit score range</span>
                                        <select name="creditRange" required>
                                            <option value="">Select a range</option>
                                            <option value="under-600">Under 600</option>
                                            <option value="600-679">600-679</option>
                                            <option value="680-739">680-739</option>
                                            <option value="740-plus">740+</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="assessment-step" data-step="3">
                                <h3 class="step-heading">Where should we send your report?</h3>
                                <label>
                                    <span>Email</span>
                                    <input type="email" name="email" placeholder="you@example.com" required>
                                </label>
                            </div>
                        </div>

                        <p class="form-message" id="form-message" aria-live="polite"></p>
                        <p class="loading-message is-hidden" id="loading-message" aria-live="polite">Analyzing this property...</p>

                        <div class="assessment-actions is-hidden" id="assessment-actions">
                            <button type="button" class="button button-secondary is-hidden" id="back-button" data-cta-id="intake_quiz_back">Back</button>
                            <button type="button" class="button button-primary" id="next-button" data-cta-id="intake_quiz_continue">Continue</button>
                            <button type="submit" class="button button-primary is-hidden" id="submit-button" data-cta-id="intake_submit_report">See My Report</button>
                        </div>
                    </form>
                </div>

                <div class="result-card is-hidden" id="result-card" aria-live="polite">
                    <span class="section-label">Before You Move Forward — Read This First</span>
                    <h3 class="result-score-heading">Your Home Decision Report</h3>

                    <div class="decision-card decision-safe" id="decision-card">
                        <p class="decision-label">Affordability Score</p>
                        <p class="decision-value"><span class="decision-icon" id="decision-icon">✓</span><span id="decision-score-value">38 / 100 — Financially Tight</span></p>
                        <p class="result-note" id="result-message">Based on your income, debt, and down payment, this home may place significant pressure on your monthly budget.</p>
                        <p class="report-leftover">Estimated money left after housing each month: <strong id="leftover-value">$0</strong></p>
                    </div>

                    <div class="report-panel report-panel-strong">
                        <h4>Your Real Monthly Cost: <span id="true-monthly-cost-value">$0</span></h4>
                        <p class="report-copy">What you’ll likely pay each month — not just the mortgage</p>
                        <ul class="report-list">
                            <li><span>Mortgage payment</span><strong id="mortgage-value">$0</strong></li>
                            <li><span>Property taxes (estimated)</span><strong id="taxes-value">$0</strong></li>
                            <li><span>Home insurance (estimated)</span><strong id="insurance-value">$0</strong></li>
                            <li class="is-hidden" id="hoa-row"><span>HOA (if applicable)</span><strong id="hoa-value">$0</strong></li>
                        </ul>
                    </div>

                    <div class="result-cta-group">
                        <button type="button" class="button button-primary button-full result-action-button" data-cta-id="intake_result_buying_plan">Get My Full Buying Plan</button>
                        <button type="button" class="button button-secondary button-full result-action-button" data-cta-id="intake_result_talk_expert">Talk to a Local Expert</button>
                    </div>

                    <div class="report-panel">
                        <h4>Your Situation (used for this analysis)</h4>
                        <ul class="report-list">
                            <li><span>Property address</span><strong id="input-address-value">-</strong></li>
                            <li><span>House offer price</span><strong id="input-offer-price-value">$0</strong></li>
                            <li><span>Annual income</span><strong id="input-income-value">$0</strong></li>
                            <li><span>Monthly debt</span><strong id="input-debt-value">$0</strong></li>
                            <li><span>Down payment</span><strong id="input-down-payment-value">$0</strong></li>
                            <li><span>Monthly HOA</span><strong id="input-hoa-value">$0</strong></li>
                            <li><span>Interest rate</span><strong id="input-interest-rate-value">6.5%</strong></li>
                            <li><span>Property tax rate</span><strong id="input-tax-rate-value">1.1%</strong></li>
                            <li><span>Credit score range</span><strong id="input-credit-range-value">-</strong></li>
                            <li><span>Email</span><strong id="input-email-value">-</strong></li>
                        </ul>
                    </div>

                    <div class="report-panel">
                        <h4>What This Means For You</h4>
                        <p class="report-copy" id="meaning-value">This home could limit your financial flexibility and make monthly expenses feel stressful. You may want to consider a lower price range or increasing your down payment.</p>
                    </div>

                    <p class="trust-copy">We’ll help you understand your next best step</p>
                    <p class="follow-up-message is-hidden" id="follow-up-message">Coming soon — we’ll email you details.</p>
                    <p class="result-disclaimer">This is not a lender approval. Final costs depend on loan terms, taxes, insurance, and market conditions. This report is designed to help you make a more informed decision before moving forward.</p>
                </div>
            </div>
        </section>
    </section>
</main>

<script>window.YHOME_MARKETING_VISIT_ID=<?= json_encode($GLOBALS['_marketing_visit_id'] ?? null) ?>;</script>
<script src="/cta_track.js" defer></script>
<script src="/app.js" defer></script>
</body>
</html>
