<?php

namespace App\Services\Import;

use App\Enums\UserRole;
use App\Imports\RawRowsImport;
use App\Models\ImportBatch;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Імпорт списку учнів (ТЗ, п. 3.1).
 *
 * Звірка йде за логіном: наявний запис оновлюється, а не дублюється.
 * Саме тому імпорт двоетапний — спершу прев'ю, і лише потім запис,
 * інакше одна помилка в колонці логіна тихо роздвоїла б половину школи.
 */
class StudentImportService
{
    /** Заголовки, які розпізнаємо в шапці файлу. */
    private const HEADERS = [
        'full_name' => ['піб', 'пiб', 'прізвище', 'учень', 'name'],
        'class' => ['клас', 'class'],
        'login' => ['логін', 'логин', 'login'],
        'email' => ['e-mail', 'email', 'пошта'],
    ];

    /** @return Collection<int, StudentImportRow> */
    public function parse(string $path): Collection
    {
        $sheets = Excel::toArray(new RawRowsImport(), $path);
        $rows = collect($sheets[0] ?? []);

        if ($rows->isEmpty()) {
            return collect();
        }

        $map = $this->mapColumns($rows->first());

        if ($map === null) {
            return collect();
        }

        $seenLogins = [];

        return $rows
            ->skip(1)
            ->values()
            // Звичайне замикання, а не стрілкове: $seenLogins має накопичуватися між рядками.
            ->map(function (array $row, int $index) use ($map, &$seenLogins): StudentImportRow {
                return $this->parseRow($row, $map, $index + 2, $seenLogins);
            })
            ->filter(fn (StudentImportRow $row): bool => $row->fullName !== null || $row->login !== null || $row->error !== null)
            ->values();
    }

    /**
     * @param  Collection<int, StudentImportRow>  $rows
     * @return array{create: int, update: int, error: int, total: int}
     */
    public function summarize(Collection $rows): array
    {
        return [
            'create' => $rows->where('action', StudentImportRow::ACTION_CREATE)->count(),
            'update' => $rows->where('action', StudentImportRow::ACTION_UPDATE)->count(),
            'error' => $rows->where('action', StudentImportRow::ACTION_ERROR)->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Записує валідні рядки. Рядки з помилками пропускаються.
     *
     * @param  Collection<int, StudentImportRow>  $rows
     * @return array{batch: ImportBatch, credentials: Collection<int, array{full_name: string, class: string, login: string, password: string}>}
     */
    public function apply(Collection $rows, ?User $actor, string $filename, int $academicYear): array
    {
        $credentials = collect();
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $academicYear, $credentials, &$created, &$updated): void {
            foreach ($rows->filter(fn (StudentImportRow $row): bool => $row->isValid()) as $row) {
                $class = SchoolClass::query()->firstOrCreate([
                    'academic_year' => $academicYear,
                    'grade' => $row->grade,
                    'letter' => $row->letter,
                ], ['is_active' => true]);

                $user = User::query()->where('login', $row->login)->first();

                if ($user === null) {
                    $password = Str::password(8, symbols: false);

                    $user = User::create([
                        'name' => $row->fullName,
                        'login' => $row->login,
                        'email' => $row->email,
                        'password' => $password,
                        'role' => UserRole::Student,
                        'is_active' => true,
                    ]);

                    $credentials->push([
                        'full_name' => $row->fullName,
                        'class' => $row->className(),
                        'login' => $row->login,
                        'password' => $password,
                    ]);

                    $created++;
                } else {
                    // Пароль при повторному імпорті не чіпаємо — учень уже ним користується.
                    $user->update([
                        'name' => $row->fullName,
                        'email' => $row->email ?? $user->email,
                    ]);

                    $updated++;
                }

                Student::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'full_name' => $row->fullName,
                        'school_class_id' => $class->id,
                        'is_active' => true,
                    ],
                );
            }
        });

        $summary = $this->summarize($rows);

        $batch = ImportBatch::create([
            'user_id' => $actor?->id,
            'filename' => $filename,
            'total_rows' => $summary['total'],
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $summary['error'],
            'errors' => $rows
                ->filter(fn (StudentImportRow $row): bool => ! $row->isValid())
                ->map(fn (StudentImportRow $row): array => ['row' => $row->number, 'error' => $row->error])
                ->values()
                ->all(),
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        return ['batch' => $batch, 'credentials' => $credentials];
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<string, int>|null
     */
    private function mapColumns(array $header): ?array
    {
        $map = [];

        foreach ($header as $index => $title) {
            $normalized = Str::lower(trim((string) $title));

            foreach (self::HEADERS as $field => $variants) {
                foreach ($variants as $variant) {
                    if ($normalized !== '' && str_contains($normalized, $variant)) {
                        $map[$field] ??= $index;
                    }
                }
            }
        }

        // Без ПІБ і логіна файл безглуздий.
        return isset($map['full_name'], $map['login']) ? $map : null;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     * @param  array<string, true>  $seenLogins
     */
    private function parseRow(array $row, array $map, int $number, array &$seenLogins): StudentImportRow
    {
        // Колонок «Клас» і «E-mail» у файлі може не бути — беремо значення безпечно.
        $fullName = $this->cell($row, $map, 'full_name');
        $login = $this->cell($row, $map, 'login');
        $rawClass = $this->cell($row, $map, 'class');
        $email = $this->cell($row, $map, 'email') ?: null;

        [$grade, $letter] = $this->parseClass($rawClass);

        $parsed = new StudentImportRow(
            number: $number,
            fullName: $fullName ?: null,
            grade: $grade,
            letter: $letter,
            login: $login ?: null,
            email: $email,
        );

        if ($fullName === '') {
            return $parsed->fail('Порожнє ПІБ');
        }

        if ($login === '') {
            return $parsed->fail('Порожній логін');
        }

        if (isset($seenLogins[$login])) {
            return $parsed->fail("Логін «{$login}» повторюється у файлі");
        }

        $seenLogins[$login] = true;

        if ($grade === null) {
            return $parsed->fail("Не розпізнано клас: «{$rawClass}»");
        }

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $parsed->fail("Некоректний e-mail: «{$email}»");
        }

        $existing = User::query()->where('login', $login)->first();

        if ($existing !== null && $existing->role !== UserRole::Student) {
            return $parsed->fail("Логін «{$login}» уже зайнятий службовим акаунтом");
        }

        $parsed->action = $existing === null
            ? StudentImportRow::ACTION_CREATE
            : StudentImportRow::ACTION_UPDATE;

        return $parsed;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     */
    private function cell(array $row, array $map, string $field): string
    {
        $index = $map[$field] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }

    /** «5-А», «5 А», «5А» → [5, 'А'] */
    private function parseClass(string $raw): array
    {
        if (! preg_match('/^\s*(\d{1,2})\s*[-–—\s]?\s*(\S+)?\s*$/u', $raw, $matches)) {
            return [null, null];
        }

        $grade = (int) $matches[1];

        if ($grade < 1 || $grade > 11) {
            return [null, null];
        }

        return [$grade, Str::upper(trim($matches[2] ?? '')) ?: 'А'];
    }
}
