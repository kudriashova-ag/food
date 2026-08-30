<?php

namespace App\Services\Import;

/** Один рядок файлу вчителів після розбору: або готовий до запису, або з поясненням помилки. */
final class TeacherImportRow
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_ERROR = 'error';

    public function __construct(
        public readonly int $number,
        public readonly ?string $fullName = null,
        public readonly ?string $login = null,
        public readonly ?string $email = null,
        public string $action = self::ACTION_CREATE,
        public ?string $error = null,
    ) {}

    public function fail(string $reason): self
    {
        $this->action = self::ACTION_ERROR;
        $this->error = $reason;

        return $this;
    }

    public function isValid(): bool
    {
        return $this->action !== self::ACTION_ERROR;
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE => 'Створиться',
            self::ACTION_UPDATE => 'Оновиться',
            default => 'Помилка',
        };
    }
}
