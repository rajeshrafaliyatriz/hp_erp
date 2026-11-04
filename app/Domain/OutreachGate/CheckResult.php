<?php

namespace App\Domain\OutreachGate;

/** One check's answer: a key, a label a person reads, whether it passed, and why in words (at most 300 characters, the width of the stored column). */
final class CheckResult
{
    public const DETAIL_MAX = 300;

    public readonly string $detail;

    public function __construct(public readonly string $key, public readonly string $label, public readonly bool $passed, string $detail = '')
    {
        $detail = trim((string) preg_replace('/\s+/u', ' ', $detail));
        $this->detail = mb_strlen($detail) > self::DETAIL_MAX ? rtrim(mb_substr($detail, 0, self::DETAIL_MAX - 1)) . '…' : $detail;
    }

    public static function pass(string $key, string $label, string $detail = ''): self
    {
        return new self($key, $label, true, $detail);
    }

    public static function fail(string $key, string $label, string $detail): self
    {
        return new self($key, $label, false, $detail);
    }

    /** @return array{key: string, label: string, passed: bool, detail: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}
