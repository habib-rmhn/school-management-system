<?php

if (!defined('BN_FONT')) {
    define('BN_FONT', 'nikosh');
}

function hasBangla(string $text): bool
{
    return preg_match('/\p{Bengali}/u', $text) === 1;
}

/*
|--------------------------------------------------------------------------
| Converts mixed English/Bangla text to TCPDF HTML
|--------------------------------------------------------------------------
|
| Example:
|
| Habibur Rahman (হাবিব)
|
| becomes
|
| Habibur Rahman (<span style="font-family:nikosh;">হাবিব</span>)
|
*/

function pdfMixedHtml(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return preg_replace_callback(
        '/[\p{Bengali}]+/u',
        function ($m) {
            return '<span style="font-family:' . BN_FONT . ';">'
                    . $m[0] .
                   '</span>';
        },
        $text
    );
}

/*
|--------------------------------------------------------------------------
| Draw bordered cell with automatic Bangla detection
|--------------------------------------------------------------------------
*/

function mixedCell(
    TCPDF $pdf,
    float $w,
    float $h,
    string $text,
    int|string $border = 1,
    int $ln = 0,
    string $align = 'L',
    bool $fill = true
)
{
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // draw border/background
    $pdf->Cell(
        $w,
        $h,
        '',
        $border,
        0,
        $align,
        $fill
    );

    // write html over the cell
    $pdf->SetXY($x + 1.2, $y + 0.7);

    $html = '<span style="font-family:times;">'
          . pdfMixedHtml($text)
          . '</span>';

    $pdf->writeHTMLCell(
        $w - 2,
        $h - 1,
        '',
        '',
        $html,
        0,
        0,
        false,
        false,
        $align,
        false
    );

    $pdf->SetXY($x + $w, $y);

    if ($ln) {
        $pdf->Ln();
    }
}