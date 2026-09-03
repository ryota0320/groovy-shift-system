<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 10mm 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: '{{ $fontFamily }}', sans-serif; font-size: 9pt; }
        .identity { margin: 0 0 1.5mm 1mm; font-size: 10pt; line-height: 1.3; }
        .statement { width: 100%; border-collapse: collapse; table-layout: fixed; border: 1.5pt solid #111; }
        .statement th, .statement td { border: 1pt solid #111; height: 7.6mm; padding: 0.8mm 1.5mm; }
        .title { height: 18mm !important; background: #f0f1f2; font-size: 22pt; font-weight: bold; text-align: center; letter-spacing: 1.5pt; }
        .section { width: 5.5%; padding: 0 !important; background: #9dbbea; color: #fff; font-size: 15pt; font-weight: bold; line-height: 1.15; text-align: center; vertical-align: middle; }
        .section.total { background: #b8b8b8; }
        .label { background: #c5d6f1; text-align: center; font-weight: normal; }
        .total-label { background: #d3d3d3; text-align: center; font-weight: normal; }
        .value { background: #fff; text-align: right; vertical-align: middle; }
        .blank { color: transparent; font-size: 0; }
        .amount { white-space: nowrap; }
        .column-sizer td { height: 0 !important; padding: 0 !important; border: 0 !important; line-height: 0; font-size: 0; }
    </style>
</head>
<body>
    <div class="identity">
        株式会社Groovy<br>
        {{ $payroll->year }}年{{ sprintf('%02d', $payroll->month) }}月<br>
        氏名：{{ $payroll->staff->name }} 様
    </div>
    <table class="statement">
        <colgroup>
            <col width="5.5%">
            @for ($column = 0; $column < 7; $column++)<col width="13.5%">@endfor
        </colgroup>
        <tr class="column-sizer">
            <td style="width:5.5%"></td>
            @for ($column = 0; $column < 7; $column++)<td style="width:13.5%"></td>@endfor
        </tr>
        <tr><th colspan="8" class="title">給与支給明細</th></tr>
        <tr>
            <th rowspan="4" class="section">勤<br>怠</th>
            <th class="label">就業日数</th><th class="label">出勤日数</th><th class="label">労働時間</th>
            <th class="label">欠勤日数</th><th class="label">休日出勤日数</th><th class="label">有給休暇日数</th><th class="label blank">未使用</th>
        </tr>
        <tr>
            <td class="value"></td><td class="value">{{ number_format($attendanceDays) }} 日</td><td class="value">{{ intdiv($payroll->working_minutes, 60) }}時間{{ $payroll->working_minutes % 60 }}分</td>
            <td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td>
        </tr>
        <tr>
            <th class="label">平日普通残業</th><th class="label">深夜加算対象時間</th><th class="label blank">未使用</th><th class="label blank">未使用</th><th class="label">遅刻早退時間</th><th class="label">有給残日数</th><th class="label blank">未使用</th>
        </tr>
        <tr>
            <td class="value"></td><td class="value">{{ intdiv($payroll->late_night_minutes, 60) }}時間{{ $payroll->late_night_minutes % 60 }}分</td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td>
        </tr>
        <tr>
            <th rowspan="4" class="section">支<br>給</th>
            <th class="label">基本給</th><th class="label">歩合</th><th class="label">資格手当</th><th class="label">住宅手当</th><th class="label">家族手当</th><th class="label blank">未使用</th><th class="label blank">未使用</th>
        </tr>
        <tr>
            <td class="value amount">{{ number_format($payroll->base_pay) }}円</td><td class="value amount">{{ number_format($payroll->commission) }}円</td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td>
        </tr>
        <tr>
            <th class="label">通勤手当</th><th class="label">残業手当</th><th class="label">深夜勤務手当</th><th class="label">法定休日手当</th><th class="label blank">未使用</th><th class="label blank">未使用</th><th class="label">総支給額</th>
        </tr>
        <tr>
            <td class="value amount">{{ number_format($payroll->transportation_fee_total) }}円</td><td class="value"></td><td class="value amount">{{ number_format($payroll->late_night_pay) }}円</td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value amount">{{ number_format($payroll->gross_pay) }}円</td>
        </tr>
        <tr>
            <th rowspan="4" class="section">控<br>除</th>
            <th class="label">健康保険</th><th class="label">厚生年金保険</th><th class="label">厚生年金基金</th><th class="label">介護保険</th><th class="label">雇用保険</th><th class="label">社会保険合計</th><th class="label blank">未使用</th>
        </tr>
        <tr><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td></tr>
        <tr>
            <th class="label">所得税</th><th class="label">住民税</th><th class="label">税額合計</th><th class="label">共済費</th><th class="label blank">未使用</th><th class="label blank">未使用</th><th class="label">総控除額</th>
        </tr>
        <tr>
            <td class="value amount">{{ number_format($payroll->income_tax) }}円</td><td class="value"></td><td class="value amount">{{ number_format($payroll->income_tax) }}円</td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value amount">{{ number_format($payroll->total_deductions) }}円</td>
        </tr>
        <tr>
            <th rowspan="2" class="section total">合<br>計</th>
            <th class="total-label blank">未使用</th><th class="total-label blank">未使用</th><th class="total-label blank">未使用</th><th class="total-label blank">未使用</th><th class="total-label blank">未使用</th><th class="total-label blank">未使用</th><th class="total-label">差引総支給額</th>
        </tr>
        <tr><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value"></td><td class="value amount">{{ number_format($payroll->net_pay) }}円</td></tr>
    </table>
</body>
</html>
