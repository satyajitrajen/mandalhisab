<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hisab - {{ $festival->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #333; }
        h1 { font-size: 24px; margin-bottom: 10px; }
        h2 { font-size: 18px; margin-top: 30px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; background-color: #fafafa; }
        .signatures { margin-top: 40px; }
        .signature-block { display: inline-block; width: 45%; vertical-align: top; }
        .status { margin-top: 20px; padding: 10px; background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Final Hisab Report</h1>
    <p><strong>Festival:</strong> {{ $festival->name }} ({{ $festival->year }})</p>
    <p><strong>Date:</strong> {{ now()->format('d M Y') }}</p>

    <h2>Financial Summary</h2>
    <table>
        <thead>
            <tr>
                <th>Particular</th>
                <th class="text-right">Amount (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Opening Balance</td>
                <td class="text-right">{{ number_format($openingBalance, 2) }}</td>
            </tr>
            <tr>
                <td>Vargani Total</td>
                <td class="text-right">{{ number_format($varganiTotal, 2) }}</td>
            </tr>
            <tr>
                <td>Other Income Total</td>
                <td class="text-right">{{ number_format($otherIncomeTotal, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Total Income</td>
                <td class="text-right">{{ number_format($totalIncome, 2) }}</td>
            </tr>
            <tr>
                <td>Total Expenses</td>
                <td class="text-right">{{ number_format($totalExpenses, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td>Closing Balance</td>
                <td class="text-right">{{ number_format($closingBalance, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Signatures</h2>
    <div class="signatures">
        <div class="signature-block">
            <p><strong>President</strong></p>
            <p>Signed: {{ $presidentSigned ? 'Yes' : 'No' }}</p>
            @if($presidentSignedAt)
                <p>Date: {{ $presidentSignedAt->format('d M Y H:i') }}</p>
            @endif
        </div>
        <div class="signature-block">
            <p><strong>Treasurer</strong></p>
            <p>Signed: {{ $treasurerSigned ? 'Yes' : 'No' }}</p>
            @if($treasurerSignedAt)
                <p>Date: {{ $treasurerSignedAt->format('d M Y H:i') }}</p>
            @endif
        </div>
    </div>

    <div class="status">
        <p><strong>Status:</strong> {{ $isLocked ? 'Locked' : 'Pending Signatures' }}</p>
    </div>
</body>
</html>
