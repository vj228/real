<?php
declare(strict_types=1);

$effectiveDate = 'May 18, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service — yHome</title>
    <meta name="description" content="Terms of Service for yHome (yhome.pro), a homebuying cost and risk information tool.">
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="legal-page">
    <section class="section legal-page__section">
        <div class="container legal-page__container">
            <header class="legal-page__header">
                <a class="site-logo" href="/">yHome</a>
                <nav class="legal-page__nav" aria-label="Legal">
                    <a href="/privacy.php">Privacy Policy</a>
                </nav>
            </header>

            <article class="legal-doc">
                <h1>Terms of Service</h1>
                <p class="legal-doc__meta">Effective date: <?= htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8') ?></p>

                <p>These Terms of Service (“Terms”) govern your access to and use of the yHome website and related services (collectively, the “Service”), operated at <a href="https://yhome.pro">https://yhome.pro</a> (“yHome,” “we,” “us,” or “our”). By using the Service, you agree to these Terms. If you do not agree, do not use the Service.</p>

                <h2>1. What yHome provides</h2>
                <p>yHome offers educational and informational tools to help prospective homebuyers understand estimated monthly housing costs, financial pressure, and general decision signals based on information you provide (such as property address, income, debts, and offer assumptions).</p>
                <p>The Service is provided for general informational purposes only. yHome is <strong>not</strong> a lender, mortgage broker, real estate broker, financial advisor, tax advisor, or legal advisor, and does not provide lending decisions, underwriting, appraisals, or personalized professional advice.</p>

                <h2>2. No guarantee of accuracy</h2>
                <p>Estimates, scores, labels, and reports shown on the Service depend on the inputs you provide and on third-party or modeled data that may be incomplete, outdated, or incorrect. Property taxes, insurance, interest rates, HOA fees, and market conditions change. You are responsible for verifying all figures with qualified professionals before making an offer or financial commitment.</p>
                <p>Outputs such as “affordability” or buy/wait-style signals are illustrative only and are not a promise of loan approval, investment performance, or suitability for your situation.</p>

                <h2>3. Eligibility</h2>
                <p>You must be at least 18 years old and able to form a binding contract to use the Service. You represent that the information you submit is accurate to the best of your knowledge and that you will not use the Service for unlawful purposes.</p>

                <h2>4. Your use of the Service</h2>
                <p>You agree not to:</p>
                <ul>
                    <li>misuse, disrupt, or attempt unauthorized access to the Service or our systems;</li>
                    <li>scrape, crawl, or automate access in a way that burdens our infrastructure without permission;</li>
                    <li>submit false, misleading, or another person’s personal information without authorization;</li>
                    <li>reverse engineer or copy the Service except as permitted by law;</li>
                    <li>use the Service in violation of applicable law or third-party rights.</li>
                </ul>
                <p>We may suspend or limit access if we reasonably believe you have violated these Terms or pose a security or abuse risk.</p>

                <h2>5. Submissions and communications</h2>
                <p>When you submit a property address, financial inputs, email address, or other data through our intake or related forms, you grant us permission to process that information to operate the Service, improve our products, and communicate with you about your request where applicable. Our use of personal data is described in our <a href="/privacy.php">Privacy Policy</a>.</p>
                <p>You are responsible for keeping any credentials, links, or account tokens (if we offer integrations in the future) confidential.</p>

                <h2>6. Third-party content and links</h2>
                <p>The Service may reference or display information related to properties listed on third-party sites (for example, listing portals). We do not control and are not responsible for third-party websites, listing accuracy, or their terms. Your use of third-party services is governed by those providers’ policies.</p>

                <h2>7. Intellectual property</h2>
                <p>The Service, including its design, text, graphics, software, and branding, is owned by yHome or its licensors and is protected by intellectual property laws. You receive a limited, non-exclusive, non-transferable license to access and use the Service for personal, non-commercial purposes. No rights are granted except as expressly stated in these Terms.</p>

                <h2>8. Disclaimer of warranties</h2>
                <p>THE SERVICE IS PROVIDED “AS IS” AND “AS AVAILABLE” WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS, IMPLIED, OR STATUTORY, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND NON-INFRINGEMENT. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.</p>

                <h2>9. Limitation of liability</h2>
                <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, yHOME AND ITS OWNERS, EMPLOYEES, AND SUPPLIERS WILL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS, DATA, OR GOODWILL, ARISING FROM OR RELATED TO YOUR USE OF THE SERVICE, EVEN IF WE HAVE BEEN ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.</p>
                <p>OUR TOTAL LIABILITY FOR ANY CLAIM ARISING OUT OF THESE TERMS OR THE SERVICE WILL NOT EXCEED THE GREATER OF (A) ONE HUNDRED U.S. DOLLARS (US $100) OR (B) THE AMOUNT YOU PAID US FOR THE SERVICE IN THE TWELVE (12) MONTHS BEFORE THE EVENT GIVING RISE TO THE CLAIM (IF ANY).</p>
                <p>Some jurisdictions do not allow certain limitations; in those cases, our liability is limited to the fullest extent permitted by law.</p>

                <h2>10. Indemnification</h2>
                <p>You agree to defend, indemnify, and hold harmless yHome from claims, damages, losses, and expenses (including reasonable attorneys’ fees) arising from your use of the Service, your submissions, or your violation of these Terms or applicable law.</p>

                <h2>11. Changes to the Service and Terms</h2>
                <p>We may modify the Service or these Terms at any time. We will post updated Terms on this page and update the effective date. Material changes may also be noted on the site where practical. Continued use after changes become effective constitutes acceptance of the revised Terms.</p>

                <h2>12. Termination</h2>
                <p>You may stop using the Service at any time. We may terminate or restrict your access at our discretion, with or without notice, subject to applicable law.</p>

                <h2>13. Governing law</h2>
                <p>These Terms are governed by the laws of the State of California, United States, without regard to conflict-of-law principles, except where mandatory consumer protections in your jurisdiction apply. Any dispute arising from these Terms or the Service will be brought in the state or federal courts located in California, unless applicable law requires otherwise.</p>

                <h2>14. Contact</h2>
                <p>Questions about these Terms may be sent to <a href="mailto:legal@yhome.pro">legal@yhome.pro</a> or through the contact options published on <a href="https://yhome.pro">yhome.pro</a>.</p>
            </article>

            <footer class="legal-page__footer">
                <a href="/">← Back to yHome</a>
                <span>·</span>
                <a href="/privacy.php">Privacy Policy</a>
            </footer>
        </div>
    </section>
</main>
</body>
</html>
