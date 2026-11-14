<?php
declare(strict_types=1);

function agrogearOrderPdfText(string $text, float $x, float $top, int $size = 10, string $font = 'F1', array $color = [0.12, 0.18, 0.16]): string
{
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if ($encoded === false) {
        throw new RuntimeException('Could not encode order text for the PDF.');
    }

    $encoded = preg_replace('/[^\x20-\x7E]/', '?', $encoded) ?? '';
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

    return sprintf(
        "BT /%s %d Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
        $font,
        $size,
        $color[0],
        $color[1],
        $color[2],
        $x,
        842 - $top,
        $escaped
    );
}

function agrogearOrderPdfRect(float $x, float $top, float $width, float $height, array $color): string
{
    return sprintf(
        "q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q\n",
        $color[0],
        $color[1],
        $color[2],
        $x,
        842 - $top - $height,
        $width,
        $height
    );
}

function agrogearOrderPdfLine(float $x1, float $top1, float $x2, float $top2, array $color = [0.86, 0.89, 0.87]): string
{
    return sprintf(
        "q %.3F %.3F %.3F RG 0.7 w %.2F %.2F m %.2F %.2F l S Q\n",
        $color[0],
        $color[1],
        $color[2],
        $x1,
        842 - $top1,
        $x2,
        842 - $top2
    );
}

function agrogearOrderPdfWrap(string $text, int $limit): array
{
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($words === []) {
        return [''];
    }

    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $parts = mb_str_split($word, $limit, 'UTF-8');
        foreach ($parts as $part) {
            if ($line !== '' && mb_strlen($line, 'UTF-8') + mb_strlen($part, 'UTF-8') + 1 > $limit) {
                $lines[] = $line;
                $line = '';
            }
            $line = $line === '' ? $part : $line . ' ' . $part;
        }
    }
    $lines[] = $line;

    return $lines;
}

function createAgrogearOrderPdf(array $order): string
{
    $darkGreen = [0.09, 0.25, 0.20];
    $green = [0.16, 0.42, 0.32];
    $lightGreen = [0.91, 0.96, 0.93];
    $muted = [0.38, 0.45, 0.42];
    $ink = [0.12, 0.18, 0.16];
    $pages = [];
    $content = '';

    $startPage = static function (bool $continued) use (&$content, $darkGreen, $green, $order): void {
        $content = agrogearOrderPdfRect(0, 0, 595, 112, $darkGreen);
        $content .= agrogearOrderPdfText('AGROGEAR', 42, 48, 23, 'F2', [1, 1, 1]);
        $content .= agrogearOrderPdfText('Agricultural equipment', 43, 69, 10, 'F1', [0.83, 0.91, 0.87]);
        $content .= agrogearOrderPdfText($continued ? 'ORDER DETAILS - CONTINUED' : 'DELIVERY ORDER', 370, 45, 11, 'F2', [1, 1, 1]);
        $content .= agrogearOrderPdfText('Order ' . $order['number'], 370, 68, 10, 'F1', [1, 1, 1]);
        $content .= agrogearOrderPdfText($order['date'], 370, 88, 9, 'F1', [0.83, 0.91, 0.87]);
        $content .= agrogearOrderPdfRect(42, 111, 58, 4, $green);
    };

    $startPage(false);
    $content .= agrogearOrderPdfText('Order details', 42, 151, 18, 'F2', $darkGreen);
    $content .= agrogearOrderPdfRect(42, 166, 246, 112, [0.97, 0.98, 0.97]);
    $content .= agrogearOrderPdfRect(307, 166, 246, 112, [0.97, 0.98, 0.97]);
    $content .= agrogearOrderPdfText('BUYER', 55, 184, 9, 'F2', $green);
    $content .= agrogearOrderPdfText('PAYMENT', 315, 184, 9, 'F2', $green);
    $content .= agrogearOrderPdfText($order['buyer_name'], 55, 207, 11, 'F2', $ink);
    $content .= agrogearOrderPdfText($order['buyer_email'], 55, 229, 9, 'F1', $muted);
    $content .= agrogearOrderPdfText('Phone: ' . $order['buyer_phone'], 55, 251, 9, 'F1', $ink);
    $content .= agrogearOrderPdfText('M-Pesa reference', 320, 207, 9, 'F1', $muted);
    $content .= agrogearOrderPdfText($order['mpesa_reference'], 320, 225, 11, 'F2', $ink);
    $content .= agrogearOrderPdfText('Payment: buyer confirmed; verify manually', 320, 247, 8, 'F1', $muted);
    $content .= agrogearOrderPdfText('ORDER TOTAL', 320, 267, 8, 'F2', $green);
    $content .= agrogearOrderPdfText('KSh ' . number_format($order['total']), 432, 267, 12, 'F2', $darkGreen);

    $addressLines = [];
    foreach ([
        'County' => $order['county'],
        'Town/area' => $order['town'],
        'Street/road' => $order['street'],
        'Estate/building' => $order['estate_building'],
        'Nearby landmark' => $order['landmark'],
        'Delivery notes' => $order['delivery_notes'],
    ] as $label => $value) {
        if ($value !== '') {
            foreach (agrogearOrderPdfWrap($label . ': ' . $value, 86) as $line) {
                $addressLines[] = $line;
            }
        }
    }

    $content .= agrogearOrderPdfText('DELIVERY ADDRESS', 42, 306, 9, 'F2', $green);
    $addressY = 326;
    foreach ($addressLines as $line) {
        $content .= agrogearOrderPdfText($line, 42, $addressY, 9, 'F1', $ink);
        $addressY += 15;
    }

    $drawTableHeader = static function (int $top) use (&$content, $darkGreen, $green, $lightGreen, $muted): void {
        $content .= agrogearOrderPdfText('ITEMS', 42, $top, 9, 'F2', $green);
        $content .= agrogearOrderPdfRect(42, $top + 9, 511, 24, $lightGreen);
        $content .= agrogearOrderPdfText('Product', 52, $top + 25, 9, 'F2', $darkGreen);
        $content .= agrogearOrderPdfText('Qty', 361, $top + 25, 9, 'F2', $darkGreen);
        $content .= agrogearOrderPdfText('Unit price', 408, $top + 25, 9, 'F2', $darkGreen);
        $content .= agrogearOrderPdfText('Amount', 493, $top + 25, 9, 'F2', $darkGreen);
        $content .= agrogearOrderPdfLine(42, $top + 33, 553, $top + 33, $muted);
    };

    $rowY = $addressY + 36;
    $drawTableHeader($rowY);
    $rowY += 52;
    foreach ($order['items'] as $item) {
        $nameLines = agrogearOrderPdfWrap($item['name'], 48);
        $rowHeight = max(28, count($nameLines) * 13 + 13);
        if ($rowY + $rowHeight > 766) {
            $pages[] = $content;
            $startPage(true);
            $drawTableHeader(133);
            $rowY = 185;
        }

        foreach ($nameLines as $lineIndex => $line) {
            $content .= agrogearOrderPdfText($line, 52, $rowY + 13 + ($lineIndex * 13), 9, 'F1', $ink);
        }
        $content .= agrogearOrderPdfText((string) $item['quantity'], 365, $rowY + 13, 9, 'F1', $ink);
        $content .= agrogearOrderPdfText('KSh ' . number_format($item['unit_price']), 408, $rowY + 13, 8, 'F1', $ink);
        $content .= agrogearOrderPdfText('KSh ' . number_format($item['line_total']), 493, $rowY + 13, 8, 'F1', $ink);
        $content .= agrogearOrderPdfLine(42, $rowY + $rowHeight, 553, $rowY + $rowHeight);
        $rowY += $rowHeight;
    }

    if ($rowY + 116 > 766) {
        $pages[] = $content;
        $startPage(true);
        $rowY = 145;
    }
    $content .= agrogearOrderPdfRect(365, $rowY + 8, 188, 34, $lightGreen);
    $content .= agrogearOrderPdfText('TOTAL', 378, $rowY + 30, 10, 'F2', $darkGreen);
    $content .= agrogearOrderPdfText('KSh ' . number_format($order['total']), 465, $rowY + 30, 11, 'F2', $darkGreen);
    $content .= agrogearOrderPdfText('Payment is subject to manual M-Pesa verification.', 42, $rowY + 31, 8, 'F1', $muted);
    $content .= agrogearOrderPdfRect(42, $rowY + 56, 511, 52, [0.91, 0.96, 0.93]);
    $content .= agrogearOrderPdfRect(42, $rowY + 56, 5, 52, $green);
    $content .= agrogearOrderPdfText('DELIVERY ESTIMATE', 58, $rowY + 75, 9, 'F2', $darkGreen);
    $content .= agrogearOrderPdfText('Delivery usually takes 3-5 days after payment and delivery details are received.', 58, $rowY + 94, 9, 'F2', $ink);
    $content .= agrogearOrderPdfText('Timing may vary depending on your location.', 58, $rowY + 106, 8, 'F1', $muted);
    $pages[] = $content;

    $pageCount = count($pages);
    foreach ($pages as $index => &$pageContent) {
        $pageContent .= agrogearOrderPdfLine(42, 801, 553, 801);
        $pageContent .= agrogearOrderPdfText('Agrogear  |  Order ' . $order['number'], 42, 819, 8, 'F1', $muted);
        $pageContent .= agrogearOrderPdfText('Page ' . ($index + 1) . ' of ' . $pageCount, 490, 819, 8, 'F1', $muted);
    }
    unset($pageContent);

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($pages as $index => $pageContent) {
        $pageObject = 5 + ($index * 2);
        $streamObject = $pageObject + 1;
        $pageReferences[] = $pageObject . ' 0 R';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamObject . ' 0 R >>';
        $objects[$streamObject] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . $pageCount . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n% Agrogear order\n";
    $offsets = [0];
    foreach ($objects as $objectNumber => $objectBody) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $objectCount = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 " . $objectCount . "\n0000000000 65535 f \n";
    for ($number = 1; $number < $objectCount; $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    $pdf .= "trailer\n<< /Size " . $objectCount . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}
