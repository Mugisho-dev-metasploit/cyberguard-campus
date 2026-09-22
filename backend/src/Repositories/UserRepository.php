<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use CyberGuard\Campus\Models\User;
use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    public function findByUsernameOrEmail(string $identifier): ?User
    {
        $sql = <<<'SQL'
            SELECT
                id,
                uuid,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                role,
                status,
                last_login_at,
                created_at,
                updated_at,
                deleted_at
            FROM users
            WHERE (username = :username OR email = :email)
              AND deleted_at IS NULL
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'username' => $identifier,
            'email' => $identifier,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapToUser($row);
    }

    public function findByUuid(string $uuid): ?User
    {
        $sql = <<<'SQL'
            SELECT
                id,
                uuid,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                role,
                status,
                last_login_at,
                created_at,
                updated_at,
                deleted_at
            FROM users
            WHERE uuid = :uuid
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'uuid' => $uuid,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapToUser($row);
    }

    /**
     * The account behind a session, only while it may still be used: it exists, its status
     * is 'active' and it is not soft-deleted. Null otherwise, whatever the reason.
     */
    public function findActiveById(int $id): ?User
    {
        $sql = <<<'SQL'
            SELECT
                id,
                uuid,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                role,
                status,
                last_login_at,
                created_at,
                updated_at,
                deleted_at
            FROM users
            WHERE id = :id
              AND status = 'active'
              AND deleted_at IS NULL
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapToUser($row);
    }

    public function create(
        string $uuid,
        string $username,
        string $email,
        string $passwordHash,
        string $firstName,
        string $lastName,
        string $role = 'viewer',
        string $status = 'active',
    ): User {
        $sql = <<<'SQL'
            INSERT INTO users (
                uuid,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                role,
                status
            ) VALUES (
                :uuid,
                :username,
                :email,
                :password_hash,
                :first_name,
                :last_name,
                :role,
                :status
            )
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'uuid' => $uuid,
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'status' => $status,
        ]);

        $user = $this->findByUuid($uuid);

        if ($user === null) {
            throw new RuntimeException(
                'User was created but could not be retrieved.'
            );
        }

        return $user;
    }

    public function updateLastLoginAt(int $userId, string $timestamp): void
    {
        $sql = <<<'SQL'
            UPDATE users
            SET last_login_at = :last_login_at
            WHERE id = :id
              AND deleted_at IS NULL
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'last_login_at' => $timestamp,
            'id' => $userId,
        ]);
    }

    /**
     * APP074-10 — replaces the hash of an account after a successful verification, only if the
     * stored hash is still the one that was verified: a password changed meanwhile (for instance
     * reset by an administrator) is never overwritten with the old password. True when replaced.
     */
    public function updatePasswordHash(
        int $userId,
        #[\SensitiveParameter] string $currentHash,
        #[\SensitiveParameter] string $newHash,
    ): bool {
        $sql = <<<'SQL'
            UPDATE users
            SET password_hash = :new_hash
            WHERE id = :id
              AND password_hash = :current_hash
              AND deleted_at IS NULL
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'new_hash' => $newHash,
            'id' => $userId,
            'current_hash' => $currentHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function isAccountActive(int $userId): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM users
            WHERE id = :id
              AND status = 'active'
              AND deleted_at IS NULL
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToUser(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            role: (string) $row['role'],
            status: (string) $row['status'],
            lastLoginAt: $row['last_login_at'] !== null
                ? (string) $row['last_login_at']
                : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: $row['deleted_at'] !== null
                ? (string) $row['deleted_at']
                : null,
        );
    }
}
