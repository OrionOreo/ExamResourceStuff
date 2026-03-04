<?php

declare(strict_types=1);

const PG_DEFAULT_HOST = "127.0.0.1";
const PG_DEFAULT_DBNAME = "postgres";
const PG_DEFAULT_USER = "postgres";
const PG_DEFAULT_PASSWORD = "postgres";

/**
 * Open a PostgreSQL connection (optionally via DSN).
 */
function pg_open_db_connection(?string $url = null): \PgSql\Connection|false
{
    if ($url !== null) {
        return pg_connect($url);
    }

    $host = PG_DEFAULT_HOST;
    $dbname = PG_DEFAULT_DBNAME;
    $user = PG_DEFAULT_USER;
    $password = PG_DEFAULT_PASSWORD;

    return pg_connect("host=$host dbname=$dbname user=$user password=$password");
}

/**
 * Run a query using an existing connection or auto-open if none.
 * Returns associative arrays for SELECT queries, or true for others.
 */
function pg_make_db_query(
    ?\PgSql\Connection $conn,
    string $query,
    ?array $params = null
): array|bool {
    $owns_conn = $conn === null;
    if ($owns_conn) {
        $conn = pg_open_db_connection();
    }

    try {
        $result = $params
            ? pg_query_params($conn, $query, $params)
            : pg_query($conn, $query);

        if (!$result) {
            return false;
        }

        // SELECT-type query
        if (pg_num_fields($result) > 0) {
            $rows = [];
            while ($row = pg_fetch_assoc($result)) {
                $rows[] = $row;
            }
            pg_free_result($result);
            return $rows;
        }

        pg_free_result($result);
        return true;
    } finally {
        if ($owns_conn) {
            pg_close($conn);
        }
    }
}

/**
 * Insert a new record.
 */
function pg_make_new_record(
    ?\PgSql\Connection $conn,
    string $table,
    array $data
): bool {
    $cols = array_keys($data);
    $placeholders = array_fill(0, count($cols), '$' . (count($cols)));
    $values = array_values($data);

    // simpler (manual) index placeholders
    $ph = [];
    foreach (array_keys($values) as $i) {
        $ph[] = '$' . ($i + 1);
    }

    $sql = sprintf(
        "INSERT INTO %s (%s) VALUES (%s)",
        $table,
        implode(', ', $cols),
        implode(', ', $ph)
    );

    return (bool) pg_make_db_query($conn, $sql, $values);
}

/**
 * Delete record by ID.
 */
function pg_delete_record_by_id(
    ?\PgSql\Connection $conn,
    string $table,
    int|string $record_id
): bool {
    $sql = "DELETE FROM {$table} WHERE id = $1";
    return (bool) pg_make_db_query($conn, $sql, [$record_id]);
}

/**
 * Fetch record by ID.
 */
function pg_fetch_record_by_id(
    ?\PgSql\Connection $conn,
    string $table,
    int|string $record_id
): ?array {
    $sql = "SELECT * FROM {$table} WHERE id = $1";
    $rows = pg_make_db_query($conn, $sql, [$record_id]);
    return (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
}

/**
 * Fetch multiple records matching a column value.
 */
function pg_fetch_records_by_value(
    ?\PgSql\Connection $conn,
    string $table,
    string $search_col = "id",
    mixed $search_value = null,
    int $limit = 0
): ?array {
    $sql = "SELECT * FROM {$table} WHERE {$search_col} = $1";
    if ($limit > 0) {
        $sql .= " LIMIT {$limit}";
    }
    $rows = pg_make_db_query($conn, $sql, [$search_value]);
    return (is_array($rows) && count($rows)) ? $rows : null;
}

/**
 * Fetch a single column as a list.
 */
function pg_fetch_column(
    ?\PgSql\Connection $conn,
    string $table,
    string $column
): ?array {
    $sql = "SELECT {$column} FROM {$table}";
    $rows = pg_make_db_query($conn, $sql);
    if (!is_array($rows)) return null;

    $out = [];
    foreach ($rows as $r) {
        if (array_key_exists($column, $r)) {
            $out[] = $r[$column];
        }
    }
    return $out;
}

/**
 * Get next available numeric ID for a table.
 */
function pg_get_next_database_id(
    ?\PgSql\Connection $conn,
    string $table
): int {
    $sql = <<<SQL
    SELECT COALESCE(
        (
            SELECT gs.num
            FROM generate_series(
                1,
                COALESCE((SELECT MAX(t.id) FROM {$table} t), 0)
            ) AS gs(num)
            LEFT JOIN {$table} t ON t.id = gs.num
            WHERE t.id IS NULL
            ORDER BY gs.num
            LIMIT 1
        ),
        (SELECT COALESCE(MAX(t.id), 0) + 1 FROM {$table} t)
    ) AS next_available_id
    SQL;

    $rows = pg_make_db_query($conn, $sql);
    if (is_array($rows) && count($rows) > 0) {
        return (int) $rows[0]['next_available_id'];
    }
    return 1;
}

/**
 * Fetch associative array keyed by ID column.
 */
function pg_fetch_assoc_from_db(
    ?\PgSql\Connection $conn,
    string $table,
    array $columns,
    string $id_column = "id"
): array {
    $select_cols = [];

    foreach ($columns as $db_col => $alias) {
        if (is_int($db_col)) {
            $select_cols[] = $alias;
        } else {
            $select_cols[] = "$db_col AS \"$alias\"";
        }
    }

    if (!in_array($id_column, $columns, true) && !array_key_exists($id_column, $columns)) {
        $select_cols[] = $id_column;
    }

    $sql = sprintf(
        "SELECT %s FROM %s ORDER BY %s ASC",
        implode(", ", $select_cols),
        $table,
        $id_column
    );

    $rows = pg_make_db_query($conn, $sql);
    $assoc = [];

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $id = $row[$id_column] ?? count($assoc);
            unset($row[$id_column]);
            $assoc[(string)$id] = $row;
        }
    }
    return $assoc;
}

/**
 * Fetch associative data and encode to JSON.
 */
function pg_fetch_json_from_db(
    ?\PgSql\Connection $conn,
    string $table,
    array $columns,
    string $id_column = "id",
    bool $pretty = false
): string {
    $data = pg_fetch_assoc_from_db($conn, $table, $columns, $id_column);
    return json_encode(
        $data,
        ($pretty ? JSON_PRETTY_PRINT : 0)
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
    );
}

/**
 * Modify a record by ID.
 */
function pg_modify_record_by_id(
    ?\PgSql\Connection $conn,
    string $table,
    int|string $record_id,
    array $data
): bool {
    if (empty($data)) {
        throw new InvalidArgumentException("No data provided to update.");
    }

    $set_parts = [];
    $values = [];
    $i = 1;
    foreach ($data as $col => $val) {
        $set_parts[] = "{$col} = $" . $i;
        $values[] = $val;
        $i++;
    }
    $values[] = $record_id;
    $sql = sprintf(
        "UPDATE %s SET %s WHERE id = $%d",
        $table,
        implode(', ', $set_parts),
        $i
    );

    $result = pg_make_db_query($conn, $sql, $values);
    return (bool) $result;
}
