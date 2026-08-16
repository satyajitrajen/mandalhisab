<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #{{ $entry->receipt_number }}</title>
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
        <h2>{{ $entry->festival?->mandal?->name ?? 'Mandal' }}</h2>
        <p>{{ $entry->festival?->name }} {{ $entry->festival?->year }}</p>
    </div>
    <div class="receipt-box">
        <h3 style="text-align:center;">Official Receipt</h3>
        <div class="row"><span class="label">Receipt No:</span> <span>{{ $entry->receipt_number }}</span></div>
        <div class="row"><span class="label">Date:</span> <span>{{ $entry->created_at?->format('d M Y') }}</span></div>
        <div class="row"><span class="label">Donor:</span> <span>{{ $entry->donor_name }}</span></div>
        @if($entry->mobile_number)
            <div class="row"><span class="label">Mobile:</span> <span>{{ $entry->mobile_number }}</span></div>
        @endif
        <div class="row"><span class="label">Amount:</span> <span>Rs. {{ number_format($entry->amount, 2) }}</span></div>
        <div class="row"><span class="label">Payment Mode:</span> <span>{{ $entry->payment_mode->value }}</span></div>
        <div class="row"><span class="label">Collector:</span> <span>{{ $entry->collector?->full_name ?? '—' }}</span></div>
        @if($entry->area)
            <div class="row"><span class="label">Area:</span> <span>{{ $entry->area }}</span></div>
        @endif
        <hr>
        <p style="text-align:center; font-size:12px; color:#666;">Thank you for your contribution!</p>
    </div>
</body>
</html>