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
            <a class="site-logo" href="/">yHome</a>
            <p class="intake-rating">Built for 500,000+ yHome Homebuyers</p>
        </header>

        <div class="intake-progress" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span>
        </div>

        <section class="intake-intro-card">
            <img class="intake-hero-image" src="https://images.pexels.com/photos/7578860/pexels-photo-7578860.jpeg?auto=compress&cs=tinysrgb&w=1200" alt="Professional real estate agent meeting buyers outside a home" width="1200" height="720" decoding="async">
            <h1>Start with the home you’re considering</h1>
            <p>Paste the address or listing location. We’ll estimate the real monthly cost and risk.</p>
        </section>

        <section class="section section-soft intake-form-section" id="budget-form">
            <div class="form-layout intake-form-layout">
                <div class="form-card">
                    <form id="affordability-form" novalidate>
                        <div class="address-check" id="address-check">
                            <label>
                                <span>Property address</span>
                                <input type="text" name="propertyAddress" placeholder="Enter a Zillow, Redfin, or property address" required>
                                <small>Works with Zillow, Redfin, or any property address</small>
                            </label>
                            <button type="button" class="button button-primary button-full" id="start-report-button">ANALYZE THIS HOME</button>
                            <p class="form-message" id="address-message" aria-live="polite"></p>

                            <div class="value-preview">
                                <h3>You’ll get a quick home buying report:</h3>
                                <ul>
                                    <li>Your estimated true monthly cost</li>
                                    <li>A clear Safe / Borderline / Risky decision</li>
                                    <li>What this means before you make an offer</li>
                                </ul>
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
                            <button type="button" class="button button-secondary is-hidden" id="back-button">Back</button>
                            <button type="button" class="button button-primary" id="next-button">Continue</button>
                            <button type="submit" class="button button-primary is-hidden" id="submit-button">See My Report</button>
                        </div>
                    </form>
                </div>

                <div class="result-card is-hidden" id="result-card" aria-live="polite">
                    <span class="section-label">Before You Buy — Here’s What You Should Know</span>
                    <h3 class="result-score-heading">Home Affordability Score</h3>

                    <div class="decision-card decision-safe" id="decision-card">
                        <p class="decision-label">Home Affordability Score</p>
                        <p class="decision-value"><span class="decision-icon" id="decision-icon">✓</span><span id="decision-score-value">Safe to Buy</span></p>
                        <p class="result-note" id="result-message">This home appears to be a comfortable fit based on your numbers.</p>
                        <p class="report-leftover">Estimated Monthly Flex Cash: <strong id="leftover-value">$0</strong></p>
                    </div>

                    <div class="report-panel report-panel-strong">
                        <h4>Estimated Monthly Cost: <span id="true-monthly-cost-value">$0</span></h4>
                        <ul class="report-list">
                            <li><span>Mortgage</span><strong id="mortgage-value">$0</strong></li>
                            <li><span>Taxes (estimated)</span><strong id="taxes-value">$0</strong></li>
                            <li><span>Insurance (estimated)</span><strong id="insurance-value">$0</strong></li>
                            <li class="is-hidden" id="hoa-row"><span>HOA</span><strong id="hoa-value">$0</strong></li>
                        </ul>
                    </div>

                    <div class="report-panel">
                        <h4>Buyer Inputs</h4>
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
                        <h4>Key Insight</h4>
                        <p class="report-copy" id="meaning-value">This home may stretch your monthly budget.</p>
                    </div>

                    <div class="result-cta-group">
                        <button type="button" class="button button-primary button-full result-action-button">Get My Full Plan</button>
                        <button type="button" class="button button-secondary button-full result-action-button">Talk to a Local Expert</button>
                    </div>
                    <p class="follow-up-message is-hidden" id="follow-up-message">Coming soon — we’ll email you details.</p>
                    <p class="result-disclaimer">This is an estimate, not mortgage or financial advice. Actual costs depend on lender approval, interest rates, taxes, insurance, HOA, and local market conditions.</p>
                </div>
            </div>
        </section>
    </section>
</main>

<script src="/app.js" defer></script>
</body>
</html>
