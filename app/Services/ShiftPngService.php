<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Carbon;
use RuntimeException;

class ShiftPngService
{
    public function __construct(private ShiftCalendarService $calendar) {}

    public function render(Store $store, Carbon $month): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('PNG生成に必要なGD/Freetypeが利用できません。Dockerイメージを再ビルドしてください。');
        }
        $font = $this->fontPath();
        $calendar = $this->calendar->monthly($store, $month);
        $days = $calendar['days'];
        $staffs = $calendar['staffs'];
        $nameWidth = 230;
        $cellWidth = 72;
        $titleHeight = 72;
        $headerHeight = 62;
        $rowHeight = 50;
        $footerHeight = 30;
        $width = $nameWidth + (count($days) * $cellWidth) + 1;
        $height = $titleHeight + $headerHeight + (max(1, count($staffs)) * $rowHeight) + $footerHeight + 1;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('シフトPNGの描画領域を作成できません。');
        }
        $colors = [
            'white' => $this->allocate($image, 255, 255, 255),
            'ink' => $this->allocate($image, 28, 42, 48),
            'line' => $this->allocate($image, 151, 167, 174),
            'header' => $this->allocate($image, 229, 239, 243),
            'saturday' => $this->allocate($image, 225, 243, 252),
            'sunday' => $this->allocate($image, 255, 232, 236),
            'holiday' => $this->allocate($image, 255, 196, 123),
            'employee' => $this->allocate($image, 92, 110, 118),
        ];
        imagefill($image, 0, 0, $colors['white']);
        $this->drawText($image, '株式会社Groovy', 16, 18, 29, $font, $colors['ink']);
        $this->drawText(
            $image,
            sprintf('%d年%02d月 %s 月間シフト', $month->year, $month->month, $store->name),
            22,
            18,
            58,
            $font,
            $colors['ink'],
        );
        imagefilledrectangle($image, 0, $titleHeight, $nameWidth, $titleHeight + $headerHeight, $colors['header']);
        $this->drawCentered($image, 'スタッフ', 14, 0, $titleHeight, $nameWidth, $headerHeight, $font, $colors['ink']);
        foreach ($days as $index => $day) {
            $x = $nameWidth + ($index * $cellWidth);
            $background = $day['is_holiday']
                ? $colors['holiday']
                : ($day['is_sunday'] ? $colors['sunday'] : ($day['is_saturday'] ? $colors['saturday'] : $colors['header']));
            imagefilledrectangle($image, $x, $titleHeight, $x + $cellWidth, $titleHeight + $headerHeight, $background);
            $label = sprintf("%d\n（%s）%s", $day['day'], $day['weekday'], $day['is_holiday'] ? "\n店休" : '');
            $this->drawMultilineCentered($image, $label, 12, $x, $titleHeight, $cellWidth, $headerHeight, $font, $colors['ink']);
        }
        if ($staffs === []) {
            $this->drawCentered($image, '対象スタッフはいません', 14, 0, $titleHeight + $headerHeight, $width, $rowHeight, $font, $colors['employee']);
        }
        foreach ($staffs as $rowIndex => $staff) {
            $y = $titleHeight + $headerHeight + ($rowIndex * $rowHeight);
            imagefilledrectangle($image, 0, $y, $nameWidth, $y + $rowHeight, $colors['white']);
            $this->drawText($image, mb_strimwidth($staff['name'], 0, 24, '…'), 13, 10, $y + 21, $font, $colors['ink']);
            $employment = $staff['employment_type'] === 'employee' ? '社員' : 'アルバイト';
            $this->drawText($image, $employment, 9, 10, $y + 39, $font, $colors['employee']);
            foreach ($staff['cells'] as $cellIndex => $cell) {
                $day = $days[$cellIndex];
                $x = $nameWidth + ($cellIndex * $cellWidth);
                $background = $day['is_holiday']
                    ? $colors['holiday']
                    : ($day['is_sunday'] ? $colors['sunday'] : ($day['is_saturday'] ? $colors['saturday'] : $colors['white']));
                imagefilledrectangle($image, $x, $y, $x + $cellWidth, $y + $rowHeight, $background);
                $display = $cell['display'];
                if ($day['is_holiday'] && $display === '') {
                    $display = '店休';
                }
                $this->drawCentered($image, mb_strimwidth((string) $display, 0, 10, '…'), 11, $x, $y, $cellWidth, $rowHeight, $font, $colors['ink']);
            }
        }
        $tableBottom = $titleHeight + $headerHeight + (max(1, count($staffs)) * $rowHeight);
        for ($column = 0; $column <= count($days); $column++) {
            $x = $column === 0 ? 0 : $nameWidth + (($column - 1) * $cellWidth);
            imageline($image, $x, $titleHeight, $x, $tableBottom, $colors['line']);
        }
        imageline($image, $width - 1, $titleHeight, $width - 1, $tableBottom, $colors['line']);
        imageline($image, 0, $titleHeight, $width - 1, $titleHeight, $colors['line']);
        imageline($image, 0, $titleHeight + $headerHeight, $width - 1, $titleHeight + $headerHeight, $colors['line']);
        for ($row = 1; $row <= max(1, count($staffs)); $row++) {
            $y = $titleHeight + $headerHeight + ($row * $rowHeight);
            imageline($image, 0, $y, $width - 1, $y, $colors['line']);
        }
        $this->drawText($image, '※勤務開始時刻のみを表示しています。', 9, 8, $height - 9, $font, $colors['employee']);
        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);
        if (! is_string($png)) {
            throw new RuntimeException('シフトPNGを生成できません。');
        }

        return $png;
    }

    private function fontPath(): string
    {
        foreach (['/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf', '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('PNG用の日本語フォントが見つかりません。Dockerイメージを再ビルドしてください。');
    }

    /**
     * @param  int<0, 255>  $red
     * @param  int<0, 255>  $green
     * @param  int<0, 255>  $blue
     */
    private function allocate(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false) {
            throw new RuntimeException('シフトPNGの色を作成できません。');
        }

        return $color;
    }

    private function drawText(\GdImage $image, string $text, int $size, int $x, int $baseline, string $font, int $color): void
    {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
    }

    private function drawCentered(\GdImage $image, string $text, int $size, int $x, int $y, int $width, int $height, string $font, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return;
        }
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        imagettftext($image, $size, 0, (int) ($x + (($width - $textWidth) / 2)), (int) ($y + (($height + $textHeight) / 2)), $color, $font, $text);
    }

    private function drawMultilineCentered(\GdImage $image, string $text, int $size, int $x, int $y, int $width, int $height, string $font, int $color): void
    {
        $lines = explode("\n", $text);
        $lineHeight = $size + 6;
        $startY = (int) ($y + (($height - (count($lines) * $lineHeight)) / 2));
        foreach ($lines as $index => $line) {
            $this->drawCentered($image, $line, $size, $x, $startY + ($index * $lineHeight), $width, $lineHeight, $font, $color);
        }
    }
}
