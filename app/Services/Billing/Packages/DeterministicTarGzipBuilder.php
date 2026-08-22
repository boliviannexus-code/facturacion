<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use RuntimeException;

final class DeterministicTarGzipBuilder
{
    /**
     * @param  array<string, string>  $files  Filename => original contents
     */
    public function build(array $files): string
    {
        if ($files === []) {
            throw new RuntimeException('No se puede construir un paquete sin facturas.');
        }

        $tar = '';

        foreach ($files as $name => $contents) {
            $this->assertFilename($name);
            $tar .= $this->header($name, strlen($contents));
            $tar .= $contents;
            $tar .= str_repeat("\0", (512 - (strlen($contents) % 512)) % 512);
        }

        $tar .= str_repeat("\0", 1024);
        $gzip = gzencode($tar, 9, ZLIB_ENCODING_GZIP);

        if ($gzip === false) {
            throw new RuntimeException('No se pudo comprimir el paquete de facturas.');
        }

        return $gzip;
    }

    private function assertFilename(string $name): void
    {
        if ($name === '' || strlen($name) > 100 || str_contains($name, '/') || str_contains($name, "\0")) {
            throw new RuntimeException('El nombre de un XML no es valido para el paquete fiscal.');
        }
    }

    private function header(string $name, int $size): string
    {
        $header = str_pad($name, 100, "\0")
            .$this->octal(0644, 8)
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .$this->octal($size, 12)
            .$this->octal(0, 12)
            .str_repeat(' ', 8)
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0"
            .'00'
            .str_pad('siat', 32, "\0")
            .str_pad('siat', 32, "\0")
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .str_repeat("\0", 155)
            .str_repeat("\0", 12);

        $checksum = 0;
        for ($position = 0; $position < 512; $position++) {
            $checksum += ord($header[$position]);
        }

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    private function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
    }
}
