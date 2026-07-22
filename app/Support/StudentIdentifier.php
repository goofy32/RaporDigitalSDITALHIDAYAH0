<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class StudentIdentifier
{
    public const MAX_DIGITS = 10;

    public const DIGIT_REGEX = '/^[0-9]+$/';

    public static function rules(string $column, int|string|null $ignoreStudentId = null): array
    {
        $unique = Rule::unique('siswas', $column);

        if ($ignoreStudentId !== null) {
            $unique->ignore($ignoreStudentId);
        }

        return [
            'bail',
            'required',
            'string',
            'max:'.self::MAX_DIGITS,
            'regex:'.self::DIGIT_REGEX,
            $unique,
        ];
    }

    public static function messages(): array
    {
        return [
            'nis.required' => 'NIS wajib diisi.',
            'nis.max' => 'NIS maksimal 10 digit.',
            'nis.regex' => 'NIS hanya boleh berisi angka.',
            'nis.unique' => 'NIS sudah digunakan.',
            'nisn.required' => 'NISN wajib diisi.',
            'nisn.max' => 'NISN maksimal 10 digit.',
            'nisn.regex' => 'NISN hanya boleh berisi angka.',
            'nisn.unique' => 'NISN sudah digunakan.',
        ];
    }

    public static function normalizeInput(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }

        return $value;
    }

    public static function hasOnlyDigits(string $value): bool
    {
        return preg_match(self::DIGIT_REGEX, $value) === 1;
    }
}
