<?php

declare(strict_types=1);

namespace Example\Todo;

use PDO;

final class TodoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS todos ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'title TEXT NOT NULL, '
            . 'completed INTEGER NOT NULL DEFAULT 0, '
            . 'created_at TEXT NOT NULL'
            . ')',
        );
    }

    /** @return list<array{id: int, title: string, completed: bool, created_at: string}> */
    public function all(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $statement = $this->pdo->prepare(
            'SELECT id, title, completed, created_at FROM todos ORDER BY id LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    /** @return array{id: int, title: string, completed: bool, created_at: string}|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, completed, created_at FROM todos WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /** @return array{id: int, title: string, completed: bool, created_at: string} */
    public function create(string $title, bool $completed = false): array
    {
        $createdAt = gmdate('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO todos (title, completed, created_at) VALUES (:title, :completed, :created_at)',
        );
        $statement->execute([
            'title' => $title,
            'completed' => $completed ? 1 : 0,
            'created_at' => $createdAt,
        ]);

        $todo = $this->find((int) $this->pdo->lastInsertId());
        if ($todo === null) {
            throw new \RuntimeException('Todo could not be read after insertion.');
        }

        return $todo;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM todos WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function ping(): bool
    {
        return (bool) $this->pdo->query('SELECT 1')->fetchColumn();
    }

    /** @return array{id: int, title: string, completed: bool, created_at: string}|null */
    public function update(int $id, string $title, bool $completed): ?array
    {
        $statement = $this->pdo->prepare(
            'UPDATE todos SET title = :title, completed = :completed WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'title' => $title,
            'completed' => $completed ? 1 : 0,
        ]);

        return $this->find($id);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'completed' => (bool) $row['completed'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
