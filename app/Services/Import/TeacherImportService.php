<?php

namespace App\Services\Import;

use App\Enums\UserRole;
use App\Imports\RawRowsImport;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Імпорт списку вчителів — той самий двоетапний підхід, що й для учнів
 * (StudentImportService), але простіший: без класу й без згоди батьків
 * (вчитель — доросла людина, згода фіксується автоматично при створенні).
 *
 * Усім вчителям із файлу ставиться той самий фіксований пароль і прапорець
 * "обов'язково змінити пароль" — при вході система відправить на сторінку
 * зміни, поки вчитель не задасть власний.
 */
class TeacherImportService
{
    /** Заголовки, які розпізнаємо в шапці файлу. */
    private const HEADERS = [
        'full_name' => ['піб', 'пiб', 'прізвище', 'вчитель', 'вчителька', 'ім\'я', 'name'],
        'login' => ['логін', 'логин', 'login'],
        'email' => ['e-mail', 'email', 'пошта'],
    ];

    /** Видається всім новим вчителям одразу; при першому вході обов'язково змінюється. */
    public const DEFAULT_PASSWORD = 'food-teacher-2026';

    /** @return Collection<int, TeacherImportRow> */
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
            ->map(function (array $row, int $index) use ($map, &$seenLogins): TeacherImportRow {
                return $this->parseRow($row, $map, $index + 2, $seenLogins);
            })
            ->filter(fn (TeacherImportRow $row): bool => $row->fullName !== null || $row->login !== null || $row->error !== null)
            ->values();
    }

    /**
     * @param  Collection<int, TeacherImportRow>  $rows
     * @return array{create: int, update: int, error: int, total: int}
     */
    public function summarize(Collection $rows): array
    {
        return [
            'create' => $rows->where('action', TeacherImportRow::ACTION_CREATE)->count(),
            'update' => $rows->where('action', TeacherImportRow::ACTION_UPDATE)->count(),
            'error' => $rows->where('action', TeacherImportRow::ACTION_ERROR)->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Записує валідні рядки. Рядки з помилками пропускаються.
     *
     * Новий вчитель отримує фіксований пароль (DEFAULT_PASSWORD) і
     * must_change_password = true — при першому вході його примусово
     * попросять задати власний. Уже наявний акаунт паролем не чіпаємо:
     * повторний імпорт не повинен скидати те, чим вчитель уже користується.
     *
     * @param  Collection<int, TeacherImportRow>  $rows
     * @return array{batch: ImportBatch, credentials: Collection<int, array{full_name: string, login: string, password: string}>}
     */
    public function apply(Collection $rows, ?User $actor, string $filename): array
    {
        $credentials = collect();
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $credentials, &$created, &$updated): void {
            foreach ($rows->filter(fn (TeacherImportRow $row): bool => $row->isValid()) as $row) {
                $user = User::query()->where('login', $row->login)->first();

                if ($user === null) {
                    $user = User::create([
                        'name' => $row->fullName,
                        'login' => $row->login,
                        'email' => $row->email,
                        'password' => self::DEFAULT_PASSWORD,
                        'role' => UserRole::Student,
                        'must_change_password' => true,
                        'is_active' => true,
                    ]);

                    $credentials->push([
                        'full_name' => $row->fullName,
                        'login' => $row->login,
                        'password' => self::DEFAULT_PASSWORD,
                    ]);

                    $created++;
                } else {
                    $user->update([
                        'name' => $row->fullName,
                        'email' => $row->email ?? $user->email,
                    ]);

                    $updated++;
                }

                // Вчитель — доросла людина: згода батьків тут не потрібна,
                // фіксуємо її одразу, щоб EnsureConsentGiven нічого не питав.
                Student::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'full_name' => $row->fullName,
                        'school_class_id' => null,
                        'is_active' => true,
                        'consent_at' => Student::query()->where('user_id', $user->id)->value('consent_at') ?? now(),
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
                ->filter(fn (TeacherImportRow $row): bool => ! $row->isValid())
                ->map(fn (TeacherImportRow $row): array => ['row' => $row->number, 'error' => $row->error])
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
    private function parseRow(array $row, array $map, int $number, array &$seenLogins): TeacherImportRow
    {
        $fullName = $this->cell($row, $map, 'full_name');
        $login = $this->cell($row, $map, 'login');
        $email = $this->cell($row, $map, 'email') ?: null;

        $parsed = new TeacherImportRow(
            number: $number,
            fullName: $fullName ?: null,
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

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $parsed->fail("Некоректний e-mail: «{$email}»");
        }

        $existing = User::query()->where('login', $login)->first();

        if ($existing !== null && $existing->role !== UserRole::Student) {
            return $parsed->fail("Логін «{$login}» уже зайнятий службовим акаунтом");
        }

        $parsed->action = $existing === null
            ? TeacherImportRow::ACTION_CREATE
            : TeacherImportRow::ACTION_UPDATE;

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
}
