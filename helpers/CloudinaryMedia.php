<?php
declare(strict_types=1);

/** Signed Cloudinary API client for property images and videos. */
final class CloudinaryMedia
{
    public static function enabled(): bool
    {
        return Config::get('media.provider') === 'cloudinary';
    }

    /** @return array{public_id:string,secure_url:string} */
    public static function upload(string $temporaryFile, string $mime, string $kind, int $recordId): array
    {
        self::requireConfiguration();
        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            throw new RuntimeException('The PHP cURL extension is required for Cloudinary media uploads.');
        }

        $folder = "real-estate-crm/properties/{$recordId}/{$kind}s";
        $publicId = bin2hex(random_bytes(16));
        $timestamp = time();
        $signature = self::signature([
            'folder' => $folder,
            'overwrite' => 'false',
            'public_id' => $publicId,
            'timestamp' => (string) $timestamp,
        ]);
        $endpoint = 'https://api.cloudinary.com/v1_1/' . rawurlencode((string) Config::get('media.cloudinary.cloud_name')) . "/{$kind}/upload";
        $response = self::post($endpoint, [
            'api_key' => Config::get('media.cloudinary.api_key'),
            'timestamp' => (string) $timestamp,
            'signature' => $signature,
            'folder' => $folder,
            'public_id' => $publicId,
            'overwrite' => 'false',
            'file' => new CURLFile($temporaryFile, $mime),
        ]);

        if (!isset($response['public_id'], $response['secure_url'])) {
            $message = is_string($response['error']['message'] ?? null) ? $response['error']['message'] : 'Cloudinary did not return a media URL.';
            throw new RuntimeException("Cloudinary upload failed: {$message}");
        }

        return ['public_id' => (string) $response['public_id'], 'secure_url' => (string) $response['secure_url']];
    }

    public static function destroy(string $publicId, string $kind): void
    {
        if (!self::enabled() || $publicId === '') return;
        self::requireConfiguration();
        $timestamp = time();
        $response = self::post(
            'https://api.cloudinary.com/v1_1/' . rawurlencode((string) Config::get('media.cloudinary.cloud_name')) . "/{$kind}/destroy",
            [
                'api_key' => Config::get('media.cloudinary.api_key'),
                'timestamp' => (string) $timestamp,
                'public_id' => $publicId,
                'invalidate' => 'true',
                'signature' => self::signature([
                    'invalidate' => 'true',
                    'public_id' => $publicId,
                    'timestamp' => (string) $timestamp,
                ]),
            ],
        );
        $result = $response['result'] ?? null;
        if ($result !== 'ok' && $result !== 'not found') {
            $message = is_string($response['error']['message'] ?? null) ? $response['error']['message'] : 'Cloudinary could not delete the media.';
            throw new RuntimeException("Cloudinary deletion failed: {$message}");
        }
    }

    private static function requireConfiguration(): void
    {
        foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
            if (trim((string) Config::get("media.cloudinary.{$key}")) === '') {
                throw new RuntimeException('Cloudinary is not configured. Set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET.');
            }
        }
    }

    /** @param array<string, string> $params */
    private static function signature(array $params): string
    {
        // Cloudinary signs the raw, sorted name=value pairs. URL-encoding the
        // folder value (and therefore its slashes) produces an invalid signature.
        ksort($params);
        $toSign = implode('&', array_map(static fn (string $key, string $value) => "{$key}={$value}", array_keys($params), $params));
        return hash('sha1', $toSign . (string) Config::get('media.cloudinary.api_secret'));
    }

    /** @return array<string, mixed> */
    private static function post(string $endpoint, array $fields): array
    {
        $curl = curl_init($endpoint);
        if ($curl === false) throw new RuntimeException('Could not start the Cloudinary request.');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false) throw new RuntimeException('Cloudinary request failed: ' . ($error ?: 'unknown network error'));
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException("Cloudinary returned an invalid response (HTTP {$status}).");
        return $decoded;
    }
}
