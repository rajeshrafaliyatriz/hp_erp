<?php

namespace App\Domain\Signals\Ingestion;

/**
 * Reads entries out of a ZIP container (DOCX and XLSX are ZIPs) in pure PHP.
 *
 * Written rather than using ZipArchive because ext-zip is not loaded on every PHP this
 * project runs under (the XAMPP CLI has none). Read-only, and defensive: entry sizes
 * are capped so a crafted "zip bomb" cannot exhaust memory.
 */
class ZipReader
{
    private const MAX_ENTRY_BYTES = 30 * 1024 * 1024;

    /** @var array<string, array{method: int, csize: int, offset: int}> */
    private array $entries = [];

    public function __construct(private readonly string $data)
    {
        $this->index();
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function read(string $name): ?string
    {
        $entry = $this->entries[$name] ?? null;
        if ($entry === null) {
            return null;
        }

        $o = $entry['offset'];
        if (substr($this->data, $o, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen = unpack('v', substr($this->data, $o + 26, 2))[1];
        $extraLen = unpack('v', substr($this->data, $o + 28, 2))[1];
        $raw = substr($this->data, $o + 30 + $nameLen + $extraLen, $entry['csize']);

        if ($entry['method'] === 0) {
            return strlen($raw) <= self::MAX_ENTRY_BYTES ? $raw : null;
        }
        if ($entry['method'] === 8) {
            $out = @gzinflate($raw, self::MAX_ENTRY_BYTES);

            return $out === false ? null : $out;
        }

        return null;
    }

    private function index(): void
    {
        $eocd = strrpos($this->data, "PK\x05\x06");
        if ($eocd === false) {
            throw new \RuntimeException('Not a valid Office file.');
        }

        $count = unpack('v', substr($this->data, $eocd + 10, 2))[1];
        $cd = unpack('V', substr($this->data, $eocd + 16, 4))[1];

        for ($i = 0; $i < $count; $i++) {
            if (substr($this->data, $cd, 4) !== "PK\x01\x02") {
                break;
            }
            $method = unpack('v', substr($this->data, $cd + 10, 2))[1];
            $csize = unpack('V', substr($this->data, $cd + 20, 4))[1];
            $nameLen = unpack('v', substr($this->data, $cd + 28, 2))[1];
            $extraLen = unpack('v', substr($this->data, $cd + 30, 2))[1];
            $commentLen = unpack('v', substr($this->data, $cd + 32, 2))[1];
            $offset = unpack('V', substr($this->data, $cd + 42, 4))[1];
            $name = substr($this->data, $cd + 46, $nameLen);

            $this->entries[$name] = ['method' => $method, 'csize' => $csize, 'offset' => $offset];
            $cd += 46 + $nameLen + $extraLen + $commentLen;
        }

        if ($this->entries === []) {
            throw new \RuntimeException('Not a valid Office file.');
        }
    }
}
