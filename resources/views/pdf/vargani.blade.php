<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #{{ $vargani->receipt_number }}</title>
    <style>
        body { font-family: sans-serif; padding: 40px; }
        .header { text-align: center; margin-bottom: 30px; }
        .receipt-box { border: 2px solid #333; padding: 20px; max-width: 500px; margin: 0 auto; }
        .row { display: flex; justify-content: space-between; margin: 10px 0; }
        .label { font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h2>{{ $vargani->festival->mandal->name ?? 'Mandal' }}</h2>
        <p>{{ $vargani->festival->name }} {{ $vargani->festival->year }}</p>
    </div>
    <div class="receipt-box">
        <h3 style="text-align:center;">Official Receipt</h3>
        <div class="row"><span class="label">Receipt No:</span> <span>{{ $vargani->receipt_number }}</span></div>
        <div class="row"><span class="label">Date:</span> <span>{{ $vargani->created_at?->format('d M Y') }}</span></div>
        <div class="row"><span class="label">Donor:</span> <span>{{ $vargani->donor_name }}</span></div>
        <div class="row"><span class="label">Amount:</span> <span>Rs. {{ number_format($vargani->amount, 2) }}</span></div>
        <div class="row"><span class="label">Payment Mode:</span> <span>{{ $vargani->payment_mode->value }}</span></div>
        <div class="row"><span class="label">Area:</span> <span>{{ $vargani->area }}</span></div>
        <hr>
        <p style="text-align:center; font-size:12px; color:#666;">Thank you for your contribution!</p>
    </div>
</body>
</html>
