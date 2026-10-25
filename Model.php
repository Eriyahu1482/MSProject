<?php
declare(strict_types=1);

class DBResult
{
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function row_object()
    {
        if (!$this->result instanceof mysqli_result) {
            return null;
        }
        $row = $this->result->fetch_object();
        return $row === null ? null : $row;
    }

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

class DB
{
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
    protected $db;
    protected $table = '';
    public function __construct(mysqli $mysqli)
    {
        $this->db = new DB($mysqli);
    }
}