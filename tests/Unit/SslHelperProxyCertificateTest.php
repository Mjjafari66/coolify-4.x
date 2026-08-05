<?php

use App\Helpers\SslHelper;

function generateTestCertificateKeyPair(string $commonName = 'example.com'): array
{
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    $dn = [
        'commonName' => $commonName,
    ];

    $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);

    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $privateKeyPem);

    return [
        'certificate' => $certificatePem,
        'private_key' => $privateKeyPem,
    ];
}

it('validates matching certificate and private key', function () {
    $pair = generateTestCertificateKeyPair('m-shahabadi.com');

    $parsed = SslHelper::parseAndValidateProxyCertificate($pair['certificate'], $pair['private_key']);

    expect($parsed['common_name'])->toBe('m-shahabadi.com');
    expect($parsed['certificate'])->toContain('BEGIN CERTIFICATE');
    expect($parsed['private_key'])->toContain('BEGIN');
});

it('rejects a private key that does not match the certificate', function () {
    $pair = generateTestCertificateKeyPair('example.com');
    $otherPair = generateTestCertificateKeyPair('other.example.com');

    expect(fn () => SslHelper::parseAndValidateProxyCertificate($pair['certificate'], $otherPair['private_key']))
        ->toThrow(RuntimeException::class, 'The private key does not match the certificate.');
});

it('rejects invalid certificate content', function () {
    $pair = generateTestCertificateKeyPair();

    expect(fn () => SslHelper::parseAndValidateProxyCertificate('invalid-cert', $pair['private_key']))
        ->toThrow(RuntimeException::class, 'Invalid certificate format.');
});
