<?php

namespace App\Services\Pdf;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Language\LanguageToFont;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * mPDF-based renderer: pure PHP (no headless browser to operate) with proper
 * Arabic shaping/RTL and Malayalam conjuncts, which dompdf lacks. Templates
 * live in resources/views/pdf.
 *
 * Malayalam runs use the bundled Manjari (SMC, resources/fonts, OFL)
 * with OpenType layout on. mPDF's FreeSerif fallback has no bold Malayalam,
 * and Noto Sans Malayalam uses GPOS lookups mPDF cannot read.
 */
class PdfRenderer
{
    public const LOCALES = ['en', 'ar', 'ml'];

    public function render(string $view, array $data, string $locale = 'en'): string
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $rtl = $locale === 'ar';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'manjari' => [
                    'R' => 'Manjari-Regular.ttf',
                    'B' => 'Manjari-Bold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'languageToFont' => new class extends LanguageToFont
            {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    $lang = strtolower(explode('-', (string) $llcc)[0]);

                    return in_array($lang, ['ml', 'mal'], true)
                        ? [false, 'manjari']
                        : parent::getLanguageOptions($llcc, $adobeCJK);
                }
            },
            'default_font' => 'dejavusans',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => false,
        ]);

        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle($data['title'] ?? 'Physio360');
        $mpdf->SetCreator('Physio360 by SYSNAC');
        $mpdf->defaultfooterfontstyle = '';
        $mpdf->defaultfooterfontsize = 7.5;
        $mpdf->defaultfooterline = 0;
        $mpdf->SetFooter($data['footer'] ?? '{PAGENO} / {nbpg}');
        if (! empty($data['watermark'])) {
            $mpdf->SetWatermarkText($data['watermark'], 0.07);
            $mpdf->showWatermarkText = true;
            $mpdf->watermark_font = 'dejavusanscondensed';
        }
        $logo = isset($data['tenant']) && $data['tenant'] instanceof Tenant ? self::logoDataUri($data['tenant']) : null;
        $mpdf->WriteHTML(view($view, $data + ['rtl' => $rtl, 'locale' => $locale, 'logo' => $logo])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** The clinic logo as a data URI (works with any storage disk), or null. */
    public static function logoDataUri(Tenant $tenant): ?string
    {
        if (! $tenant->logo_path) {
            return null;
        }
        $disk = Storage::disk(config('filesystems.private_disk'));
        if (! $disk->exists($tenant->logo_path)) {
            return null;
        }

        return 'data:'.($disk->mimeType($tenant->logo_path) ?: 'image/png').';base64,'.base64_encode($disk->get($tenant->logo_path));
    }

    /** Normalise a requested PDF language to one we have templates for. */
    public static function locale(?string $requested): string
    {
        return in_array($requested, self::LOCALES, true) ? $requested : 'en';
    }
}
