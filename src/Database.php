<?php

namespace FloCMS\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

class Database
{
    protected PDO $pdo;

    protected string $table = '';
    protected string $fields = '*';
    protected array $where = [];
    protected array $joins = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected int $limit = 0;
    protected int $offset = 0;
    protected array $order = [];
    protected bool $distinct = false;

    protected array $allowedOperators = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE',
        'IN', 'NOT IN',
        'IS', 'IS NOT'
    ];

    protected array $allowedJoinOperators = [
        '=', '!=', '<>', '<', '>', '<=', '>='
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ------------------ Core Helpers ------------------ */

    protected function reset(): void
    {
        $this->table = '';
        $this->fields = '*';
        $this->where = [];
        $this->joins = [];
        $this->groupBy = [];
        $this->having = [];
        $this->limit = 0;
        $this->offset = 0;
        $this->order = [];
        $this->distinct = false;
    }

    protected function validateIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return $identifier;
        }

        // Handle aliases: "table alias" OR "table AS alias"
        if (preg_match('/^([a-zA-Z0-9_.]+)\s+(?:AS\s+)?([a-zA-Z0-9_]+)$/i', $identifier, $m)) {
            return $m[1] . ' ' . $m[2];
        }

        // Simple identifier: table, column, table.column
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $identifier)) {
            return $identifier;
        }

        throw new InvalidArgumentException("Invalid identifier: {$identifier}");
    }

    protected function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return $identifier;
        }

        // Handle alias cases
        if (preg_match('/^([a-zA-Z0-9_.]+)\s+(?:AS\s+)?([a-zA-Z0-9_]+)$/i', $identifier, $m)) {
            return $this->quote($m[1]) . ' ' . $m[2];
        }

        // Handle dotted identifiers: table.column
        if (str_contains($identifier, '.')) {
            return implode('.', array_map(
                fn ($part) => '`' . str_replace('`', '', $part) . '`',
                explode('.', $identifier)
            ));
        }

        return '`' . str_replace('`', '', $identifier) . '`';
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            throw new InvalidArgumentException('Unsupported object value provided.');
        }

        return $value;
    }

    protected function normalizeArrayValues(array $values): array
    {
        return array_map(fn ($value) => $this->normalizeValue($value), array_values($values));
    }

    protected function addCondition(array &$stack, string $type, string $column, string $operator, mixed $value): void
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, $this->allowedOperators, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        $stack[] = [
            'type' => strtoupper($type) === 'OR' ? 'OR' : 'AND',
            'column' => $this->quote($this->validateIdentifier($column)),
            'operator' => $operator,
            'value' => $value,
        ];
    }

    protected function buildConditions(array $conditions, string $prefix): array
    {
        if (empty($conditions)) {
            return ['', []];
        }

        $sql = ' ' . strtoupper($prefix) . ' ';
        $params = [];

        foreach ($conditions as $i => $condition) {
            if ($i > 0) {
                $sql .= ' ' . $condition['type'] . ' ';
            }

            $column = $condition['column'];
            $operator = $condition['operator'];
            $value = $condition['value'];

            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                if (!is_array($value) || $value === []) {
                    throw new InvalidArgumentException("{$operator} requires a non-empty array value.");
                }

                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $sql .= "{$column} {$operator} ({$placeholders})";
                $params = array_merge($params, $this->normalizeArrayValues($value));
                continue;
            }

            if (in_array($operator, ['IS', 'IS NOT'], true)) {
                if ($value !== null) {
                    throw new InvalidArgumentException("{$operator} only supports NULL values. Use '=' or '!=' for non-null checks.");
                }

                $sql .= "{$column} {$operator} NULL";
                continue;
            }

            $sql .= "{$column} {$operator} ?";
            $params[] = $this->normalizeValue($value);
        }

        return [$sql, $params];
    }

    protected function buildWhere(): array
    {
        return $this->buildConditions($this->where, 'WHERE');
    }

    protected function buildHaving(): array
    {
        return $this->buildConditions($this->having, 'HAVING');
    }

    protected function runWithReset(callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $this->reset();
        }
    }

    /* ------------------ Transactions ------------------ */

    public function transaction(callable $callback): mixed
    {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /* ------------------ Builder ------------------ */

    public function table(string $table): self
    {
        $this->table = $this->quote($this->validateIdentifier($table));
        return $this;
    }

    public function select(string|array $fields): self
    {
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        $quoted = array_map(function ($field) {
            return $this->quote($this->validateIdentifier($field));
        }, $fields);

        if ($this->fields === '*') {
            $this->fields = implode(', ', $quoted);
        } else {
            $this->fields .= ', ' . implode(', ', $quoted);
        }

        return $this;
    }

    /**
     * Add raw select expression.
     * Warning: do not pass untrusted user input here.
    */
    public function selectRaw(string $expression): self
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new InvalidArgumentException('Raw select expression cannot be empty.');
        }

        if ($this->fields === '*') {
            $this->fields = $expression;
        } else {
            $this->fields .= ', ' . $expression;
        }

        return $this;
    }

    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->where, 'AND', $column, $operator, $value);
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->where, 'OR', $column, $operator, $value);
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        return $this->where($column, 'IN', $values);
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->orWhere($column, 'IN', $values);
    }

    public function whereNotIn(string $column, array $values): self
    {
        return $this->where($column, 'NOT IN', $values);
    }

    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->orWhere($column, 'NOT IN', $values);
    }

    public function whereNull(string $column): self
    {
        return $this->where($column, 'IS', null);
    }

    public function orWhereNull(string $column): self
    {
        return $this->orWhere($column, 'IS', null);
    }

    public function whereNotNull(string $column): self
    {
        return $this->where($column, 'IS NOT', null);
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->orWhere($column, 'IS NOT', null);
    }

    public function join(
        string $table,
        string $left,
        string $operator,
        string $right,
        string $type = 'INNER'
    ): self {
        $type = strtoupper(trim($type));
        $operator = strtoupper(trim($operator));

        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new InvalidArgumentException("Invalid JOIN type: {$type}");
        }

        if (!in_array($operator, $this->allowedJoinOperators, true)) {
            throw new InvalidArgumentException("Invalid JOIN operator: {$operator}");
        }

        if ($type === 'CROSS') {
            $this->joins[] = "CROSS JOIN " . $this->quote($this->validateIdentifier($table));
            return $this;
        }

        $this->joins[] =
            "{$type} JOIN " .
            $this->quote($this->validateIdentifier($table)) .
            ' ON ' .
            $this->quote($this->validateIdentifier($left)) .
            " {$operator} " .
            $this->quote($this->validateIdentifier($right));

        return $this;
    }

    public function groupBy(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            $this->groupBy[] = $this->quote($this->validateIdentifier($column));
        }

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->having, 'AND', $column, $operator, $value);
        return $this;
    }

    public function orHaving(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->having, 'OR', $column, $operator, $value);
        return $this;
    }

    public function havingIn(string $column, array $values): self
    {
        return $this->having($column, 'IN', $values);
    }

    public function havingNotIn(string $column, array $values): self
    {
        return $this->having($column, 'NOT IN', $values);
    }

    public function havingNull(string $column): self
    {
        return $this->having($column, 'IS', null);
    }

    public function havingNotNull(string $column): self
    {
        return $this->having($column, 'IS NOT', null);
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';

        $this->order[] = [
            'column' => $this->quote($this->validateIdentifier($column)),
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /* ------------------ Execution ------------------ */

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_map(fn ($value) => $this->normalizeValue($value), $params));
        return $stmt;
    }

    public function get(): array
    {
        return $this->runWithReset(function () {
            $select = $this->distinct ? 'SELECT DISTINCT' : 'SELECT';
            $sql = "{$select} {$this->fields} FROM {$this->table}";

            if ($this->joins) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            [$whereSql, $params] = $this->buildWhere();
            $sql .= $whereSql;

            if ($this->groupBy) {
                $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
            }

            [$havingSql, $havingParams] = $this->buildHaving();
            $sql .= $havingSql;
            $params = array_merge($params, $havingParams);

            if ($this->order) {
                $orderSql = array_map(
                    fn ($order) => $order['column'] . ' ' . $order['direction'],
                    $this->order
                );
                $sql .= ' ORDER BY ' . implode(', ', $orderSql);
            }

            if ($this->limit > 0) {
                $sql .= " LIMIT {$this->limit}";

                if ($this->offset > 0) {
                    $sql .= " OFFSET {$this->offset}";
                }
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        });
    }

    public function first(): ?object
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function insert(array $data): int
    {
        return $this->runWithReset(function () use ($data) {
            if (empty($data)) {
                throw new InvalidArgumentException('No data provided for insert.');
            }

            $columns = array_map(
                fn ($column) => $this->quote($this->validateIdentifier($column)),
                array_keys($data)
            );

            $placeholders = array_fill(0, count($data), '?');

            $sql = "INSERT INTO {$this->table} (" .
                implode(', ', $columns) .
                ') VALUES (' .
                implode(', ', $placeholders) .
                ')';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->normalizeArrayValues($data));

            return (int) $this->pdo->lastInsertId();
        });
    }

    public function update(array $data): bool
    {
        return $this->runWithReset(function () use ($data) {
            if (empty($this->where)) {
                throw new RuntimeException('UPDATE without WHERE is not allowed.');
            }

            if (empty($data)) {
                throw new InvalidArgumentException('No data provided for update.');
            }

            $set = [];
            $bind = [];

            foreach ($data as $column => $value) {
                $column = $this->validateIdentifier($column);
                $set[] = $this->quote($column) . ' = ?';
                $bind[] = $this->normalizeValue($value);
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $set);
            [$whereSql, $params] = $this->buildWhere();

            $stmt = $this->pdo->prepare($sql . $whereSql);
            $stmt->execute(array_merge($bind, $params));

            return true;
        });
    }

    public function delete(): bool
    {
        return $this->runWithReset(function () {
            if (empty($this->where)) {
                throw new RuntimeException('DELETE without WHERE is not allowed.');
            }

            $sql = "DELETE FROM {$this->table}";
            [$whereSql, $params] = $this->buildWhere();

            $stmt = $this->pdo->prepare($sql . $whereSql);
            $stmt->execute($params);

            return true;
        });
    }

    public function count(string $column = '*'): int
    {
        return $this->runWithReset(function () use ($column) {
            $column = $column === '*'
                ? '*'
                : $this->quote($this->validateIdentifier($column));

            $sql = "SELECT COUNT({$column}) AS total FROM {$this->table}";

            if ($this->joins) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            [$whereSql, $params] = $this->buildWhere();
            $sql .= $whereSql;

            if ($this->groupBy) {
                $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
            }

            [$havingSql, $havingParams] = $this->buildHaving();
            $sql .= $havingSql;
            $params = array_merge($params, $havingParams);

            if ($this->order) {
                $orderSql = array_map(
                    fn ($order) => $order['column'] . ' ' . $order['direction'],
                    $this->order
                );
                $sql .= ' ORDER BY ' . implode(', ', $orderSql);
            }

            if ($this->limit > 0) {
                $sql .= " LIMIT {$this->limit}";

                if ($this->offset > 0) {
                    $sql .= " OFFSET {$this->offset}";
                }
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $row = $stmt->fetch(PDO::FETCH_OBJ);
            return (int) ($row->total ?? 0);
        });
    }
}