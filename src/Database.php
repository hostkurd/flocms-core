<?php

namespace FloCMS\Core;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO wrapper and query builder.
 *
 * table() returns a new builder for each query, so two queries can be built at
 * the same time. Builder calls made directly on the shared instance (e.g.
 * $db->where(...) after a separate $db->table(...) statement) go to the query
 * its last table() call returned, so code that does not chain keeps working
 * as it did in 2.1.
 */
class Database
{
    protected PDO $pdo;

    /** Shared by every builder of this connection (holds the transaction depth). */
    private object $connectionState;

    /** True for builders returned by table() and for condition groups. */
    private bool $isQuery = false;

    /** The builder returned by the last table() call on the shared instance. */
    private ?self $current = null;

    protected string $table = '';
    protected string $fields = '*';
    protected array $fieldBindings = [];
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
        $this->connectionState = (object) ['transactionDepth' => 0];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /* ------------------ Core Helpers ------------------ */

    protected function reset(): void
    {
        $this->table = '';
        $this->fields = '*';
        $this->fieldBindings = [];
        $this->where = [];
        $this->joins = [];
        $this->groupBy = [];
        $this->having = [];
        $this->limit = 0;
        $this->offset = 0;
        $this->order = [];
        $this->distinct = false;
    }

    /**
     * The builder a call on this object applies to: the current query when
     * called on the shared instance after table(), otherwise this object.
     */
    private function builder(): self
    {
        return (!$this->isQuery && $this->current !== null) ? $this->current : $this;
    }

    private function newBuilder(): self
    {
        $builder = clone $this;
        $builder->reset();
        $builder->current = null;
        $builder->isQuery = true;

        return $builder;
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

    /**
     * Add a parenthesised group: $callback receives a fresh builder; the
     * conditions it adds (where* for WHERE groups, having* for HAVING groups)
     * are wrapped in ( ... ).
     */
    private function groupCondition(string $type, Closure $callback, string $clause): ?array
    {
        $group = $this->newBuilder();
        $callback($group);

        $conditions = $clause === 'having' ? $group->having : $group->where;

        if ($conditions === []) {
            return null;
        }

        return [
            'type' => $type === 'OR' ? 'OR' : 'AND',
            'group' => $conditions,
        ];
    }

    /**
     * @param list<mixed> $bindings one value per "?" placeholder
     */
    private function rawCondition(string $type, string $sql, array $bindings): array
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new InvalidArgumentException('Raw condition cannot be empty.');
        }

        self::assertBindingCount($sql, $bindings);

        return [
            'type' => $type === 'OR' ? 'OR' : 'AND',
            'raw' => $sql,
            'bindings' => array_values($bindings),
        ];
    }

    private function betweenCondition(string $type, string $column, array $range, bool $not): array
    {
        $range = array_values($range);

        if (count($range) !== 2) {
            throw new InvalidArgumentException('BETWEEN requires exactly two values: [min, max].');
        }

        return [
            'type' => $type === 'OR' ? 'OR' : 'AND',
            'between' => $not ? 'NOT BETWEEN' : 'BETWEEN',
            'column' => $this->quote($this->validateIdentifier($column)),
            'value' => $range,
        ];
    }

    /**
     * Raw SQL must use "?" placeholders with exactly one binding each, so no
     * value can be concatenated into the SQL by mistake.
     */
    private static function assertBindingCount(string $sql, array $bindings): void
    {
        if (preg_match('/(?<!:):[a-zA-Z_]/', $sql)) {
            throw new InvalidArgumentException('Raw SQL must use "?" placeholders, not named placeholders.');
        }

        $placeholders = substr_count($sql, '?');

        if ($placeholders !== count($bindings)) {
            throw new InvalidArgumentException(sprintf(
                'Raw SQL has %d "?" placeholder(s) but %d binding(s) were given.',
                $placeholders,
                count($bindings)
            ));
        }
    }

    /**
     * @return array{0: string, 1: list<mixed>} SQL without prefix, and its bindings
     */
    private function compileConditions(array $conditions): array
    {
        $sql = '';
        $params = [];

        foreach (array_values($conditions) as $i => $condition) {
            if ($i > 0) {
                $sql .= ' ' . $condition['type'] . ' ';
            }

            if (isset($condition['group'])) {
                [$groupSql, $groupParams] = $this->compileConditions($condition['group']);
                $sql .= '(' . $groupSql . ')';
                $params = array_merge($params, $groupParams);
                continue;
            }

            if (isset($condition['raw'])) {
                $sql .= '(' . $condition['raw'] . ')';
                $params = array_merge($params, $this->normalizeArrayValues($condition['bindings']));
                continue;
            }

            $column = $condition['column'];
            $value = $condition['value'];

            if (isset($condition['between'])) {
                $sql .= "{$column} {$condition['between']} ? AND ?";
                $params = array_merge($params, $this->normalizeArrayValues($value));
                continue;
            }

            $operator = $condition['operator'];

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

    protected function buildConditions(array $conditions, string $prefix): array
    {
        if (empty($conditions)) {
            return ['', []];
        }

        [$sql, $params] = $this->compileConditions($conditions);

        return [' ' . strtoupper($prefix) . ' ' . $sql, $params];
    }

    protected function buildWhere(): array
    {
        return $this->buildConditions($this->where, 'WHERE');
    }

    protected function buildHaving(): array
    {
        return $this->buildConditions($this->having, 'HAVING');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildOrder(): array
    {
        if (!$this->order) {
            return ['', []];
        }

        $parts = [];
        $params = [];

        foreach ($this->order as $order) {
            if (isset($order['raw'])) {
                $parts[] = $order['raw'];
                $params = array_merge($params, $this->normalizeArrayValues($order['bindings']));
                continue;
            }

            $parts[] = $order['column'] . ' ' . $order['direction'];
        }

        return [' ORDER BY ' . implode(', ', $parts), $params];
    }

    private function buildLimit(): string
    {
        if ($this->limit <= 0) {
            return '';
        }

        return " LIMIT {$this->limit}" . ($this->offset > 0 ? " OFFSET {$this->offset}" : '');
    }

    /**
     * FROM ... JOIN ... WHERE ... GROUP BY ... HAVING ...
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildFromClause(): array
    {
        $sql = " FROM {$this->table}";

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

        return [$sql, array_merge($params, $havingParams)];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSelect(bool $withOrderAndLimit = true): array
    {
        $select = $this->distinct ? 'SELECT DISTINCT' : 'SELECT';
        [$fromSql, $params] = $this->buildFromClause();
        $sql = "{$select} {$this->fields}" . $fromSql;
        $params = array_merge($this->normalizeArrayValues($this->fieldBindings), $params);

        if ($withOrderAndLimit) {
            [$orderSql, $orderParams] = $this->buildOrder();
            $sql .= $orderSql . $this->buildLimit();
            $params = array_merge($params, $orderParams);
        }

        return [$sql, $params];
    }

    /**
     * Prepare and execute.
     *
     * On SQLite, integers are bound as integers: a value bound as text never
     * equals a number there, so COUNT(*) > ? or price * 2 > ? would never match.
     * Other drivers keep binding every value as a string, as before: binding an
     * integer on MySQL would turn `token = 0` into a numeric comparison, which
     * matches any non-numeric string.
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        if ($this->driver() !== 'sqlite') {
            $stmt->execute(array_values($params));
            return $stmt;
        }

        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }

        $stmt->execute();

        return $stmt;
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
        $state = $this->connectionState;
        $savepoint = 'flo_savepoint_' . $state->transactionDepth;
        $isOuterTransaction = $state->transactionDepth === 0;
        $entered = false;

        try {
            if ($isOuterTransaction) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
            }

            $state->transactionDepth++;
            $entered = true;
            $result = $callback($this);

            $state->transactionDepth--;
            $entered = false;
            if ($isOuterTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            if ($entered) {
                $state->transactionDepth = max(0, $state->transactionDepth - 1);
            }

            if ($isOuterTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$isOuterTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }
    }

    /* ------------------ Builder ------------------ */

    /**
     * Start a query. Returns a new builder, so queries built at the same time
     * do not share state.
     */
    public function table(string $table): self
    {
        $quoted = $this->quote($this->validateIdentifier($table));

        // Inside a chain: change the table of this query (2.1 behaviour)
        if ($this->isQuery) {
            $this->table = $quoted;
            return $this;
        }

        $this->current = $this->newBuilder();
        $this->current->table = $quoted;

        return $this->current;
    }

    public function select(string|array $fields): self
    {
        $q = $this->builder();

        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        $quoted = array_map(function ($field) use ($q) {
            return $q->quote($q->validateIdentifier($field));
        }, $fields);

        if ($q->fields === '*') {
            $q->fields = implode(', ', $quoted);
        } else {
            $q->fields .= ', ' . implode(', ', $quoted);
        }

        return $q;
    }

    /**
     * Add raw select expression, e.g. a distance:
     * selectRaw('ST_Distance_Sphere(location, POINT(?, ?)) AS distance', [$lng, $lat])
     *
     * Warning: do not put untrusted input into $expression; pass values as bindings.
    */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $q = $this->builder();
        $expression = trim($expression);

        if ($expression === '') {
            throw new InvalidArgumentException('Raw select expression cannot be empty.');
        }

        if ($bindings !== []) {
            self::assertBindingCount($expression, $bindings);
        }

        if ($q->fields === '*') {
            $q->fields = $expression;
        } else {
            $q->fields .= ', ' . $expression;
        }

        $q->fieldBindings = array_merge($q->fieldBindings, array_values($bindings));

        return $q;
    }

    public function distinct(bool $value = true): self
    {
        $q = $this->builder();
        $q->distinct = $value;
        return $q;
    }

    /**
     * where('status', '=', 1), or a parenthesised group:
     * where(fn (Database $q) => $q->where('a', '=', 1)->orWhere('b', '=', 2))
     */
    public function where(string|Closure $column, ?string $operator = null, mixed $value = null): self
    {
        $q = $this->builder();

        if ($column instanceof Closure) {
            if ($condition = $q->groupCondition('AND', $column, 'where')) {
                $q->where[] = $condition;
            }
            return $q;
        }

        self::assertOperatorAndValue(func_num_args(), $operator);
        $q->addCondition($q->where, 'AND', $column, $operator, $value);
        return $q;
    }

    public function orWhere(string|Closure $column, ?string $operator = null, mixed $value = null): self
    {
        $q = $this->builder();

        if ($column instanceof Closure) {
            if ($condition = $q->groupCondition('OR', $column, 'where')) {
                $q->where[] = $condition;
            }
            return $q;
        }

        self::assertOperatorAndValue(func_num_args(), $operator);
        $q->addCondition($q->where, 'OR', $column, $operator, $value);
        return $q;
    }

    private static function assertOperatorAndValue(int $argumentCount, ?string $operator): void
    {
        if ($argumentCount < 3 || $operator === null) {
            throw new InvalidArgumentException('where() needs a column, an operator and a value, or a Closure.');
        }
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

    /**
     * whereBetween('price', [100000, 250000])
     */
    public function whereBetween(string $column, array $range): self
    {
        $q = $this->builder();
        $q->where[] = $q->betweenCondition('AND', $column, $range, false);
        return $q;
    }

    public function orWhereBetween(string $column, array $range): self
    {
        $q = $this->builder();
        $q->where[] = $q->betweenCondition('OR', $column, $range, false);
        return $q;
    }

    public function whereNotBetween(string $column, array $range): self
    {
        $q = $this->builder();
        $q->where[] = $q->betweenCondition('AND', $column, $range, true);
        return $q;
    }

    public function orWhereNotBetween(string $column, array $range): self
    {
        $q = $this->builder();
        $q->where[] = $q->betweenCondition('OR', $column, $range, true);
        return $q;
    }

    /**
     * Raw condition with required bindings, one per "?":
     * whereRaw('MATCH(title, body) AGAINST(? IN BOOLEAN MODE)', [$terms])
     *
     * The condition is wrapped in parentheses. Never concatenate input into $sql.
     */
    public function whereRaw(string $sql, array $bindings): self
    {
        $q = $this->builder();
        $q->where[] = $q->rawCondition('AND', $sql, $bindings);
        return $q;
    }

    public function orWhereRaw(string $sql, array $bindings): self
    {
        $q = $this->builder();
        $q->where[] = $q->rawCondition('OR', $sql, $bindings);
        return $q;
    }

    public function join(
        string $table,
        string $left,
        string $operator,
        string $right,
        string $type = 'INNER'
    ): self {
        $q = $this->builder();
        $type = strtoupper(trim($type));
        $operator = strtoupper(trim($operator));

        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new InvalidArgumentException("Invalid JOIN type: {$type}");
        }

        if (!in_array($operator, $q->allowedJoinOperators, true)) {
            throw new InvalidArgumentException("Invalid JOIN operator: {$operator}");
        }

        if ($type === 'CROSS') {
            $q->joins[] = "CROSS JOIN " . $q->quote($q->validateIdentifier($table));
            return $q;
        }

        $q->joins[] =
            "{$type} JOIN " .
            $q->quote($q->validateIdentifier($table)) .
            ' ON ' .
            $q->quote($q->validateIdentifier($left)) .
            " {$operator} " .
            $q->quote($q->validateIdentifier($right));

        return $q;
    }

    public function groupBy(string|array $columns): self
    {
        $q = $this->builder();
        $columns = is_array($columns) ? $columns : [$columns];

        foreach ($columns as $column) {
            $q->groupBy[] = $q->quote($q->validateIdentifier($column));
        }

        return $q;
    }

    public function having(string|Closure $column, ?string $operator = null, mixed $value = null): self
    {
        $q = $this->builder();

        if ($column instanceof Closure) {
            if ($condition = $q->groupCondition('AND', $column, 'having')) {
                $q->having[] = $condition;
            }
            return $q;
        }

        self::assertOperatorAndValue(func_num_args(), $operator);
        $q->addCondition($q->having, 'AND', $column, $operator, $value);
        return $q;
    }

    public function orHaving(string|Closure $column, ?string $operator = null, mixed $value = null): self
    {
        $q = $this->builder();

        if ($column instanceof Closure) {
            if ($condition = $q->groupCondition('OR', $column, 'having')) {
                $q->having[] = $condition;
            }
            return $q;
        }

        self::assertOperatorAndValue(func_num_args(), $operator);
        $q->addCondition($q->having, 'OR', $column, $operator, $value);
        return $q;
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

    /**
     * Raw HAVING condition with required bindings, one per "?".
     */
    public function havingRaw(string $sql, array $bindings): self
    {
        $q = $this->builder();
        $q->having[] = $q->rawCondition('AND', $sql, $bindings);
        return $q;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $q = $this->builder();
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';

        $q->order[] = [
            'column' => $q->quote($q->validateIdentifier($column)),
            'direction' => $direction,
        ];

        return $q;
    }

    /**
     * Raw ORDER BY expression, e.g. orderByRaw('distance ASC') or
     * orderByRaw('ST_Distance_Sphere(location, POINT(?, ?))', [$lng, $lat]).
     * Never concatenate input into $sql.
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $q = $this->builder();
        $sql = trim($sql);

        if ($sql === '') {
            throw new InvalidArgumentException('Raw ORDER BY expression cannot be empty.');
        }

        self::assertBindingCount($sql, $bindings);

        $q->order[] = [
            'raw' => $sql,
            'bindings' => array_values($bindings),
        ];

        return $q;
    }

    public function limit(int $limit): self
    {
        $q = $this->builder();
        $q->limit = max(0, $limit);
        return $q;
    }

    public function offset(int $offset): self
    {
        $q = $this->builder();
        $q->offset = max(0, $offset);
        return $q;
    }

    /* ------------------ Execution ------------------ */

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_map(fn ($value) => $this->normalizeValue($value), $params));
        return $stmt;
    }

    /**
     * The SELECT this query would run, with its bindings. Does not run or reset it.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        return $this->builder()->buildSelect();
    }

    public function get(): array
    {
        $q = $this->builder();

        return $q->runWithReset(function () use ($q) {
            [$sql, $params] = $q->buildSelect();

            $stmt = $q->run($sql, $params);

            return $stmt->fetchAll(PDO::FETCH_OBJ);
        });
    }

    public function first(): ?object
    {
        $q = $this->builder();
        $q->limit(1);
        $rows = $q->get();
        return $rows[0] ?? null;
    }

    /**
     * Run the query for one page and count all matching rows.
     *
     * @return array{data: list<object>, pagination: array<string, mixed>} where
     *         'pagination' has the same keys as Model::pagingArray()
     */
    public function paginate(int $page, int $perPage): array
    {
        $q = $this->builder();
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        try {
            [$sql, $params] = $q->buildSelect(false);

            if ($q->groupBy || $q->having || $q->distinct) {
                $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) flo_paginate";
            } else {
                [$fromSql, $params] = $q->buildFromClause();
                $countSql = 'SELECT COUNT(*) AS total' . $fromSql;
            }

            $stmt = $q->run($countSql, $params);
            $row = $stmt->fetch(PDO::FETCH_OBJ);
            $total = (int) ($row->total ?? 0);
        } catch (Throwable $e) {
            $q->reset();
            throw $e;
        }

        $rows = $q->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'data' => $rows,
            'pagination' => Pagination::meta($page, $perPage, $total),
        ];
    }

    public function insert(array $data): int
    {
        $q = $this->builder();

        return $q->runWithReset(function () use ($q, $data) {
            if (empty($data)) {
                throw new InvalidArgumentException('No data provided for insert.');
            }

            $columns = array_map(
                fn ($column) => $q->quote($q->validateIdentifier($column)),
                array_keys($data)
            );

            $placeholders = array_fill(0, count($data), '?');

            $sql = "INSERT INTO {$q->table} (" .
                implode(', ', $columns) .
                ') VALUES (' .
                implode(', ', $placeholders) .
                ')';

            $stmt = $q->run($sql, $q->normalizeArrayValues($data));

            return (int) $q->pdo->lastInsertId();
        });
    }

    public function update(array $data): bool
    {
        $q = $this->builder();

        return $q->runWithReset(function () use ($q, $data) {
            if (empty($q->where)) {
                throw new RuntimeException('UPDATE without WHERE is not allowed.');
            }

            if (empty($data)) {
                throw new InvalidArgumentException('No data provided for update.');
            }

            $set = [];
            $bind = [];

            foreach ($data as $column => $value) {
                $column = $q->validateIdentifier($column);
                $set[] = $q->quote($column) . ' = ?';
                $bind[] = $q->normalizeValue($value);
            }

            $sql = "UPDATE {$q->table} SET " . implode(', ', $set);
            [$whereSql, $params] = $q->buildWhere();

            $stmt = $q->run($sql . $whereSql, array_merge($bind, $params));

            return true;
        });
    }

    public function delete(): bool
    {
        $q = $this->builder();

        return $q->runWithReset(function () use ($q) {
            if (empty($q->where)) {
                throw new RuntimeException('DELETE without WHERE is not allowed.');
            }

            $sql = "DELETE FROM {$q->table}";
            [$whereSql, $params] = $q->buildWhere();

            $stmt = $q->run($sql . $whereSql, $params);

            return true;
        });
    }

    public function count(string $column = '*'): int
    {
        $q = $this->builder();

        return $q->runWithReset(function () use ($q, $column) {
            $column = $column === '*'
                ? '*'
                : $q->quote($q->validateIdentifier($column));

            [$fromSql, $params] = $q->buildFromClause();
            [$orderSql, $orderParams] = $q->buildOrder();
            $sql = "SELECT COUNT({$column}) AS total" . $fromSql . $orderSql . $q->buildLimit();

            $stmt = $q->run($sql, array_merge($params, $orderParams));

            $row = $stmt->fetch(PDO::FETCH_OBJ);
            return (int) ($row->total ?? 0);
        });
    }
}
