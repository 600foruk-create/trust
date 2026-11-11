<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$activePage = 'zakat_calculator';
$madrasaName = get_setting('madrasa_name', 'Trust Accounts');
$currency = get_setting('currency', 'PKR');

// Default estimated market rates in Pakistan
$defaultSilverRate = 3200; // Rs per tola
$defaultGoldRate = 285000; // Rs per tola

// Find Zakat fund ID if exists
$zakatFundId = 0;
try {
    $zStmt = $pdo->query("SELECT id FROM funds WHERE is_active=1 AND (fund_code='ZAK' OR name LIKE '%zakat%' OR name LIKE '%زکوٰ%') ORDER BY id ASC LIMIT 1");
    $zakatFundId = (int)$zStmt->fetchColumn();
} catch (Exception $e) {}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Zakat Calculator | <?=e($madrasaName)?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?=css_ver()?>">
    <style>
        .calc-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-top: 20px;
        }
        @media (max-width: 900px) {
            .calc-grid { grid-template-columns: 1fr; }
        }
        .nisab-bar {
            background: linear-gradient(135deg, #064e3b, #047857);
            color: #ffffff;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(6,78,59,0.15);
        }
        .calc-field-group {
            margin-bottom: 16px;
        }
        .calc-field-group label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
            color: #1e293b;
        }
        .calc-field-group input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            background: #ffffff;
            box-sizing: border-box;
        }
        .calc-field-group input:focus {
            border-color: #10b981;
            outline: none;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.15);
        }
        .summary-card {
            background: #ffffff;
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 24px;
            position: sticky;
            top: 20px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        }
        .result-box {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            border-radius: 10px;
            padding: 18px;
            text-align: center;
            margin: 20px 0;
        }
        .result-amount {
            font-size: 32px;
            font-weight: 900;
            color: #15803d;
            letter-spacing: .5px;
            margin: 4px 0;
        }
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .breakdown-table td {
            padding: 7px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .breakdown-table td:last-child {
            text-align: right;
            font-weight: 700;
        }
        @media print {
            .app-header, .sidebar, .topbar, .no-print, nav { display: none !important; }
            .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .calc-grid { display: block !important; }
            .summary-card { border: 1.5px solid #000; box-shadow: none; margin-top: 20px; }
            .nisab-bar { background: #f8fafc !important; color: #000 !important; border: 1px solid #000; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../app/sidebar.php'; ?>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ISLAMIC FINANCIAL CALCULATOR</span>
                <h1>Zakat Calculator <span class="ur">(زکوٰۃ کیلکولیٹر و واؤچر نظام)</span></h1>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn secondary" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;">
                    🖨️ Print Worksheet <span class="ur">(شیٹ پرنٹ کریں)</span>
                </button>
            </div>
        </header>

        <!-- Shariah Nisab Overview Bar -->
        <div class="nisab-bar">
            <div>
                <h3 style="margin:0 0 4px;font-size:16px;">
                    📜 شرعی نصابِ زکوٰۃ (Shariah Nisab Criteria)
                </h3>
                <p style="margin:0;font-size:12.5px;opacity:0.9;">
                    نقدی رقم، تجارتی مال اور مشترکہ اثاثوں پر <b>چاندی کا نصاب (52.5 تولے / 612.36 گرام)</b> لاگو ہوتا ہے۔
                </p>
            </div>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <div style="background:rgba(255,255,255,0.15);padding:6px 12px;border-radius:6px;font-size:12px;">
                    Silver Rate (چاندی): <b>Rs. <span id="dispSilverRate"><?=$defaultSilverRate?></span>/tola</b>
                </div>
                <div style="background:rgba(255,255,255,0.15);padding:6px 12px;border-radius:6px;font-size:12px;">
                    Nisab Limit (حدِ نصاب): <b>Rs. <span id="dispNisabLimit"><?=number_format($defaultSilverRate * 52.5)?></span></b>
                </div>
            </div>
        </div>

        <div class="calc-grid">
            <!-- Left Side: Wealth Inputs -->
            <div>
                <!-- Panel 1: Market Rates Customization -->
                <article class="panel" style="margin-bottom:20px;border:1px solid #cbd5e1;">
                    <div class="panel-heading" style="background:#f8fafc;padding:12px 18px;border-bottom:1px solid #e2e8f0;">
                        <h4 style="margin:0;font-size:13.5px;color:#0f172a;">⚙️ Live Market Rates <span class="ur">(مارکیٹ ریٹس برائے نصاب)</span></h4>
                    </div>
                    <div style="padding:16px 18px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div class="calc-field-group" style="margin-bottom:0;">
                            <label>Silver Price / Tola <span class="ur">(چاندی فی تولہ)</span></label>
                            <input type="number" id="silverRateInput" value="<?=$defaultSilverRate?>" min="1" oninput="calculateZakat()">
                        </div>
                        <div class="calc-field-group" style="margin-bottom:0;">
                            <label>Gold 24K Price / Tola <span class="ur">(سونا فی تولہ)</span></label>
                            <input type="number" id="goldRateInput" value="<?=$defaultGoldRate?>" min="1" oninput="calculateZakat()">
                        </div>
                    </div>
                </article>

                <!-- Panel 2: Zakatable Wealth (اموالِ زکوٰۃ) -->
                <article class="panel" style="margin-bottom:20px;border:1px solid #cbd5e1;">
                    <div class="panel-heading" style="background:#ecfdf5;padding:12px 18px;border-bottom:1px solid #a7f3d0;">
                        <h4 style="margin:0;font-size:14px;color:#065f46;">💰 Zakatable Assets <span class="ur">(قابلِ زکوٰۃ اثاثہ جات)</span></h4>
                    </div>
                    <div style="padding:18px;">
                        <div class="calc-field-group">
                            <label>
                                <span>1. Cash in Hand &amp; Bank Accounts <span class="ur">(نقد رقم و بینک بیلنس)</span></span>
                                <small style="color:#64748b;">(PKR)</small>
                            </label>
                            <input type="number" id="inputCash" placeholder="0.00" min="0" step="any" oninput="calculateZakat()">
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="calc-field-group">
                                <label>
                                    <span>2. Gold Quantity (سونا تولے میں)</span>
                                    <small style="color:#64748b;">(Tolas)</small>
                                </label>
                                <input type="number" id="inputGoldTolas" placeholder="0.0" min="0" step="0.01" oninput="calculateZakat()">
                            </div>
                            <div class="calc-field-group">
                                <label>
                                    <span>Gold Value (سونے کی کل مالیت)</span>
                                    <small id="goldValDisp" style="color:#059669;font-weight:700;">Rs. 0</small>
                                </label>
                                <input type="number" id="inputGoldVal" placeholder="Calculated or enter manual" min="0" step="any" oninput="calculateZakat(true)">
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div class="calc-field-group">
                                <label>
                                    <span>3. Silver Quantity (چاندی تولے میں)</span>
                                    <small style="color:#64748b;">(Tolas)</small>
                                </label>
                                <input type="number" id="inputSilverTolas" placeholder="0.0" min="0" step="0.01" oninput="calculateZakat()">
                            </div>
                            <div class="calc-field-group">
                                <label>
                                    <span>Silver Value (چاندی کی کل مالیت)</span>
                                    <small id="silverValDisp" style="color:#059669;font-weight:700;">Rs. 0</small>
                                </label>
                                <input type="number" id="inputSilverVal" placeholder="Calculated or enter manual" min="0" step="any" oninput="calculateZakat(true)">
                            </div>
                        </div>

                        <div class="calc-field-group">
                            <label>
                                <span>4. Trade Merchandise &amp; Business Stock <span class="ur">(تجارتی مالِ فروخت)</span></span>
                                <small style="color:#64748b;">(Cost / Wholesale Price)</small>
                            </label>
                            <input type="number" id="inputTradeGoods" placeholder="0.00" min="0" step="any" oninput="calculateZakat()">
                        </div>

                        <div class="calc-field-group" style="margin-bottom:0;">
                            <label>
                                <span>5. Good Loans Receivable <span class="ur">(دیے گئے قرضے جو واپس ملنے کی امید ہو)</span></span>
                                <small style="color:#64748b;">(PKR)</small>
                            </label>
                            <input type="number" id="inputReceivables" placeholder="0.00" min="0" step="any" oninput="calculateZakat()">
                        </div>
                    </div>
                </article>

                <!-- Panel 3: Deductible Debts & Liabilities (واجب الادا منہا اخراجات) -->
                <article class="panel" style="border:1px solid #cbd5e1;">
                    <div class="panel-heading" style="background:#fff1f2;padding:12px 18px;border-bottom:1px solid #fecdd3;">
                        <h4 style="margin:0;font-size:14px;color:#9f1239;">💳 Immediate Liabilities &amp; Debts <span class="ur">(فوری واجب الادا قرضے و بلز)</span></h4>
                    </div>
                    <div style="padding:18px;">
                        <div class="calc-field-group">
                            <label>
                                <span>1. Debts Payable Immediately <span class="ur">(لوگوں کے دینے والے قرضے)</span></span>
                            </label>
                            <input type="number" id="inputDebts" placeholder="0.00" min="0" step="any" oninput="calculateZakat()">
                        </div>

                        <div class="calc-field-group" style="margin-bottom:0;">
                            <label>
                                <span>2. Due Business Bills &amp; Unpaid Salaries <span class="ur">(واجب الادا بلز و تنخواہیں)</span></span>
                            </label>
                            <input type="number" id="inputPendingBills" placeholder="0.00" min="0" step="any" oninput="calculateZakat()">
                        </div>
                    </div>
                </article>
            </div>

            <!-- Right Side: Realtime Calculation Summary & Voucher Generator -->
            <div>
                <div class="summary-card">
                    <h3 style="margin:0 0 16px;color:#0f172a;font-size:17px;display:flex;align-items:center;gap:8px;">
                        📊 Zakat Summary <span class="ur">(خلاصہ حسابِ زکوٰۃ)</span>
                    </h3>

                    <table class="breakdown-table">
                        <tr>
                            <td>Total Zakatable Wealth (کل اثاثے):</td>
                            <td id="resGrossAssets">Rs. 0</td>
                        </tr>
                        <tr>
                            <td style="color:#991b1b;">Less Deductions (منہا قرضے):</td>
                            <td id="resDeductions" style="color:#991b1b;">- Rs. 0</td>
                        </tr>
                        <tr style="border-top:1.5px solid #0f172a;border-bottom:1.5px solid #0f172a;">
                            <td style="padding:10px 0;font-weight:700;">Net Zakatable Wealth (خالص مال):</td>
                            <td id="resNetWealth" style="padding:10px 0;font-size:15px;color:#0f172a;">Rs. 0</td>
                        </tr>
                        <tr>
                            <td style="padding-top:10px;">Silver Nisab Threshold (حدِ نصاب):</td>
                            <td id="resNisabThreshold" style="padding-top:10px;">Rs. 168,000</td>
                        </tr>
                        <tr>
                            <td>Status (شرعی حیثیت):</td>
                            <td id="resNisabStatus"><span style="color:#64748b;">درج کریں</span></td>
                        </tr>
                    </table>

                    <!-- Result Box -->
                    <div class="result-box" id="resultBox">
                        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#166534;letter-spacing:1px;">
                            Total Zakat Due (واجب الادا زکوٰۃ 2.5%)
                        </div>
                        <div class="result-amount" id="resZakatAmount">
                            Rs. 0.00
                        </div>
                        <div id="resZakatWords" style="font-size:12px;color:#15803d;font-weight:600;margin-top:4px;">
                            Zero Rupees
                        </div>
                    </div>

                    <!-- Direct Action: Convert to Zakat Voucher in 1 Click -->
                    <div style="margin-top:20px;">
                        <button type="button" id="btnCreateVoucher" onclick="redirectToIncomeVoucher()" class="btn success" style="width:100%;justify-content:center;padding:12px;font-size:14px;font-weight:700;background:#15803d;color:#fff;border-radius:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
                            🚀 Create Zakat Income Voucher <span class="ur">(زکوٰۃ فنڈ واؤچر بنائیں)</span>
                        </button>
                        <small style="display:block;text-align:center;color:#64748b;font-size:11.5px;margin-top:8px;">
                            یہ بٹن اس رقم کا واؤچر زکوٰۃ فنڈ میں فوری درج کرنے کے لیے انکم پیج پر لے جائے گا۔
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <?=render_app_footer($madrasaName)?>
    </main>
</div>

<script>
var zakatFundId = <?=(int)$zakatFundId?>;

function num(val) {
    var n = parseFloat(val);
    return isNaN(n) ? 0 : n;
}

function formatMoney(amount) {
    return 'Rs. ' + Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function calculateZakat(isManualVal = false) {
    var silverRate = num(document.getElementById('silverRateInput').value) || 3200;
    var goldRate = num(document.getElementById('goldRateInput').value) || 285000;

    document.getElementById('dispSilverRate').textContent = Number(silverRate).toLocaleString();
    var nisabLimit = silverRate * 52.5;
    document.getElementById('dispNisabLimit').textContent = Number(nisabLimit).toLocaleString();
    document.getElementById('resNisabThreshold').textContent = 'Rs. ' + Number(nisabLimit).toLocaleString();

    // Gold Calculation
    var goldTolas = num(document.getElementById('inputGoldTolas').value);
    var goldVal = 0;
    if (!isManualVal) {
        goldVal = goldTolas * goldRate;
        document.getElementById('inputGoldVal').value = goldVal ? goldVal.toFixed(2) : '';
    } else {
        goldVal = num(document.getElementById('inputGoldVal').value);
    }
    document.getElementById('goldValDisp').textContent = formatMoney(goldVal);

    // Silver Calculation
    var silverTolas = num(document.getElementById('inputSilverTolas').value);
    var silverVal = 0;
    if (!isManualVal) {
        silverVal = silverTolas * silverRate;
        document.getElementById('inputSilverVal').value = silverVal ? silverVal.toFixed(2) : '';
    } else {
        silverVal = num(document.getElementById('inputSilverVal').value);
    }
    document.getElementById('silverValDisp').textContent = formatMoney(silverVal);

    // Other Assets
    var cash = num(document.getElementById('inputCash').value);
    var trade = num(document.getElementById('inputTradeGoods').value);
    var receivables = num(document.getElementById('inputReceivables').value);

    var grossAssets = cash + goldVal + silverVal + trade + receivables;
    document.getElementById('resGrossAssets').textContent = formatMoney(grossAssets);

    // Deductions
    var debts = num(document.getElementById('inputDebts').value);
    var bills = num(document.getElementById('inputPendingBills').value);
    var totalDeductions = debts + bills;
    document.getElementById('resDeductions').textContent = '- ' + formatMoney(totalDeductions);

    // Net Wealth
    var netWealth = Math.max(0, grossAssets - totalDeductions);
    document.getElementById('resNetWealth').textContent = formatMoney(netWealth);

    // Zakat Calculation (2.5%)
    var zakatDue = 0;
    var statusElem = document.getElementById('resNisabStatus');
    var resultBox = document.getElementById('resultBox');
    var btnVoucher = document.getElementById('btnCreateVoucher');

    if (netWealth >= nisabLimit && netWealth > 0) {
        zakatDue = netWealth * 0.025;
        statusElem.innerHTML = '<span style="color:#15803d;font-weight:700;">🟢 صاحبِ نصاب (زکوٰۃ واجب ہے)</span>';
        resultBox.style.background = '#f0fdf4';
        resultBox.style.borderColor = '#22c55e';
        btnVoucher.disabled = false;
        btnVoucher.style.opacity = '1';
    } else if (netWealth > 0) {
        zakatDue = 0;
        statusElem.innerHTML = '<span style="color:#b45309;font-weight:700;">⚪ نصاب سے کم (زکوٰۃ واجب نہیں)</span>';
        resultBox.style.background = '#f8fafc';
        resultBox.style.borderColor = '#cbd5e1';
    } else {
        statusElem.innerHTML = '<span style="color:#64748b;">درج کریں</span>';
        resultBox.style.background = '#f8fafc';
        resultBox.style.borderColor = '#cbd5e1';
    }

    document.getElementById('resZakatAmount').textContent = formatMoney(zakatDue);
    document.getElementById('resZakatWords').textContent = zakatDue > 0 ? (Number(zakatDue.toFixed(2)).toLocaleString() + ' روپے') : 'صفر روپے';
}

function redirectToIncomeVoucher() {
    var rawText = document.getElementById('resZakatAmount').textContent.replace(/[^0-9.]/g, '');
    var amount = parseFloat(rawText) || 0;
    if (amount <= 0) {
        alert('براہ کرم پہلے اثاثہ جات درج کریں تاکہ زکوٰۃ کی رقم کا حساب ہو سکے۔');
        return;
    }

    var url = 'income.php?prefill_fund=zakat&prefill_amount=' + encodeURIComponent(amount.toFixed(2)) + '&prefill_desc=' + encodeURIComponent('ادائیگی زکوٰۃ برائے فلاحی و دینی مقاصد (Zakat Contribution)');
    window.location.href = url;
}

window.addEventListener('DOMContentLoaded', function() {
    calculateZakat();
});
</script>
</body>
</html>
