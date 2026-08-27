<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title>Зведення по школі</title>
    <style>
        @page { margin: 16mm 12mm; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; }

        h1 { font-size: 14pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 8mm 0 2mm; }
        h3 { font-size: 10pt; margin: 3mm 0 1mm; }

        .meta { color: #555; font-size: 9pt; margin-bottom: 5mm; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1.2mm 2mm; text-align: left; }
        th { border-bottom: 0.5pt solid #999; font-size: 9pt; }
        td { border-bottom: 0.25pt solid #ddd; }
        .num { text-align: right; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>{{ $schoolName ?: 'Зведення по школі' }}</h1>
    <div class="meta">
        Період: {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}
    </div>

    <h2>Замовлення по днях</h2>

    @if ($supplierNames->isEmpty())
        <p class="muted">За цей період замовлень немає.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>День</th>
                    @foreach ($supplierNames as $name)
                        <th class="num">{{ $name }}</th>
                    @endforeach
                    <th class="num">Позицій</th>
                    <th class="num">Учнів</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    <tr>
                        <td>{{ $day['date']->translatedFormat('D, d.m') }}</td>
                        @foreach ($supplierNames as $name)
                            <td class="num">{{ $day['suppliers'][$name] ?? '—' }}</td>
                        @endforeach
                        <td class="num">{{ $day['positions'] }}</td>
                        <td class="num">{{ $day['students'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Не замовляли на {{ $missingDate->format('d.m.Y') }}</h2>

    @if ($missing->isEmpty())
        <p class="muted">Замовили всі.</p>
    @else
        <p class="muted">Усього: {{ $missing->count() }}</p>

        @foreach ($missing->groupBy(fn ($student) => $student->schoolClass?->title ?? 'Без класу') as $class => $students)
            <h3>{{ $class }}</h3>
            <p>{{ $students->pluck('full_name')->implode(', ') }}</p>
        @endforeach
    @endif
</body>
</html>
