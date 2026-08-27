<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title>Звіт для кухні — {{ $date->format('d.m.Y') }}</title>
    <style>
        @page { margin: 18mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            color: #111;
        }

        h1 { font-size: 14pt; margin: 0 0 2mm; }
        h2 { font-size: 12pt; margin: 8mm 0 2mm; }
        h3 { font-size: 11pt; margin: 4mm 0 1mm; }

        .meta { color: #555; font-size: 10pt; margin-bottom: 6mm; }

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1.5mm 0; text-align: left; vertical-align: top; }
        th { border-bottom: 0.5pt solid #999; font-size: 10pt; }
        .num { text-align: right; width: 20mm; }
        .totals td { border-top: 0.5pt solid #999; font-weight: bold; }
        .student { padding-left: 4mm; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <h1>{{ $supplier->name }}</h1>
    <div class="meta">
        {{ mb_strtoupper($date->translatedFormat('l')) }}, {{ $date->format('d.m.Y') }}
    </div>

    <h2>Зведення на день</h2>

    @if ($summary['dishes']->isEmpty())
        <p>Замовлень немає.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Страва</th>
                    <th class="num">К-сть</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['dishes'] as $dish)
                    <tr>
                        <td>{{ $dish['name'] }}</td>
                        <td class="num">{{ $dish['quantity'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="totals">
                <tr>
                    <td>Разом позицій</td>
                    <td class="num">{{ $summary['positions'] }}</td>
                </tr>
                <tr>
                    <td>Учнів</td>
                    <td class="num">{{ $summary['students'] }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    @if ($classes->isNotEmpty())
        <div class="page-break"></div>

        <h1>Список для видачі</h1>
        <div class="meta">
            {{ $supplier->name }} · {{ $date->format('d.m.Y') }}
        </div>

        @foreach ($classes as $class)
            <h3>{{ $class['class'] }}</h3>

            <table>
                @foreach ($class['students'] as $student)
                    <tr>
                        <td class="student" style="width: 55mm;">{{ $student['name'] }}</td>
                        <td>{{ $student['dishes'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif
</body>
</html>
