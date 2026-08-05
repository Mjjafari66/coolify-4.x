<?php

namespace App\Helpers;

use App\Models\Server;
use App\Models\SslCertificate;
use Carbon\CarbonImmutable;
use Spatie\Url\Url;

class SslHelper
{
    private const DEFAULT_ORGANIZATION_NAME = 'Coolify';

    private const DEFAULT_COUNTRY_NAME = 'XX';

    private const DEFAULT_STATE_NAME = 'Default';

    public static function generateSslCertificate(
        string $commonName,
        array $subjectAlternativeNames = [],
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?int $serverId = null,
        int $validityDays = 365,
        ?string $caCert = null,
        ?string $caKey = null,
        bool $isCaCertificate = false,
        ?string $configurationDir = null,
        ?string $mountPath = null,
        bool $isPemKeyFileRequired = false,
    ): SslCertificate {
        $organizationName = self::DEFAULT_ORGANIZATION_NAME;
        $countryName = self::DEFAULT_COUNTRY_NAME;
        $stateName = self::DEFAULT_STATE_NAME;

        try {
            $privateKey = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'secp521r1',
            ]);

            if ($privateKey === false) {
                throw new \RuntimeException('Failed to generate private key: '.openssl_error_string());
            }

            if (! openssl_pkey_export($privateKey, $privateKeyStr)) {
                throw new \RuntimeException('Failed to export private key: '.openssl_error_string());
            }

            if (! is_null($serverId) && ! $isCaCertificate) {
                $server = Server::find($serverId);
                if ($server) {
                    $ip = $server->getIp;
                    if ($ip) {
                        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)
                            ? 'IP'
                            : 'DNS';
                        $subjectAlternativeNames = array_unique(
                            array_merge($subjectAlternativeNames, ["$type:$ip"])
                        );
                    }
                }
            }

            $basicConstraints = $isCaCertificate ? 'critical, CA:TRUE, pathlen:0' : 'critical, CA:FALSE';
            $keyUsage = $isCaCertificate ? 'critical, keyCertSign, cRLSign' : 'critical, digitalSignature, keyAgreement';

            $subjectAltNameSection = '';
            $extendedKeyUsageSection = '';

            if (! $isCaCertificate) {
                $extendedKeyUsageSection = "\nextendedKeyUsage = serverAuth, clientAuth";

                $subjectAlternativeNames = array_values(
                    array_unique(
                        array_merge(["DNS:$commonName"], $subjectAlternativeNames)
                    )
                );

                $formattedSubjectAltNames = array_map(
                    function ($index, $san) {
                        [$type, $value] = explode(':', $san, 2);

                        return "{$type}.".($index + 1)." = $value";
                    },
                    array_keys($subjectAlternativeNames),
                    $subjectAlternativeNames
                );

                $subjectAltNameSection = "subjectAltName = @subject_alt_names\n\n[ subject_alt_names ]\n"
                    .implode("\n", $formattedSubjectAltNames);
            }

            $config = <<<CONF
                [ req ]
                prompt = no
                distinguished_name = distinguished_name
                req_extensions = req_ext

                [ distinguished_name ]
                CN = $commonName
                
                [ req_ext ]
                basicConstraints = $basicConstraints
                keyUsage = $keyUsage
                {$extendedKeyUsageSection}

                [ v3_req ]
                basicConstraints = $basicConstraints
                keyUsage = $keyUsage
                {$extendedKeyUsageSection}
                subjectKeyIdentifier = hash
                {$subjectAltNameSection}
            CONF;

            $tempConfig = tmpfile();
            fwrite($tempConfig, $config);
            $tempConfigPath = stream_get_meta_data($tempConfig)['uri'];

            $csr = openssl_csr_new([
                'commonName' => $commonName,
                'organizationName' => $organizationName,
                'countryName' => $countryName,
                'stateOrProvinceName' => $stateName,
            ], $privateKey, [
                'digest_alg' => 'sha512',
                'config' => $tempConfigPath,
                'req_extensions' => 'req_ext',
            ]);

            if ($csr === false) {
                throw new \RuntimeException('Failed to generate CSR: '.openssl_error_string());
            }

            $certificate = openssl_csr_sign(
                $csr,
                $caCert ?? null,
                $caKey ?? $privateKey,
                $validityDays,
                [
                    'digest_alg' => 'sha512',
                    'config' => $tempConfigPath,
                    'x509_extensions' => 'v3_req',
                ],
                random_int(1, PHP_INT_MAX)
            );

            if ($certificate === false) {
                throw new \RuntimeException('Failed to sign certificate: '.openssl_error_string());
            }

            if (! openssl_x509_export($certificate, $certificateStr)) {
                throw new \RuntimeException('Failed to export certificate: '.openssl_error_string());
            }

            SslCertificate::query()
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('server_id', $serverId)
                ->delete();

            $sslCertificate = SslCertificate::create([
                'ssl_certificate' => $certificateStr,
                'ssl_private_key' => $privateKeyStr,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'server_id' => $serverId,
                'configuration_dir' => $configurationDir,
                'mount_path' => $mountPath,
                'valid_until' => CarbonImmutable::now()->addDays($validityDays),
                'is_ca_certificate' => $isCaCertificate,
                'common_name' => $commonName,
                'subject_alternative_names' => $subjectAlternativeNames,
            ]);

            if ($configurationDir && $mountPath && $resourceType && $resourceId) {
                $model = app($resourceType)->find($resourceId);

                $model->fileStorages()
                    ->where('resource_type', $model->getMorphClass())
                    ->where('resource_id', $model->id)
                    ->get()
                    ->filter(function ($storage) use ($mountPath) {
                        return in_array($storage->mount_path, [
                            $mountPath.'/server.crt',
                            $mountPath.'/server.key',
                            $mountPath.'/server.pem',
                        ]);
                    })
                    ->each(function ($storage) {
                        $storage->delete();
                    });

                if ($isPemKeyFileRequired) {
                    $model->fileStorages()->create([
                        'fs_path' => $configurationDir.'/ssl/server.pem',
                        'mount_path' => $mountPath.'/server.pem',
                        'content' => $certificateStr."\n".$privateKeyStr,
                        'is_directory' => false,
                        'chmod' => '600',
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                    ]);
                } else {
                    $model->fileStorages()->create([
                        'fs_path' => $configurationDir.'/ssl/server.crt',
                        'mount_path' => $mountPath.'/server.crt',
                        'content' => $certificateStr,
                        'is_directory' => false,
                        'chmod' => '644',
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                    ]);

                    $model->fileStorages()->create([
                        'fs_path' => $configurationDir.'/ssl/server.key',
                        'mount_path' => $mountPath.'/server.key',
                        'content' => $privateKeyStr,
                        'is_directory' => false,
                        'chmod' => '600',
                        'resource_type' => $resourceType,
                        'resource_id' => $resourceId,
                    ]);
                }
            }

            return $sslCertificate;
        } catch (\Throwable $e) {
            throw new \RuntimeException('SSL Certificate generation failed: '.$e->getMessage(), 0, $e);
        } finally {
            fclose($tempConfig);
        }
    }

    /**
     * @return array{
     *     certificate: string,
     *     private_key: string,
     *     common_name: string,
     *     subject_alternative_names: array<int, string>,
     *     valid_until: \Carbon\CarbonImmutable
     * }
     */
    public static function parseAndValidateProxyCertificate(string $certificate, string $privateKey): array
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $certificate,
            $pemMatches,
        );
        $pemBlocks = $pemMatches[0];
        if (empty($pemBlocks)) {
            throw new \RuntimeException('Invalid certificate format.');
        }

        $parsedCert = openssl_x509_read($pemBlocks[0]);
        if ($parsedCert === false) {
            throw new \RuntimeException('Invalid certificate format.');
        }

        // Preserve the full chain (leaf + intermediates). Dropping intermediates
        // makes Traefik serve an incomplete chain that browsers reject.
        $cleanedChain = [];
        foreach ($pemBlocks as $pemBlock) {
            $chainCert = openssl_x509_read($pemBlock);
            if ($chainCert === false) {
                throw new \RuntimeException('Invalid certificate in chain.');
            }
            if (! openssl_x509_export($chainCert, $cleanedBlock)) {
                throw new \RuntimeException('Failed to process certificate.');
            }
            $cleanedChain[] = trim($cleanedBlock);
        }
        $cleanedCertificate = implode("\n", $cleanedChain)."\n";

        $parsedKey = openssl_pkey_get_private($privateKey);
        if ($parsedKey === false) {
            throw new \RuntimeException('Invalid private key format.');
        }

        if (! openssl_x509_check_private_key($parsedCert, $parsedKey)) {
            throw new \RuntimeException('The private key does not match the certificate.');
        }

        if (! openssl_pkey_export($parsedKey, $cleanedPrivateKey)) {
            throw new \RuntimeException('Failed to process private key.');
        }

        $parsedDetails = openssl_x509_parse($parsedCert);
        if ($parsedDetails === false) {
            throw new \RuntimeException('Failed to parse certificate details.');
        }

        $commonName = data_get($parsedDetails, 'subject.CN');
        if (! is_string($commonName) || $commonName === '') {
            throw new \RuntimeException('Certificate must contain a common name (CN).');
        }

        $subjectAlternativeNames = [];
        if (isset($parsedDetails['extensions']['subjectAltName'])) {
            $altNames = explode(',', (string) $parsedDetails['extensions']['subjectAltName']);
            foreach ($altNames as $altName) {
                $altName = trim($altName);
                if ($altName !== '') {
                    $subjectAlternativeNames[] = $altName;
                }
            }
        }

        $validUntil = CarbonImmutable::createFromTimestamp((int) data_get($parsedDetails, 'validTo_time_t', 0));

        return [
            'certificate' => $cleanedCertificate,
            'private_key' => $cleanedPrivateKey,
            'common_name' => $commonName,
            'subject_alternative_names' => $subjectAlternativeNames,
            'valid_until' => $validUntil,
        ];
    }

    /**
     * @return array{
     *     certificate: string,
     *     private_key: string,
     *     common_name: string,
     *     subject_alternative_names: array<int, string>,
     *     valid_until: \Carbon\CarbonImmutable
     * }
     */
    public static function parseAndValidateApplicationTraefikCertificate(
        string $certificate,
        string $privateKey,
        string $expectedDomain,
    ): array {
        $parsed = self::parseAndValidateProxyCertificate($certificate, $privateKey);
        $normalizedExpected = self::normalizeCertificateDomain($expectedDomain);

        $coveredDomains = collect([$parsed['common_name']])
            ->merge(collect($parsed['subject_alternative_names'])->map(function (string $san) {
                if (str_contains($san, ':')) {
                    return explode(':', $san, 2)[1];
                }

                return $san;
            }))
            ->map(fn (string $domain) => self::normalizeCertificateDomain($domain))
            ->unique()
            ->values();

        $matches = $coveredDomains->contains(function (string $domain) use ($normalizedExpected) {
            if ($domain === $normalizedExpected) {
                return true;
            }

            if (str_starts_with($domain, '*.') && str($normalizedExpected)->endsWith(str($domain)->after('*.'))) {
                return true;
            }

            return false;
        });

        if (! $matches) {
            throw new \RuntimeException("Certificate does not cover domain {$expectedDomain}.");
        }

        return $parsed;
    }

    public static function normalizeCertificateDomain(string $domain): string
    {
        $domain = str($domain)->lower()->trim()->toString();
        $domain = str($domain)->replaceStart('*.', '')->toString();

        if (str_contains($domain, '://')) {
            $domain = Url::fromString($domain)->getHost();
        }

        return $domain;
    }
}
