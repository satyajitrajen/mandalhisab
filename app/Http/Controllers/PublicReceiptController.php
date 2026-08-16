<?php

namespace App\Http\Controllers;

use App\Models\VarganiEntry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublicReceiptController extends Controller
{
    /**
     * Display the authentic public web receipt for a given receipt identifier.
     */
    public function show(string $id)
    {
        // Try resolving by primary ID, receipt_number, or client_uuid
        $cleanId = trim($id);
        $entry = VarganiEntry::with(['festival.mandal', 'collector'])
            ->where('id', $cleanId)
            ->orWhere('receipt_number', $cleanId)
            ->orWhere('receipt_number', 'Receipt #' . $cleanId)
            ->orWhere('receipt_number', '#' . $cleanId)
            ->orWhere('client_uuid', $cleanId)
            ->first();

        $mandalName = $entry?->festival?->mandal?->name ?? 'सार्वजनिक गणेशोत्सव मंडळ';
        $festivalName = $entry?->festival?->name ?? 'गणेशोत्सव २०२६';
        $donorName = $entry?->donor_name ?? 'भाविक / वर्गणीदार';
        $amount = (float) ($entry?->amount ?? 0);
        $receiptNumber = $entry?->receipt_number ?? $cleanId;
        $dateText = $entry?->created_at ? $entry->created_at->format('d M Y') : date('d M Y');
        $paymentMode = $entry?->payment_mode?->value ?? 'CASH';
        $area = $entry?->area ?? '';
        $mobileNumber = $entry?->mobile_number ?? '';
        $collectorName = $entry?->collector?->full_name ?? ($entry?->collector?->name ?? 'अधिकृत प्रतिनिधी');
        $isCancelled = (bool) ($entry?->is_cancelled ?? false);

        $amountInWordsMarathi = self::amountInWords($amount, true);
        $amountInWordsEnglish = self::amountInWords($amount, false);

        return view('public_receipt', [
            'entry' => $entry,
            'receiptId' => $cleanId,
            'mandalName' => $mandalName,
            'festivalName' => $festivalName,
            'donorName' => $donorName,
            'amount' => $amount,
            'receiptNumber' => $receiptNumber,
            'dateText' => $dateText,
            'paymentMode' => strtoupper($paymentMode),
            'area' => $area,
            'mobileNumber' => $mobileNumber,
            'collectorName' => $collectorName,
            'isCancelled' => $isCancelled,
            'amountInWordsMarathi' => $amountInWordsMarathi,
            'amountInWordsEnglish' => $amountInWordsEnglish,
        ]);
    }

    /**
     * Converts numeric amounts to authentic Marathi or English words.
     */
    public static function amountInWords(float $number, bool $isMarathi = false): string
    {
        $num = (int) round($number);
        if ($num <= 0) {
            return $isMarathi ? 'शून्य रुपये फक्त' : 'Zero Rupees Only';
        }

        if ($isMarathi) {
            return self::marathiWords($num) . ' रुपये फक्त';
        } else {
            return self::englishWords($num) . ' Rupees Only';
        }
    }

    private static function marathiWords(int $n): string
    {
        $ones = [
            0 => '', 1 => 'एक', 2 => 'दोन', 3 => 'तीन', 4 => 'चार', 5 => 'पाच',
            6 => 'सहा', 7 => 'सात', 8 => 'आठ', 9 => 'नऊ', 10 => 'दहा',
            11 => 'अकरा', 12 => 'बारा', 13 => 'तेरा', 14 => 'चौदा', 15 => 'पंधरा',
            16 => 'सोळा', 17 => 'सतरा', 18 => 'अठरा', 19 => 'एकोणीस', 20 => 'वीस',
            21 => 'एकवीस', 22 => 'बावीस', 23 => 'तेवीस', 24 => 'चोवीस', 25 => 'पंचवीस',
            26 => 'सव्वीस', 27 => 'सत्तावीस', 28 => 'अठ्ठावीस', 29 => 'एकोणतीस', 30 => 'तीस',
            31 => 'एकतीस', 32 => 'बत्तीस', 33 => 'तेहतीस', 34 => 'चौतीस', 35 => 'पस्तीस',
            36 => 'छत्तीस', 37 => 'सदतीस', 38 => 'अडतीस', 39 => 'एकेचाळीस', 40 => 'चाळीस',
            41 => 'एक्केचाळीस', 42 => 'बेचाळीस', 43 => 'त्रेचाळीस', 44 => 'चव्वेचाळीस', 45 => 'पंचेचाळीस',
            46 => 'शेहेचाळीस', 47 => 'सत्तेचाळीस', 48 => 'अठ्ठेचाळीस', 49 => 'एकोणपन्नास', 50 => 'पन्नास',
            51 => 'एक्कावन्न', 52 => 'बावन्न', 53 => 'त्रेपन्न', 54 => 'चौपन्न', 55 => 'पंचावन्न',
            56 => 'छप्पन्न', 57 => 'सत्तावन्न', 58 => 'अठ्ठावन्न', 59 => 'एकोणसाठ', 60 => 'साठ',
            61 => 'एकसष्ठ', 62 => 'पासष्ठ', 63 => 'त्रेसष्ठ', 64 => 'चौसष्ठ', 65 => 'पासष्ठ',
            66 => 'सहासष्ठ', 67 => 'सदुसष्ठ', 68 => 'अडुसष्ठ', 69 => 'एकोणसत्तर', 70 => 'सत्तर',
            71 => 'एकाहत्तर', 72 => 'बाहत्तर', 73 => 'त्र्याहत्तर', 74 => 'चौर्‍याहत्तर', 75 => 'पंच्याहत्तर',
            76 => 'शहात्तर', 77 => 'सत्त्याहत्तर', 78 => 'अठ्ठ्याहत्तर', 79 => 'एकोणऐंशी', 80 => 'ऐंशी',
            81 => 'एक्याऐंशी', 82 => 'ब्याऐंशी', 83 => 'त्र्याऐंशी', 84 => 'चौऱ्याऐंशी', 85 => 'पंच्याऐंशी',
            86 => 'शहाऐंशी', 87 => 'सत्त्याऐंशी', 88 => 'अठ्ठ्याऐंशी', 89 => 'एकोणनव्वद', 90 => 'नव्वद',
            91 => 'एक्याण्णव', 92 => 'ब्याण्णव', 93 => 'त्र्याण्णव', 94 => 'चौऱ्याण्णव', 95 => 'पंच्याण्णव',
            96 => 'शहाण्णव', 97 => 'सत्त्याण्णव', 98 => 'अठ्ठ्याण्णव', 99 => 'नव्व्याण्णव',
        ];

        if ($n < 100) {
            return $ones[$n] ?? '';
        }
        if ($n < 1000) {
            $hundreds = (int) ($n / 100);
            $rem = $n % 100;
            $res = ($ones[$hundreds] ?? '') . 'शे';
            if ($rem > 0) {
                $res .= ' ' . ($ones[$rem] ?? '');
            }
            return trim($res);
        }
        if ($n < 100000) {
            $thousands = (int) ($n / 1000);
            $rem = $n % 1000;
            $res = self::marathiWords($thousands) . ' हजार';
            if ($rem > 0) {
                $res .= ' ' . self::marathiWords($rem);
            }
            return trim($res);
        }
        if ($n < 10000000) {
            $lakhs = (int) ($n / 100000);
            $rem = $n % 100000;
            $res = self::marathiWords($lakhs) . ' लाख';
            if ($rem > 0) {
                $res .= ' ' . self::marathiWords($rem);
            }
            return trim($res);
        }
        $crores = (int) ($n / 10000000);
        $rem = $n % 10000000;
        $res = self::marathiWords($crores) . ' कोटी';
        if ($rem > 0) {
            $res .= ' ' . self::marathiWords($rem);
        }
        return trim($res);
    }

    private static function englishWords(int $n): string
    {
        $ones = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        ];
        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
        ];

        if ($n < 20) {
            return $ones[$n] ?? '';
        }
        if ($n < 100) {
            $t = (int) ($n / 10);
            $rem = $n % 10;
            return trim(($tens[$t] ?? '') . ($rem > 0 ? ' ' . $ones[$rem] : ''));
        }
        if ($n < 1000) {
            $h = (int) ($n / 100);
            $rem = $n % 100;
            $res = ($ones[$h] ?? '') . ' Hundred';
            if ($rem > 0) {
                $res .= ' ' . self::englishWords($rem);
            }
            return trim($res);
        }
        if ($n < 100000) {
            $th = (int) ($n / 1000);
            $rem = $n % 1000;
            $res = self::englishWords($th) . ' Thousand';
            if ($rem > 0) {
                $res .= ' ' . self::englishWords($rem);
            }
            return trim($res);
        }
        if ($n < 10000000) {
            $lakhs = (int) ($n / 100000);
            $rem = $n % 100000;
            $res = self::englishWords($lakhs) . ' Lakh';
            if ($rem > 0) {
                $res .= ' ' . self::englishWords($rem);
            }
            return trim($res);
        }
        $cr = (int) ($n / 10000000);
        $rem = $n % 10000000;
        $res = self::englishWords($cr) . ' Crore';
        if ($rem > 0) {
            $res .= ' ' . self::englishWords($rem);
        }
        return trim($res);
    }
}
