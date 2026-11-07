<?php

namespace App\Services;

use RuntimeException;

/**
 * QZ Tray print signing. QZ Tray on the hotel's computer trusts print requests from this site
 * when they come with its certificate and are signed (SHA512 with RSA) by the matching
 * private key. Without them QZ Tray still prints, after asking the user to allow each one.
 */
class QzTraySigning
{
    public const ALGORITHM = 'SHA512';

    public function isConfigured(): bool
    {
        return $this->certificate() !== null && is_readable($this->path('private_key'));
    }

    /**
     * The site's public certificate (PEM), or null when none is set up.
     */
    public function certificate(): ?string
    {
        $path = $this->path('certificate');

        return is_readable($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * The base64 signature QZ Tray checks for one request, or null when signing isn't set up.
     *
     * @throws RuntimeException when the private key can't be used
     */
    public function sign(string $request): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $key = openssl_pkey_get_private((string) file_get_contents($this->path('private_key')), config('services.qz_tray.private_key_passphrase') ?: null);

        if ($key === false || ! openssl_sign($request, $signature, $key, OPENSSL_ALGO_SHA512)) {
            throw new RuntimeException('The QZ Tray private key could not sign the print request.');
        }

        return base64_encode($signature);
    }

    /**
     * Makes a new private key and a self-signed certificate for it (valid $days days). QZ Tray
     * only trusts a self-signed certificate copied onto each computer as override.crt; a
     * certificate issued by QZ is trusted everywhere.
     *
     * @return array{certificate: string, private_key: string}
     */
    public function generate(string $commonName, string $organisation, int $days = 3650): array
    {
        // PHP on Windows often has no openssl.cnf; a minimal one is enough here.
        $config = tempnam(sys_get_temp_dir(), 'qz');
        file_put_contents($config, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        $options = ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha512'];

        try {
            $key = openssl_pkey_new($options);
            $request = $key ? openssl_csr_new(['commonName' => $commonName, 'organizationName' => $organisation], $key, $options) : false;
            $certificate = $request ? openssl_csr_sign($request, null, $key, $days, $options) : false;

            if ($key === false || $certificate === false || ! openssl_pkey_export($key, $privateKey, null, $options) || ! openssl_x509_export($certificate, $certificatePem)) {
                throw new RuntimeException('OpenSSL could not make the key pair: '.openssl_error_string());
            }
        } finally {
            @unlink($config);
        }

        return ['certificate' => $certificatePem, 'private_key' => $privateKey];
    }

    public function path(string $which): string
    {
        return (string) config("services.qz_tray.{$which}");
    }
}
