<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Exception\InvalidValue;

/**
 * Password protection and permissions for a PDF, encrypted with AES-256.
 *
 * The user password opens the document; leave it empty to let anyone open it while the
 * permissions still apply. The owner password lifts the permissions. Without one, a random
 * owner password is generated, so the permissions cannot be lifted at all.
 * Text stays available to screen readers whatever the permissions say.
 */
final readonly class Encryption
{
    /** Longest password a PDF reader accepts, in bytes. */
    private const int MAX_PASSWORD = 127;

    public string $ownerPassword;

    /**
     * @throws InvalidValue for a password over 127 bytes, or an owner password equal to the user password
     */
    public function __construct(
        #[\SensitiveParameter] public string $userPassword = '',
        #[\SensitiveParameter] ?string $ownerPassword = null,
        public bool $print = true,
        public bool $copy = true,
        public bool $modify = false,
        public bool $annotate = false,
        public bool $assemble = false,
    ) {
        foreach (['user password' => $userPassword, 'owner password' => $ownerPassword ?? ''] as $kind => $password) {
            if (strlen($password) > self::MAX_PASSWORD) {
                throw InvalidValue::for($kind, sprintf('%d bytes', strlen($password)))->because('PDF passwords are at most 127 bytes');
            }
        }
        if ($ownerPassword !== null && $ownerPassword === $userPassword) {
            throw InvalidValue::for('owner password', '(same as the user password)')
                ->because('it would lift the permissions for anyone who can open the document; leave it out to generate one');
        }
        $this->ownerPassword = $ownerPassword ?? bin2hex(random_bytes(32));
    }

    /** Opens only with $password; printing and copying allowed, editing not. */
    public static function password(#[\SensitiveParameter] string $password): self
    {
        return new self($password);
    }
}
