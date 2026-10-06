<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SecurityRegressionTest extends TestCase
{
    #[Test]
    public function application_code_does_not_use_legacy_password_storage_or_comparison(): void
    {
        $source = $this->phpSourceUnder(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app');

        $this->assertDoesNotMatchRegularExpression('/->plain_password\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/[\'"]plain_password[\'"]\s*=>/', $source);
        $this->assertDoesNotMatchRegularExpression('/\[[\'"]plain_password[\'"]\]\s*=/', $source);
        $this->assertDoesNotMatchRegularExpression('/md5\s*\(\s*\$password\s*\)/i', $source);
        $this->assertDoesNotMatchRegularExpression('/->password\s*!={0,1}\s*\$password\b/', $source);
    }

    #[Test]
    public function application_code_does_not_contain_embedded_jwt_tokens(): void
    {
        $source = $this->phpSourceUnder(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app');

        $this->assertDoesNotMatchRegularExpression(
            '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
            $source
        );
    }

    private function phpSourceUnder(string $path): string
    {
        $source = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        return $source;
    }
}
