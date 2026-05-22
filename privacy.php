<?php
declare(strict_types=1);

$effectiveDate = 'May 18, 2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — yHome</title>
    <meta name="description" content="Privacy Policy for yHome (yhome.pro): what we collect, how we use it, and your choices.">
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="legal-page">
    <section class="section legal-page__section">
        <div class="container legal-page__container">
            <header class="legal-page__header">
                <a class="site-logo" href="/">yHome</a>
                <nav class="legal-page__nav" aria-label="Legal">
                    <a href="/terms.php">Terms of Service</a>
                </nav>
            </header>

            <article class="legal-doc">
                <h1>Privacy Policy</h1>
                <p class="legal-doc__meta">Effective date: <?= htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8') ?></p>

                <p>This Privacy Policy explains how yHome (“we,” “us,” or “our”) collects, uses, and shares information when you visit <a href="https://yhome.pro">https://yhome.pro</a> or use our homebuying cost and risk tools (the “Service”).</p>

                <h2>1. Information we collect</h2>

                <h3>Information you provide</h3>
                <p>When you use our intake or report flows, you may submit:</p>
                <ul>
                    <li>property address or listing reference;</li>
                    <li>financial inputs (for example, income, monthly debt, offer price, down payment, HOA, interest rate, property tax rate, credit score range);</li>
                    <li>email address;</li>
                    <li>calculated report outputs stored with your submission (for example, estimated monthly cost and affordability score).</li>
                </ul>

                <h3>Information collected automatically</h3>
                <p>When you browse the Service, we may collect:</p>
                <ul>
                    <li>page URL, path, query parameters, and HTTP method;</li>
                    <li>referrer and referring site host;</li>
                    <li>IP address;</li>
                    <li>approximate location derived from IP (city, region, country) where available;</li>
                    <li>browser user agent and device category (desktop, mobile, tablet, or bot);</li>
                    <li>UTM campaign parameters when present in the URL;</li>
                    <li>PHP session identifier, when sessions are used;</li>
                    <li>click interactions on certain buttons or links (CTA tracking), linked to a visit identifier when applicable.</li>
                </ul>

                <h3>Cookies and similar technologies</h3>
                <p>We may use session cookies or similar technologies needed to operate the site, attribute visits, or remember preferences. You can control cookies through your browser settings; disabling cookies may affect some features.</p>

                <h2>2. How we use information</h2>
                <p>We use the information described above to:</p>
                <ul>
                    <li>provide affordability and cost estimates you request;</li>
                    <li>store submissions and generated report snapshots for operations and support;</li>
                    <li>measure traffic, marketing performance, and product usage;</li>
                    <li>maintain security, prevent abuse, and troubleshoot errors;</li>
                    <li>improve the Service and develop new features;</li>
                    <li>communicate with you about your submission or the Service, where permitted.</li>
                </ul>

                <h2>3. Legal bases (where applicable)</h2>
                <p>If you are in a region that requires a legal basis for processing (such as the EEA or UK), we rely on:</p>
                <ul>
                    <li><strong>Contract / steps at your request</strong> — to provide the report or tool you ask for;</li>
                    <li><strong>Legitimate interests</strong> — analytics, security, fraud prevention, and product improvement, balanced against your rights;</li>
                    <li><strong>Consent</strong> — where required for optional marketing or certain integrations you explicitly authorize.</li>
                </ul>

                <h2>4. How we share information</h2>
                <p>We do not sell your personal information. We may share information with:</p>
                <ul>
                    <li><strong>Service providers</strong> that host our website, databases, email, analytics, or automation tools, under contractual obligations to protect data;</li>
                    <li><strong>Geolocation lookup providers</strong> (for example, IP-based city/region lookup services) to approximate visitor location from IP addresses;</li>
                    <li><strong>Infrastructure partners</strong> such as content delivery or security providers that process traffic metadata;</li>
                    <li><strong>Legal and safety recipients</strong> when required by law, court order, or to protect rights, safety, and security.</li>
                </ul>
                <p>If you connect third-party accounts (for example, social or video platforms) through features we offer, those platforms receive information according to their own policies and the permissions you grant.</p>

                <h2>5. Data retention</h2>
                <p>We retain information for as long as needed to operate the Service, comply with legal obligations, resolve disputes, and enforce agreements. Marketing visit logs and form submissions may be kept for analytics and operations unless you request deletion where applicable law allows.</p>

                <h2>6. Security</h2>
                <p>We use reasonable administrative, technical, and organizational measures to protect information. No method of transmission or storage is completely secure; we cannot guarantee absolute security.</p>

                <h2>7. Your choices and rights</h2>
                <p>Depending on where you live, you may have rights to access, correct, delete, or restrict certain processing of your personal information, or to object to processing and receive a portable copy.</p>
                <p>To make a request, contact <a href="mailto:privacy@yhome.pro">privacy@yhome.pro</a>. We may need to verify your identity before responding. You may also have the right to lodge a complaint with a supervisory authority in your region.</p>
                <p>California residents may have additional rights under the CCPA/CPRA, including knowing what categories of personal information we collect and requesting deletion, subject to exceptions.</p>

                <h2>8. Children’s privacy</h2>
                <p>The Service is not directed to children under 18, and we do not knowingly collect personal information from children. If you believe a child has provided us personal information, contact us and we will take appropriate steps to delete it.</p>

                <h2>9. International users</h2>
                <p>We operate from the United States. If you access the Service from other countries, your information may be processed in the U.S. or other locations where our providers operate, which may have different data protection laws than your country.</p>

                <h2>10. Third-party sites</h2>
                <p>Our pages may link to external sites (for example, property listings). We are not responsible for their privacy practices. Review their policies before providing personal information.</p>

                <h2>11. Changes to this policy</h2>
                <p>We may update this Privacy Policy from time to time. We will post the revised version on this page and update the effective date. Material changes may be highlighted on the site where appropriate.</p>

                <h2>12. Contact</h2>
                <p>Privacy questions or requests: <a href="mailto:privacy@yhome.pro">privacy@yhome.pro</a></p>
                <p>yHome — <a href="https://yhome.pro">https://yhome.pro</a></p>
            </article>

            <footer class="legal-page__footer">
                <a href="/">← Back to yHome</a>
                <span>·</span>
                <a href="/terms.php">Terms of Service</a>
            </footer>
        </div>
    </section>
</main>
</body>
</html>
