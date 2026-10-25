<?php
declare(strict_types=1);

/**
 * Результат запроса — как в CI: row_object() / result_object()
 */
class DBResult
{
    /** @var mysqli_result|null */
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    /** Одна строка как объект (или null) */
    public function row_object()
    {
        if (!$this->result instanceof mysqli_result) {
            return null;
        }
        $row = $this->result->fetch_object();
        return $row === null ? null : $row;
    }

    /** Массив строк-объектов */
    public function result_object(): array
    {
        if (!$this->result instanceof mysqli_result) {
            return [];
        }
        $rows = [];
        while ($row = $this->result->fetch_object()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function num_rows(): int
    {
        return $this->result instanceof mysqli_result ? $this->result->num_rows : 0;
    }
}

/**
 * Обёртка над mysqli в стиле $this->db->query(...)
 */
class DB
{
    /** @var mysqli */
    private $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function query(string $sql): DBResult
    {
        $res = $this->mysqli->query($sql);
        if ($res === false) {
            throw new RuntimeException('SQL error: ' . $this->mysqli->error . ' in: ' . $sql);
        }
        return new DBResult($res);
    }

    /** Безопасное экранирование (включая кавычки) */
    public function escape(string $value): string
    {
        return "'" . $this->mysqli->real_escape_string($value) . "'";
    }

    public function lastInsertId(): int
    {
        return (int)$this->mysqli->insert_id;
    }

    public function affectedRows(): int
    {
        return (int)$this->mysqli->affected_rows;
    }

    public function errno(): int
    {
        return (int)$this->mysqli->errno;
    }

    public function error(): string
    {
        return $this->mysqli->error;
    }
}

abstract class Model
{
    /** @var DB */
    protected $db;

    /** @var string */
    protected $table = '';

    public function __construct(mysqli $mysqli)
    {
        $this->db = new DB($mysqli);
    }
}