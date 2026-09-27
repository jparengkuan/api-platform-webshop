<?php

namespace App\Identity\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generates the RSA key pair that signs (private key) and verifies (public key) the OAuth2 access tokens.
 */
#[AsCommand(name: 'app:oauth:generate-keys', description: 'Generate the RSA key pair for signing OAuth2 access tokens.')]
final readonly class GenerateOAuthKeysCommand
{
    /**
     * Creates the command with the key locations and passphrase from the environment.
     */
    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PRIVATE_KEY)%')]
        private string $privateKeyPath,
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')]
        private string $publicKeyPath,
        #[Autowire('%env(OAUTH_PASSPHRASE)%')]
        private string $passphrase,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Writes a new key pair, encrypting the private key with OAUTH_PASSPHRASE.
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Do nothing when both key files already exist (for container start-up).')]
        bool $skipIfExists = false,
        #[Option('Replace existing key files. Every issued token becomes invalid.')]
        bool $overwrite = false,
    ): int {
        $exists = $this->filesystem->exists([$this->privateKeyPath, $this->publicKeyPath]);

        if ($exists && $skipIfExists) {
            $io->writeln('The key pair already exists.', SymfonyStyle::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        if ($exists && !$overwrite) {
            $io->error('The key pair already exists. Use --overwrite to replace it (this invalidates every issued token).');

            return Command::FAILURE;
        }

        if ('' === $this->passphrase) {
            $io->error('Set OAUTH_PASSPHRASE: the private key must be encrypted.');

            return Command::FAILURE;
        }

        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => \OPENSSL_KEYTYPE_RSA])
            ?: throw new \RuntimeException('OpenSSL could not generate a key: '.openssl_error_string());
        openssl_pkey_export($key, $privateKey, $this->passphrase, ['encrypt_key_cipher' => \OPENSSL_CIPHER_AES_256_CBC])
            ?: throw new \RuntimeException('OpenSSL could not export the private key: '.openssl_error_string());
        $publicKey = openssl_pkey_get_details($key)['key'];

        $this->filesystem->dumpFile($this->privateKeyPath, $privateKey);
        $this->filesystem->chmod($this->privateKeyPath, 0600);
        $this->filesystem->dumpFile($this->publicKeyPath, $publicKey);
        $this->filesystem->chmod($this->publicKeyPath, 0644);

        $io->success(sprintf('Key pair written to %s and %s.', $this->privateKeyPath, $this->publicKeyPath));

        return Command::SUCCESS;
    }
}
