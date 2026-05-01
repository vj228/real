<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);

    exit;
}

require_once dirname(__DIR__) . '/pdo_connect.php';
require_once dirname(__DIR__) . '/helpers/marketing_client_ip.php';
require_once dirname(__DIR__) . '/helpers/marketing_resolve_visit_id.php';

function submission_read_json(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function submission_float($v, bool $allowEmpty = false): ?float
{
    if ($v === null || $v === '') {
        return $allowEmpty ? null : null;
    }
    if (is_numeric($v)) {
        return (float) $v;
    }

    return null;
}

function submission_int($v, bool $allowEmpty = false): ?int
{
    if ($v === null || $v === '') {
        return $allowEmpty ? null : null;
    }
    if (is_numeric($v)) {
        return (int) round((float) $v);
    }

    return null;
}

function submission_string($v, int $maxLen): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $s = trim((string) $v);

    return strlen($s) > $maxLen ? substr($s, 0, $maxLen) : $s;
}

$payload = submission_read_json();
if ($payload === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);

    exit;
}

$form = isset($payload['form']) && is_array($payload['form']) ? $payload['form'] : $payload;
$result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];

$propertyAddress = submission_string($form['propertyAddress'] ?? '', 2048);
$email = submission_string($form['email'] ?? '', 255);

if ($propertyAddress === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_or_invalid_fields']);

    exit;
}

$annualIncome = submission_float($form['annualIncome'] ?? null);
$monthlyDebt = submission_float($form['monthlyDebt'] ?? null);
$offerPrice = submission_float($form['offerPrice'] ?? null);
$downPayment = submission_float($form['downPayment'] ?? null);

if ($annualIncome === null || $annualIncome < 0 || $monthlyDebt === null || $monthlyDebt < 0
    || $offerPrice === null || $offerPrice <= 0 || $downPayment === null || $downPayment < 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_numeric_inputs']);

    exit;
}

$monthlyHoa = submission_float($form['hoa'] ?? null, true);
$interestRatePercent = submission_float($form['interestRate'] ?? null, true);
$propertyTaxRatePercent = submission_float($form['propertyTaxRate'] ?? null, true);
$creditScoreRange = submission_string($form['creditRange'] ?? '', 20);
if ($creditScoreRange === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'credit_range_required']);

    exit;
}

$reportEstimatedHome = submission_float($result['estimatedHomePrice'] ?? null, true);
$reportMortgage = submission_int($result['estimatedMortgage'] ?? null, true);
$reportTax = submission_int($result['taxesEstimate'] ?? null, true);
$reportInsurance = submission_int($result['insuranceEstimate'] ?? null, true);
$reportTrueCost = submission_int($result['trueMonthlyCost'] ?? null, true);
$reportFlex = submission_int($result['leftoverForLife'] ?? null, true);
$reportScore = null;
if (isset($result['affordabilityScore']) && is_numeric($result['affordabilityScore'])) {
    $s = (int) round((float) $result['affordabilityScore']);
    if ($s >= 0 && $s <= 100) {
        $reportScore = $s;
    }
}
$pdo = db_pdo_connect();
if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'database_unavailable']);

    exit;
}

$marketingVisitId = marketing_resolve_visit_id_from_payload($pdo, $payload);
$ip = marketing_client_ip();

$sql = 'INSERT INTO home_offer_form_submissions (
    marketing_visit_id,
    ip_address,
    property_address,
    annual_income,
    monthly_debt,
    offer_price,
    down_payment,
    monthly_hoa,
    interest_rate_percent,
    property_tax_rate_percent,
    credit_score_range,
    email,
    report_estimated_home_price,
    report_monthly_mortgage,
    report_monthly_property_tax,
    report_monthly_insurance,
    report_monthly_true_cost,
    report_monthly_flex_cash,
    report_affordability_score
) VALUES (
    :marketing_visit_id,
    :ip_address,
    :property_address,
    :annual_income,
    :monthly_debt,
    :offer_price,
    :down_payment,
    :monthly_hoa,
    :interest_rate_percent,
    :property_tax_rate_percent,
    :credit_score_range,
    :email,
    :report_estimated_home_price,
    :report_monthly_mortgage,
    :report_monthly_property_tax,
    :report_monthly_insurance,
    :report_monthly_true_cost,
    :report_monthly_flex_cash,
    :report_affordability_score
)';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':marketing_visit_id' => $marketingVisitId,
        ':ip_address' => $ip,
        ':property_address' => $propertyAddress,
        ':annual_income' => round($annualIncome, 2),
        ':monthly_debt' => round($monthlyDebt, 2),
        ':offer_price' => round($offerPrice, 2),
        ':down_payment' => round($downPayment, 2),
        ':monthly_hoa' => $monthlyHoa !== null ? round($monthlyHoa, 2) : null,
        ':interest_rate_percent' => $interestRatePercent !== null ? round($interestRatePercent, 2) : null,
        ':property_tax_rate_percent' => $propertyTaxRatePercent !== null ? round($propertyTaxRatePercent, 3) : null,
        ':credit_score_range' => $creditScoreRange,
        ':email' => $email,
        ':report_estimated_home_price' => $reportEstimatedHome !== null ? round($reportEstimatedHome, 2) : null,
        ':report_monthly_mortgage' => $reportMortgage,
        ':report_monthly_property_tax' => $reportTax,
        ':report_monthly_insurance' => $reportInsurance,
        ':report_monthly_true_cost' => $reportTrueCost,
        ':report_monthly_flex_cash' => $reportFlex,
        ':report_affordability_score' => $reportScore,
    ]);
} catch (Throwable $e) {
    error_log('save_home_offer: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'insert_failed']);

    exit;
}

echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
