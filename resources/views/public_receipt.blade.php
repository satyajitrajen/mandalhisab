<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>पावती {{ $receiptNumber }} - {{ $mandalName }}</title>
    
    <!-- Open Graph for rich link previews in WhatsApp & Social Media -->
    <meta property="og:title" content="🚩 {{ $mandalName }} - अधिकृत वर्गणी पावती" />
    <meta property="og:description" content="वर्गणीदार: {{ $donorName }} | रक्कम: ₹{{ number_format($amount, 0) }} ({{ $amountInWordsMarathi }}) | पावती क्र: {{ $receiptNumber }}" />
    <meta property="og:type" content="article" />
    <meta property="og:site_name" content="मंडळ हिशोब (MandalHishob)" />
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-saffron: #D35400;
            --saffron-dark: #A04000;
            --saffron-light: #FFF3E0;
            --crimson: #8E1B1B;
            --gold: #D4AF37;
            --gold-light: #FCF3CF;
            --dark-text: #2C3E50;
            --light-bg: #FAF8F5;
            --card-bg: #FFFFFF;
            --border-color: #F0D3A2;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Mukta', 'Poppins', sans-serif;
            background-color: var(--light-bg);
            color: var(--dark-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px 48px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        .receipt-card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 2px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(211, 84, 0, 0.08), 0 1px 3px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            padding: 24px 20px;
        }

        /* Festive corner flourishes */
        .receipt-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--crimson), var(--primary-saffron), var(--gold), var(--primary-saffron), var(--crimson));
        }

        .sacred-header {
            text-align: center;
            margin-bottom: 12px;
        }

        .invocation {
            display: inline-block;
            font-size: 13px;
            font-weight: 700;
            color: var(--crimson);
            letter-spacing: 0.5px;
        }

        .ganpati-emblem {
            width: 56px;
            height: 56px;
            margin: 8px auto 10px;
            background: var(--saffron-light);
            border: 2px solid var(--gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
        }

        .mandal-name {
            font-size: 20px;
            font-weight: 800;
            color: #1A252F;
            line-height: 1.25;
            text-align: center;
            margin-bottom: 4px;
        }

        .festival-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-saffron);
            text-align: center;
        }

        .receipt-badge {
            font-size: 11px;
            color: #7F8C8D;
            text-align: center;
            margin-bottom: 14px;
        }

        .ornate-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 12px 0;
            color: var(--gold);
        }

        .ornate-divider::before, .ornate-divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px dashed var(--gold);
            opacity: 0.6;
        }

        .ornate-divider span {
            padding: 0 10px;
            font-size: 12px;
        }

        .meta-strip {
            background: #FBF9F5;
            border: 1px solid #EFE4D2;
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            margin-bottom: 14px;
        }

        .meta-strip .rec-no {
            font-weight: 700;
            color: var(--primary-saffron);
        }

        .meta-strip .date {
            font-weight: 600;
            color: #555;
        }

        .info-box {
            background: #FFFFFF;
            border: 1px solid #EADDC9;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 13px;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: #7F8C8D;
            font-weight: 500;
        }

        .info-val {
            font-weight: 700;
            color: #2C3E50;
            text-align: right;
        }

        .donor-title {
            font-size: 11px;
            color: var(--primary-saffron);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .donor-name-large {
            font-size: 17px;
            font-weight: 800;
            color: #1A252F;
            margin-bottom: 4px;
        }

        .amount-card {
            background: linear-gradient(135deg, #FFF3E0 0%, #FFE0B2 100%);
            border: 1.5px solid #FFB74D;
            border-radius: 12px;
            padding: 14px 16px;
            text-align: center;
            margin-bottom: 14px;
        }

        .amount-label {
            font-size: 11px;
            font-weight: 700;
            color: #E65100;
            letter-spacing: 0.5px;
        }

        .amount-number {
            font-size: 32px;
            font-weight: 800;
            color: #BF360C;
            line-height: 1.2;
            font-family: 'Poppins', sans-serif;
            margin: 4px 0;
        }

        .amount-words {
            font-size: 12px;
            font-weight: 600;
            color: #5D4037;
            font-style: italic;
        }

        .blessing-card {
            background: var(--gold-light);
            border: 1px dashed var(--gold);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 11.5px;
            line-height: 1.4;
            color: #795548;
            text-align: center;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .sign-seal-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 14px;
        }

        .seal-box {
            border: 1px solid var(--crimson);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 9px;
            color: var(--crimson);
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sig-box {
            text-align: right;
        }

        .sig-line {
            width: 80px;
            border-bottom: 1.5px solid #444;
            margin-left: auto;
            margin-bottom: 4px;
        }

        .sig-text {
            font-size: 10px;
            color: #777;
            font-weight: 600;
        }

        .footer-note {
            text-align: center;
            font-size: 10px;
            color: #95A5A6;
            margin-top: 14px;
        }

        /* Action Buttons */
        .actions-container {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        .btn {
            flex: 1;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            font-family: 'Mukta', 'Poppins', sans-serif;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #25D366;
            color: white;
        }

        .btn-primary:hover {
            background: #1EBE5B;
        }

        .btn-secondary {
            background: #FFFFFF;
            color: var(--dark-text);
            border: 1px solid #CCC;
        }

        .btn-secondary:hover {
            background: #F0F0F0;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .actions-container, .footer-note {
                display: none !important;
            }
            .receipt-card {
                border: 2px solid #000;
                box-shadow: none;
                max-width: 100%;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="receipt-card">
        <!-- Sacred Header -->
        <div class="sacred-header">
            <div class="invocation">🚩 ॥ श्री गणेशाय नमः ॥ 🚩</div>
            
            <div class="ganpati-emblem">
                <svg width="34" height="34" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="46" stroke="#D4AF37" stroke-width="3" fill="#FFF3E0"/>
                    <path d="M50 18 C46 18, 42 22, 42 28 C42 34, 46 38, 50 38 C54 38, 58 34, 58 28 C58 22, 54 18, 50 18 Z" fill="#8E1B1B"/>
                    <path d="M38 32 C32 36, 28 44, 28 54 C28 66, 36 76, 50 76 C64 76, 72 66, 72 54 C72 44, 68 36, 62 32" stroke="#D35400" stroke-width="4" stroke-linecap="round" fill="none"/>
                    <path d="M50 38 C48 48, 48 58, 52 64 C54 67, 58 68, 60 65 C62 62, 60 58, 56 58" stroke="#8E1B1B" stroke-width="3.5" stroke-linecap="round" fill="none"/>
                    <circle cx="36" cy="46" r="3.5" fill="#D35400"/>
                    <circle cx="64" cy="46" r="3.5" fill="#D35400"/>
                    <path d="M48 24 L52 24" stroke="#D4AF37" stroke-width="2"/>
                    <circle cx="50" cy="28" r="1.5" fill="#FFF"/>
                </svg>
            </div>

            <h1 class="mandal-name">{{ $mandalName }}</h1>
            <div class="festival-name">॥ {{ $festivalName }} ॥</div>
            <div class="receipt-badge">अधिकृत डिजिटल वर्गणी पावती (Official Digital Receipt)</div>
        </div>

        <!-- Meta Strip -->
        <div class="meta-strip">
            <div class="rec-no">पावती क्र. <span>{{ $receiptNumber }}</span></div>
            <div class="date">📅 {{ $dateText }}</div>
        </div>

        <!-- Donor Block -->
        <div class="info-box">
            <div class="donor-title">वर्गणीदार / देणगीदार (Donor)</div>
            <div class="donor-name-large">{{ $donorName }}</div>
            @if($mobileNumber)
                <div class="info-row">
                    <span class="info-label">मोबाईल (Mobile):</span>
                    <span class="info-val">+91 {{ $mobileNumber }}</span>
                </div>
            @endif
            @if($area)
                <div class="info-row">
                    <span class="info-label">विभाग / एरिया (Area):</span>
                    <span class="info-val">{{ $area }}</span>
                </div>
            @endif
        </div>

        <!-- Amount Box -->
        <div class="amount-card">
            <div class="amount-label">जमा वर्गणी रक्कम (RECEIVED AMOUNT)</div>
            <div class="amount-number">₹{{ number_format($amount, 0) }}</div>
            <div class="amount-words">अक्षरी: {{ $amountInWordsMarathi }}</div>
            <div class="amount-words" style="font-size: 10.5px; opacity: 0.85;">(In Words: {{ $amountInWordsEnglish }})</div>
        </div>

        <!-- Details Block -->
        <div class="info-box">
            <div class="info-row">
                <span class="info-label">भरणा प्रकार (Payment Mode):</span>
                <span class="info-val" style="color: #27AE60;">{{ $paymentMode }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">पावती संकलक (Collector):</span>
                <span class="info-val">{{ $collectorName }}</span>
            </div>
            @if($isCancelled)
                <div class="info-row">
                    <span class="info-label">स्थिती (Status):</span>
                    <span class="info-val" style="color: #E74C3C;">रद्द (CANCELLED)</span>
                </div>
            @endif
        </div>

        <!-- Blessing Note -->
        <div class="blessing-card">
            🌺 श्रींच्या चरणी आपण दिलेली वर्गणी सप्रेम स्वीकारली. श्री गणेश आपल्या सर्व मनोकामना पूर्ण करो हीच प्रार्थना! धन्यवाद! 🌺
        </div>

        <div class="ornate-divider">
            <span>🚩</span>
        </div>

        <!-- Signatures and Seals -->
        <div class="sign-seal-row">
            <div class="seal-box">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                <span>VERIFIED RECEIPT</span>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-text">अध्यक्ष / खजिनदार स्वाक्षरी</div>
            </div>
        </div>

        <div class="footer-note">
            पावती तयार केली: मंडळ हिशोब (MandalHishob) • डिजिटल अकाउंटिंग प्लॅटफॉर्म
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="actions-container">
        <button class="btn btn-secondary" onclick="window.print()">
            🖨️ पावती प्रिंट करा / PDF
        </button>
        <button class="btn btn-primary" onclick="shareReceipt()">
            💬 शेअर करा
        </button>
    </div>
</div>

<script>
    function shareReceipt() {
        const text = "🚩 {{ $mandalName }} 🚩\n{{ $festivalName }} • वर्गणी पावती\n\nवर्गणीदार: {{ $donorName }}\nरक्कम: ₹{{ number_format($amount, 0) }} ({{ $amountInWordsMarathi }})\nपावती क्र: {{ $receiptNumber }}\n\n🔗 अधिकृत पावती लिंक:\n" + window.location.href;
        
        if (navigator.share) {
            navigator.share({
                title: '{{ $mandalName }} पावती',
                text: text,
                url: window.location.href,
            }).catch(() => {});
        } else {
            window.open('https://api.whatsapp.com/send?text=' + encodeURIComponent(text), '_blank');
        }
    }
</script>

</body>
</html>
