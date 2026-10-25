<?php
declare(strict_types=1);

require_once __DIR__ . '/Model.php';

final class Order extends Model
{
    protected $table = 'orders';

    public function create(string $number, string $org, string $agent): int
    {
        $sql = 'INSERT INTO ' . $this->table . ' (order_number, organization, agent) VALUES ('
            . $this->db->escape($number) . ', '
            . $this->db->escape($org)    . ', '
            . $this->db->escape($agent)  . ')';

        try {
            $this->db->query($sql);
        } catch (RuntimeException $e) {
            if ($this->db->errno() === 1062) {
                throw new RuntimeException('DUPLICATE', 1062);
            }
            throw $e;
        }

        return $this->db->lastInsertId();
    }

    public function find(int $id)
    {
        $sql = 'SELECT * FROM ' . $this->table . ' WHERE id = ' . (int)$id . ' LIMIT 1';
        return $this->db->query($sql)->row_object();
    }

    public function findByNumber(string $number)
    {
        $sql = 'SELECT * FROM ' . $this->table
            . ' WHERE order_number = ' . $this->db->escape($number)
            . ' LIMIT 1';

        return $this->db->query($sql)->row_object();
    }

    public function exists(string $number): bool
    {
        return $this->findByNumber($number) !== null;
    }

    public function all(): array
    {
        $sql = 'SELECT * FROM ' . $this->table . ' ORDER BY id DESC';
        return $this->db->query($sql)->result_object();
    }

    public function setMsInfo(int $id, string $msId, ?string $status): void
    {
        $statusSql = $status === null ? 'NULL' : $this->db->escape($status);

        $sql = 'UPDATE ' . $this->table
            . ' SET ms_id  = ' . $this->db->escape($msId)
            . ', status = ' . $statusSql
            . ' WHERE id  = ' . (int)$id;

        $this->db->query($sql);
    }

    public function updateStatus(int $id, string $status): void
    {
        $sql = 'UPDATE ' . $this->table
            . ' SET status = ' . $this->db->escape($status)
            . ' WHERE id = ' . (int)$id;

        $this->db->query($sql);
    }

    public function countAll(): int
    {
        $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table . ' WHERE ms_id IS NOT NULL AND ms_id != \'\'';
        $row = $this->db->query($sql)->row_object();
        return $row ? (int)$row->cnt : 0;
    }

    public function getPaginated(int $limit, int $offset): array
    {
        $limit  = (int)$limit;
        $offset = (int)$offset;

        $sql = 'SELECT * FROM ' . $this->table
            . ' WHERE ms_id IS NOT NULL AND ms_id != \'\''
            . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->db->query($sql)->result_object();
    }
}