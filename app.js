const scrollButtons = document.querySelectorAll('.js-scroll-to-form');
const formSection = document.getElementById('budget-form');
const form = document.getElementById('affordability-form');
const formMessage = document.getElementById('form-message');
const addressMessage = document.getElementById('address-message');
const addressCheck = document.getElementById('address-check');
const quizShell = document.getElementById('quiz-shell');
const assessmentActions = document.getElementById('assessment-actions');
const startReportButton = document.getElementById('start-report-button');
const assessmentProgress = document.getElementById('assessment-progress');
const progressBarFill = document.getElementById('progress-bar-fill');
const backButton = document.getElementById('back-button');
const nextButton = document.getElementById('next-button');
const submitButton = document.getElementById('submit-button');
const resultCard = document.getElementById('result-card');
const loadingMessage = document.getElementById('loading-message');
const followUpMessage = document.getElementById('follow-up-message');
const resultActionButtons = document.querySelectorAll('.result-action-button');
const steps = Array.from(document.querySelectorAll('.assessment-step'));

const decisionCard = document.getElementById('decision-card');
const decisionIcon = document.getElementById('decision-icon');
const decisionScoreValue = document.getElementById('decision-score-value');
const trueMonthlyCostValue = document.getElementById('true-monthly-cost-value');
const mortgageValue = document.getElementById('mortgage-value');
const taxesValue = document.getElementById('taxes-value');
const insuranceValue = document.getElementById('insurance-value');
const hoaRow = document.getElementById('hoa-row');
const hoaValue = document.getElementById('hoa-value');
const leftoverValue = document.getElementById('leftover-value');
const resultMessage = document.getElementById('result-message');
const meaningValue = document.getElementById('meaning-value');
const inputAddressValue = document.getElementById('input-address-value');
const inputOfferPriceValue = document.getElementById('input-offer-price-value');
const inputIncomeValue = document.getElementById('input-income-value');
const inputDebtValue = document.getElementById('input-debt-value');
const inputDownPaymentValue = document.getElementById('input-down-payment-value');
const inputHoaValue = document.getElementById('input-hoa-value');
const inputInterestRateValue = document.getElementById('input-interest-rate-value');
const inputTaxRateValue = document.getElementById('input-tax-rate-value');
const inputCreditRangeValue = document.getElementById('input-credit-range-value');
const inputEmailValue = document.getElementById('input-email-value');

let currentStep = 1;
const totalSteps = steps.length;

/** Resolve /foo/bar/script.php relative to current page (works when app is not at domain root). */
function yhomeApiUrl(filename) {
    const pathname = window.location.pathname;
    const dir = pathname.replace(/[^/]*$/, '');
    return `${dir}${filename}`;
}

scrollButtons.forEach((button) => {
    button.addEventListener('click', () => {
        formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

function formatCurrency(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0
    }).format(value);
}

function getCurrentStepElement() {
    return steps[currentStep - 1];
}

function updateStepUI() {
    steps.forEach((step, index) => {
        step.classList.toggle('is-active', index === currentStep - 1);
    });

    assessmentProgress.textContent = `Step ${currentStep} of ${totalSteps}`;
    progressBarFill.style.width = `${(currentStep / totalSteps) * 100}%`;
    backButton.classList.toggle('is-hidden', currentStep === 1);
    nextButton.classList.toggle('is-hidden', currentStep === totalSteps);
    submitButton.classList.toggle('is-hidden', currentStep !== totalSteps);
}

function validateStep(stepNumber) {
    const step = steps[stepNumber - 1];
    const inputs = Array.from(step.querySelectorAll('input, select'));

    for (const input of inputs) {
        const value = String(input.value || '').trim();

        if (input.name === 'annualIncome') {
            const annualIncome = Number(value);
            if (!annualIncome || annualIncome <= 0) {
                formMessage.textContent = 'Please enter a valid annual income.';
                return false;
            }
        }

        if (input.name === 'monthlyDebt') {
            const monthlyDebt = Number(value);
            if (value === '' || monthlyDebt < 0) {
                formMessage.textContent = 'Please enter a valid monthly debt amount.';
                return false;
            }
        }

        if (input.name === 'downPayment') {
            const downPayment = Number(value);
            if (value === '' || downPayment < 0) {
                formMessage.textContent = 'Please enter a valid down payment amount.';
                return false;
            }
        }

        if (input.name === 'offerPrice') {
            const offerPrice = Number(value);
            if (value === '' || offerPrice <= 0) {
                formMessage.textContent = 'Please enter a valid house offer price.';
                return false;
            }
        }

        if ((input.name === 'hoa' || input.name === 'interestRate' || input.name === 'propertyTaxRate') && value !== '') {
            const optionalValue = Number(value);
            if (optionalValue < 0) {
                formMessage.textContent = 'Optional rate and HOA values cannot be negative.';
                return false;
            }
        }

        if (input.name === 'creditRange' && !value) {
            formMessage.textContent = 'Please select your credit score range.';
            return false;
        }

        if (input.name === 'email' && (!value || !value.includes('@'))) {
            formMessage.textContent = 'Please enter a valid email address.';
            return false;
        }
    }

    formMessage.textContent = '';
    return true;
}

function validateAddress() {
    const addressInput = form.querySelector('input[name="propertyAddress"]');
    const address = String(addressInput.value || '').trim();

    if (!address) {
        addressMessage.textContent = 'Please enter the property address you want to check.';
        return false;
    }

    addressMessage.textContent = '';
    return true;
}

function getDecisionScore(dti, downPaymentRatio) {
    let dtiScore = 5;
    if (dti <= 0.25) dtiScore = 60;
    else if (dti <= 0.30) dtiScore = 55;
    else if (dti <= 0.36) dtiScore = 50;
    else if (dti <= 0.43) dtiScore = 40;
    else if (dti <= 0.45) dtiScore = 30;
    else if (dti <= 0.50) dtiScore = 15;

    let downPaymentScore = 5;
    if (downPaymentRatio >= 0.20) downPaymentScore = 25;
    else if (downPaymentRatio >= 0.15) downPaymentScore = 22;
    else if (downPaymentRatio >= 0.10) downPaymentScore = 18;
    else if (downPaymentRatio >= 0.05) downPaymentScore = 12;

    return {
        dtiScore,
        downPaymentScore
    };
}

function getCreditScorePoints(creditRange) {
    if (creditRange === '740-plus') return 15;
    if (creditRange === '680-739') return 12;
    if (creditRange === '600-679') return 8;
    return 5;
}

function getScoreBand(score) {
    if (score >= 80) {
        return {
            label: 'Comfortably Affordable',
            message: 'Based on your income, debt, and down payment, this home appears to fit comfortably within your budget.',
            insight: 'This home appears to fit comfortably within your budget, giving you room to manage other expenses and save.',
            tone: 'safe'
        };
    }

    if (score >= 65) {
        return {
            label: 'Manageable',
            message: 'Based on your income, debt, and down payment, this home looks manageable with moderate monthly pressure.',
            insight: 'This home looks manageable for your current profile, but keeping healthy cash reserves will still matter.',
            tone: 'safe'
        };
    }

    if (score >= 50) {
        return {
            label: 'Borderline',
            message: 'Based on your income, debt, and down payment, this home may feel tight month to month.',
            insight: 'This home may be possible, but your monthly flexibility could be limited unless you improve the structure of the deal.',
            tone: 'borderline'
        };
    }

    if (score >= 35) {
        return {
            label: 'Financially Tight',
            message: 'Based on your income, debt, and down payment, this home may place significant pressure on your monthly budget.',
            insight: 'This home could limit your financial flexibility and make monthly expenses feel stressful. You may want to consider a lower price range or increasing your down payment.',
            tone: 'borderline'
        };
    }

    return {
        label: 'High Risk',
        message: 'Based on your numbers, this purchase may create high financial strain and elevated risk.',
        insight: 'This home may put too much pressure on your finances right now. Consider a lower price point or a stronger down payment before moving forward.',
        tone: 'risky'
    };
}

function getDecisionMessage(score) {
    return score.message;
}

function getMeaningMessage(score) {
    return score.insight;
}

function getCreditRangeLabel(value) {
    if (value === '740-plus') return '740+';
    if (value === '680-739') return '680-739';
    if (value === '600-679') return '600-679';
    return 'Under 600';
}

function calculateReport(data) {
    const annualIncome = Number(data.annualIncome);
    const monthlyIncome = annualIncome / 12;
    const monthlyDebt = Number(data.monthlyDebt || 0);
    const downPayment = Number(data.downPayment || 0);
    const homePrice = Math.round(Number(data.offerPrice || 0) / 1000) * 1000;
    const hoa = Number(data.hoa || 0);
    const interestRate = Number(data.interestRate || 6.5);
    const propertyTaxRate = Number(data.propertyTaxRate || 1.1);
    const loanAmount = Math.max(homePrice - downPayment, 0);
    const monthlyInterestRate = (interestRate / 100) / 12;
    const numberOfPayments = 30 * 12;
    const mortgagePayment = (loanAmount === 0 || monthlyInterestRate === 0)
        ? loanAmount / numberOfPayments
        : (loanAmount * monthlyInterestRate * Math.pow(1 + monthlyInterestRate, numberOfPayments))
            / (Math.pow(1 + monthlyInterestRate, numberOfPayments) - 1);
    const monthlyPropertyTax = homePrice * (propertyTaxRate / 100) / 12;
    const monthlyInsurance = homePrice * 0.0035 / 12;
    const estimatedMonthlyCost = mortgagePayment + monthlyPropertyTax + monthlyInsurance + hoa;
    const totalMonthlyObligation = estimatedMonthlyCost + monthlyDebt;
    const dti = totalMonthlyObligation / Math.max(monthlyIncome, 1);
    const downPaymentRatio = downPayment / Math.max(homePrice, 1);
    const dtiAndDownPayment = getDecisionScore(dti, downPaymentRatio);
    const creditScorePoints = getCreditScorePoints(String(data.creditRange || 'under-600'));
    const totalScore = Math.min(dtiAndDownPayment.dtiScore + dtiAndDownPayment.downPaymentScore + creditScorePoints, 100);
    const scoreBand = getScoreBand(totalScore);
    const estimatedMortgage = Math.round(mortgagePayment);
    const taxesEstimate = Math.round(monthlyPropertyTax);
    const insuranceEstimate = Math.round(monthlyInsurance);
    const trueMonthlyCost = Math.round(estimatedMonthlyCost);
    const leftoverForLife = Math.round(monthlyIncome - trueMonthlyCost - monthlyDebt);

    return {
        propertyAddress: data.propertyAddress,
        estimatedHomePrice: homePrice,
        hoa,
        interestRate,
        propertyTaxRate,
        estimatedMortgage,
        taxesEstimate,
        insuranceEstimate,
        trueMonthlyCost,
        leftoverForLife,
        affordabilityScore: totalScore,
        decisionScore: `${totalScore} / 100 — ${scoreBand.label}`,
        decisionTone: scoreBand.tone,
        decisionMessage: getDecisionMessage(scoreBand),
        meaningMessage: getMeaningMessage(scoreBand)
    };
}

function saveLead(data, result) {
    const storageKey = 'home_reports';
    const existing = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
    const payload = {
        ...data,
        result,
        submittedAt: new Date().toISOString()
    };

    existing.push(payload);
    window.localStorage.setItem(storageKey, JSON.stringify(existing));
}

function persistHomeOfferToServer(data, result) {
    const visitRaw = window.YHOME_MARKETING_VISIT_ID;
    const visitId = typeof visitRaw === 'number' && Number.isFinite(visitRaw) && visitRaw > 0 ? visitRaw : null;
    const body = JSON.stringify({
        visit_id: visitId,
        form: data,
        result
    });
    const fetchOpts = {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json'
        },
        body,
        keepalive: true,
        credentials: 'same-origin'
    };
    const primary = yhomeApiUrl('persist_intake_snapshot.php');
    fetch(primary, fetchOpts)
        .then((r) => {
            if (r.ok) return r.json();
            if (r.status === 404) {
                return fetch(yhomeApiUrl('save_home_offer.php'), fetchOpts).then((r2) =>
                    r2.ok ? r2.json() : Promise.reject(new Error(`save HTTP ${r2.status}`))
                );
            }

            return Promise.reject(new Error(`save HTTP ${r.status}`));
        })
        .then((j) => {
            if (!j || j.ok !== true) {
                console.warn('[yHome] intake not saved:', j && j.error ? j.error : j);
            }
        })
        .catch((e) => console.warn('[yHome] intake save request failed', e));
}

startReportButton.addEventListener('click', () => {
    if (!validateAddress()) {
        return;
    }

    addressMessage.textContent = '';
    addressCheck.classList.add('is-hidden');
    quizShell.classList.remove('is-hidden');
    assessmentActions.classList.remove('is-hidden');
    updateStepUI();
    getCurrentStepElement().querySelector('input, select').focus();
});

nextButton.addEventListener('click', () => {
    if (!validateStep(currentStep)) {
        return;
    }

    currentStep += 1;
    updateStepUI();
    getCurrentStepElement().querySelector('input, select').focus();
});

backButton.addEventListener('click', () => {
    formMessage.textContent = '';
    currentStep -= 1;
    updateStepUI();
    getCurrentStepElement().querySelector('input, select').focus();
});

resultActionButtons.forEach((button) => {
    button.addEventListener('click', () => {
        followUpMessage.classList.remove('is-hidden');
    });
});

form.addEventListener('submit', (event) => {
    event.preventDefault();
    formMessage.textContent = '';
    resultCard.classList.add('is-hidden');
    followUpMessage.classList.add('is-hidden');

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const annualIncome = Number(data.annualIncome);
    const monthlyDebt = Number(data.monthlyDebt || 0);
    const offerPrice = Number(data.offerPrice || 0);
    const downPayment = Number(data.downPayment);
    const email = String(data.email || '').trim();
    const propertyAddress = String(data.propertyAddress || '').trim();

    if (!propertyAddress) {
        formMessage.textContent = 'Please enter the property address you want to check.';
        return;
    }

    if (!validateStep(currentStep)) {
        return;
    }

    if (!annualIncome || annualIncome <= 0 || monthlyDebt < 0 || !offerPrice || offerPrice <= 0 || downPayment < 0 || !email || !email.includes('@')) {
        formMessage.textContent = 'Please complete the check with valid information.';
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Checking...';
    loadingMessage.classList.remove('is-hidden');

    const loadingFrames = [
        'Analyzing this home...',
        'Calculating true monthly cost...',
        'Evaluating financial pressure...'
    ];
    let loadingIndex = 0;
    loadingMessage.textContent = loadingFrames[loadingIndex];
    const loadingInterval = window.setInterval(() => {
        loadingIndex = (loadingIndex + 1) % loadingFrames.length;
        loadingMessage.textContent = loadingFrames[loadingIndex];
    }, 400);

    const result = calculateReport(data);
    saveLead(data, result);
    persistHomeOfferToServer(data, result);

    window.setTimeout(() => {
        console.log('Home report submitted:', data);
        console.log('Home report result:', result);

        decisionScoreValue.textContent = result.decisionScore;
        trueMonthlyCostValue.textContent = formatCurrency(result.trueMonthlyCost);
        mortgageValue.textContent = formatCurrency(result.estimatedMortgage);
        taxesValue.textContent = formatCurrency(result.taxesEstimate);
        insuranceValue.textContent = formatCurrency(result.insuranceEstimate);
        hoaValue.textContent = formatCurrency(result.hoa);
        hoaRow.classList.toggle('is-hidden', result.hoa <= 0);
        leftoverValue.textContent = formatCurrency(result.leftoverForLife);
        inputAddressValue.textContent = propertyAddress;
        inputOfferPriceValue.textContent = formatCurrency(offerPrice);
        inputIncomeValue.textContent = formatCurrency(annualIncome);
        inputDebtValue.textContent = formatCurrency(monthlyDebt);
        inputDownPaymentValue.textContent = formatCurrency(downPayment);
        inputHoaValue.textContent = formatCurrency(result.hoa);
        inputInterestRateValue.textContent = `${result.interestRate}%`;
        inputTaxRateValue.textContent = `${result.propertyTaxRate}%`;
        inputCreditRangeValue.textContent = getCreditRangeLabel(String(data.creditRange || 'under-600'));
        inputEmailValue.textContent = email;
        resultMessage.textContent = result.decisionMessage;
        meaningValue.textContent = result.meaningMessage;
        decisionCard.classList.remove('decision-safe', 'decision-borderline', 'decision-risky');
        if (result.decisionTone === 'safe') {
            decisionCard.classList.add('decision-safe');
            decisionIcon.textContent = '✓';
        } else if (result.decisionTone === 'borderline') {
            decisionCard.classList.add('decision-borderline');
            decisionIcon.textContent = '!';
        } else {
            decisionCard.classList.add('decision-risky');
            decisionIcon.textContent = '!';
        }

        resultCard.classList.remove('is-hidden');
        resultCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        submitButton.disabled = false;
        submitButton.textContent = 'See My Report';
        window.clearInterval(loadingInterval);
        loadingMessage.classList.add('is-hidden');
        formMessage.textContent = '';
    }, 1200);
});

updateStepUI();
