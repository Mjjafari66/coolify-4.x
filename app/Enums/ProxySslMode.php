<?php

namespace App\Enums;

enum ProxySslMode: string
{
    case Letsencrypt = 'letsencrypt';
    case Manual = 'manual';
    case Off = 'off';

    public function usesLetsEncrypt(): bool
    {
        return $this === self::Letsencrypt;
    }

    public function usesManualCertificates(): bool
    {
        return $this === self::Manual;
    }

    public function label(): string
    {
        return match ($this) {
            self::Letsencrypt => "Let's Encrypt (automatic)",
            self::Manual => 'Manual certificates',
            self::Off => 'Disabled (HTTP only)',
        };
    }
}
