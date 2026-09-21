<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Support\Database;
use Dilibori\ControlPlane\Support\Uuid;
use PDO;
use RuntimeException;

final class SecretStore
{
    public function __construct(
        private PDO $db,
        private string $secretsPath,
        private string $secretKey,
    ) {
    }

    public static function make(string $secretsPath, string $secretKey): self
    {
        return new self(Database::connection(), $secretsPath, $secretKey);
    }

    public function store(string $deploymentId, string $keyName, string $plaintext): string
    {
        $this->assertKey();

        $encrypted = $this->encrypt($plaintext);
        $dir = rtrim($this->secretsPath, '/') . '/' . $deploymentId;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create secrets directory: {$dir}");
        }

        $ref = "{$dir}/{$keyName}.json";
        file_put_contents($ref, json_encode($encrypted, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($ref, 0600);

        $metaId = Uuid::v4();
        $statement = $this->db->prepare(
            'INSERT INTO secrets_meta (id, deployment_id, key_name, secret_ref)
             VALUES (:id, :deployment_id, :key_name, :secret_ref)
             ON CONFLICT (deployment_id, key_name)
             DO UPDATE SET secret_ref = EXCLUDED.secret_ref',
        );
        $statement->execute([
            'id' => $metaId,
            'deployment_id' => $deploymentId,
            'key_name' => $keyName,
            'secret_ref' => $ref,
        ]);

        return $ref;
    }

    public function decrypt(string $deploymentId, string $keyName): string
    {
        $statement = $this->db->prepare(
            'SELECT secret_ref FROM secrets_meta WHERE deployment_id = :deployment_id AND key_name = :key_name',
        );
        $statement->execute(['deployment_id' => $deploymentId, 'key_name' => $keyName]);
        $row = $statement->fetch();

        if (!$row) {
            throw new RuntimeException("Secret meta not found: {$keyName}");
        }

        $payload = json_decode(file_get_contents($row['secret_ref']), true, 512, JSON_THROW_ON_ERROR);

        return $this->decryptPayload($payload);
    }

    /** @return list<array{id: string, key_name: string, secret_ref: string}> */
    public function listMeta(string $deploymentId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, key_name, secret_ref FROM secrets_meta WHERE deployment_id = :deployment_id ORDER BY key_name',
        );
        $statement->execute(['deployment_id' => $deploymentId]);

        return $statement->fetchAll();
    }

    private function assertKey(): void
    {
        if (strlen($this->secretKey) < 32) {
            throw new RuntimeException('APP_SECRET_KEY must be at least 32 bytes');
        }
    }

    /** @return array{ciphertext: string, iv: string, tag: string} */
    private function encrypt(string $plaintext): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            substr(hash('sha256', $this->secretKey, true), 0, 32),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /** @param array{ciphertext: string, iv: string, tag: string} $payload */
    private function decryptPayload(array $payload): string
    {
        $plaintext = openssl_decrypt(
            base64_decode($payload['ciphertext'], true),
            'aes-256-gcm',
            substr(hash('sha256', $this->secretKey, true), 0, 32),
            OPENSSL_RAW_DATA,
            base64_decode($payload['iv'], true),
            base64_decode($payload['tag'], true),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plaintext;
    }
}
