<?php
namespace Omps\Database;

/**
 * Small parameterized query builder. Identifiers are validated, values are always bound.
 *
 *   DB::table('users')->where('status', 'active')->whereIn('id', [1, 2])->orderBy('id')->get();
 */
class QueryBuilder
{
    /** @var string */
    private $table;
    /** @var string */
    private $alias = '';
    /** @var array */
    private $columns = array('*');
    /** @var array */
    private $joins = array();
    /** @var array list of [sql, params] */
    private $wheres = array();
    /** @var array */
    private $orders = array();
    /** @var int|null */
    private $limit = null;
    /** @var int|null */
    private $offset = null;
    /** @var bool */
    private $withDeleted = false;
    /** @var bool */
    private $softDeletes = false;

    public function __construct($table)
    {
        $parts = preg_split('/\s+(?:as\s+)?/i', trim((string) $table));
        $this->table = self::ident($parts[0]);
        $this->alias = isset($parts[1]) ? self::ident($parts[1]) : '';
    }

    /** Validate a column or table identifier (letters, digits, underscore, optional table prefix). */
    public static function ident($name)
    {
        $name = (string) $name;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_*][A-Za-z0-9_]*)?$/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }
        return $name;
    }

    private static function quoteIdent($name)
    {
        $name = self::ident($name);
        $out = array();
        foreach (explode('.', $name) as $p) {
            $out[] = $p === '*' ? '*' : '`' . $p . '`';
        }
        return implode('.', $out);
    }

    private function ref()
    {
        return $this->alias !== '' ? $this->alias : $this->table;
    }

    /** Exclude rows with deleted_at set (call for tables that use soft delete). */
    public function active()
    {
        $this->softDeletes = true;
        return $this;
    }

    public function withDeleted()
    {
        $this->withDeleted = true;
        return $this;
    }

    /** @param array $cols column names, optionally 'col as alias' */
    public function select(array $cols)
    {
        $this->columns = array();
        foreach ($cols as $c) {
            $this->columns[] = self::selectExpr((string) $c);
        }
        return $this;
    }

    /** Raw select expression (no user input). */
    public function selectRaw($expr)
    {
        $this->columns = array((string) $expr);
        return $this;
    }

    private static function selectExpr($c)
    {
        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $c, $m)) {
            return self::quoteIdent(trim($m[1])) . ' AS `' . $m[2] . '`';
        }
        return self::quoteIdent($c);
    }

    public function join($table, $first, $op, $second, $type = 'INNER')
    {
        $parts = preg_split('/\s+(?:as\s+)?/i', trim((string) $table));
        $t = self::quoteIdent($parts[0]) . (isset($parts[1]) ? ' ' . self::quoteIdent($parts[1]) : '');
        $op = self::op($op);
        $this->joins[] = $type . ' JOIN ' . $t . ' ON ' . self::quoteIdent($first) . ' ' . $op . ' ' . self::quoteIdent($second);
        return $this;
    }

    public function leftJoin($table, $first, $op, $second)
    {
        return $this->join($table, $first, $op, $second, 'LEFT');
    }

    private static function op($op)
    {
        $op = strtoupper(trim((string) $op));
        $ok = array('=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE');
        if (!in_array($op, $ok, true)) {
            throw new \InvalidArgumentException('Invalid operator: ' . $op);
        }
        return $op;
    }

    /** where('col', value) or where('col', '>=', value) */
    public function where($col, $op = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $op;
            $op = '=';
        }
        if ($value === null) {
            return $op === '=' ? $this->whereNull($col) : $this->whereNotNull($col);
        }
        $this->wheres[] = array(self::quoteIdent($col) . ' ' . self::op($op) . ' ?', array($value));
        return $this;
    }

    public function whereNull($col)
    {
        $this->wheres[] = array(self::quoteIdent($col) . ' IS NULL', array());
        return $this;
    }

    public function whereNotNull($col)
    {
        $this->wheres[] = array(self::quoteIdent($col) . ' IS NOT NULL', array());
        return $this;
    }

    public function whereIn($col, array $values)
    {
        if (!$values) {
            $this->wheres[] = array('1 = 0', array());
            return $this;
        }
        $this->wheres[] = array(self::quoteIdent($col) . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
        return $this;
    }

    public function whereNotIn($col, array $values)
    {
        if (!$values) {
            return $this;
        }
        $this->wheres[] = array(self::quoteIdent($col) . ' NOT IN (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
        return $this;
    }

    /** LIKE search on several columns joined by OR; the term is escaped. */
    public function whereLikeAny(array $cols, $term)
    {
        $term = (string) $term;
        if ($term === '' || !$cols) {
            return $this;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $sql = array();
        $params = array();
        foreach ($cols as $c) {
            // compared as utf8mb4: ascii columns (codes, numbers) would refuse a Chinese search term
            $sql[] = 'CONVERT(' . self::quoteIdent($c) . ' USING utf8mb4) LIKE ?';
            $params[] = $like;
        }
        $this->wheres[] = array('(' . implode(' OR ', $sql) . ')', $params);
        return $this;
    }

    /** Like whereLikeAny, but rows whose $idCol is in $orIds also match (e.g. found in custom fields). */
    public function whereLikeAnyOrIn(array $cols, $term, $idCol, array $orIds)
    {
        $term = (string) $term;
        if ($term === '' || !$cols) {
            return $this;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $sql = array();
        $params = array();
        foreach ($cols as $c) {
            // compared as utf8mb4: ascii columns (codes, numbers) would refuse a Chinese search term
            $sql[] = 'CONVERT(' . self::quoteIdent($c) . ' USING utf8mb4) LIKE ?';
            $params[] = $like;
        }
        if ($orIds) {
            $sql[] = self::quoteIdent($idCol) . ' IN (' . implode(',', array_fill(0, count($orIds), '?')) . ')';
            foreach ($orIds as $id) {
                $params[] = (int) $id;
            }
        }
        $this->wheres[] = array('(' . implode(' OR ', $sql) . ')', $params);
        return $this;
    }

    /** Raw condition with bound parameters. Never put user input into $sql. */
    public function whereRaw($sql, array $params = array())
    {
        $this->wheres[] = array('(' . $sql . ')', array_values($params));
        return $this;
    }

    public function orderBy($col, $dir = 'ASC')
    {
        $dir = strtoupper((string) $dir) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = self::quoteIdent($col) . ' ' . $dir;
        return $this;
    }

    public function limit($n)
    {
        $this->limit = max(0, (int) $n);
        return $this;
    }

    public function offset($n)
    {
        $this->offset = max(0, (int) $n);
        return $this;
    }

    private function whereSql(array &$params)
    {
        $w = $this->wheres;
        if ($this->softDeletes && !$this->withDeleted) {
            $w[] = array(self::quoteIdent($this->ref() . '.deleted_at') . ' IS NULL', array());
        }
        if (!$w) {
            return '';
        }
        $sql = array();
        foreach ($w as $item) {
            $sql[] = $item[0];
            foreach ($item[1] as $p) {
                $params[] = $p;
            }
        }
        return ' WHERE ' . implode(' AND ', $sql);
    }

    private function fromSql()
    {
        return self::quoteIdent($this->table) . ($this->alias !== '' ? ' ' . self::quoteIdent($this->alias) : '');
    }

    public function toSql(array &$params)
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->fromSql();
        if ($this->joins) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        $sql .= $this->whereSql($params);
        if ($this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }
        return $sql;
    }

    public function get()
    {
        $params = array();
        $sql = $this->toSql($params);
        return DB::select($sql, $params);
    }

    public function first()
    {
        $this->limit = 1;
        $rows = $this->get();
        return $rows ? $rows[0] : null;
    }

    public function find($id)
    {
        return $this->where($this->ref() . '.id', (int) $id)->first();
    }

    public function count()
    {
        $params = array();
        $sql = 'SELECT COUNT(*) FROM ' . $this->fromSql() . ($this->joins ? ' ' . implode(' ', $this->joins) : '') . $this->whereSql($params);
        return (int) DB::value($sql, $params);
    }

    public function exists()
    {
        return $this->count() > 0;
    }

    /** @return array values of one column */
    public function pluck($col)
    {
        $this->columns = array(self::quoteIdent($col));
        $out = array();
        foreach ($this->get() as $r) {
            $out[] = reset($r);
        }
        return $out;
    }

    /**
     * Paginated result: ['rows' => [...], 'total' => n, 'page' => p, 'size' => s]
     */
    public function paginate($page, $size)
    {
        $page = max(1, (int) $page);
        $size = min(200, max(1, (int) $size));
        $total = (clone $this)->count();
        $rows = $this->limit($size)->offset(($page - 1) * $size)->get();
        return array('rows' => $rows, 'total' => $total, 'page' => $page, 'size' => $size);
    }

    public function insert(array $data)
    {
        if (!$data) {
            throw new \InvalidArgumentException('Empty insert');
        }
        $cols = array();
        foreach (array_keys($data) as $c) {
            $cols[] = self::quoteIdent($c);
        }
        $sql = 'INSERT INTO ' . self::quoteIdent($this->table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')';
        DB::statement($sql, array_values($data));
        return DB::lastInsertId();
    }

    public function update(array $data)
    {
        if (!$data) {
            return 0;
        }
        if (!$this->wheres) {
            throw new \LogicException('Refusing UPDATE without WHERE');
        }
        $set = array();
        $params = array();
        foreach ($data as $c => $v) {
            $set[] = self::quoteIdent($c) . ' = ?';
            $params[] = $v;
        }
        $sql = 'UPDATE ' . self::quoteIdent($this->table) . ' SET ' . implode(', ', $set) . $this->whereSql($params);
        return DB::statement($sql, $params);
    }

    /** Increment a column by n. */
    public function increment($col, $n = 1, array $extra = array())
    {
        if (!$this->wheres) {
            throw new \LogicException('Refusing UPDATE without WHERE');
        }
        $set = array(self::quoteIdent($col) . ' = ' . self::quoteIdent($col) . ' + ' . (int) $n);
        $params = array();
        foreach ($extra as $c => $v) {
            $set[] = self::quoteIdent($c) . ' = ?';
            $params[] = $v;
        }
        $sql = 'UPDATE ' . self::quoteIdent($this->table) . ' SET ' . implode(', ', $set) . $this->whereSql($params);
        return DB::statement($sql, $params);
    }

    public function delete()
    {
        if (!$this->wheres) {
            throw new \LogicException('Refusing DELETE without WHERE');
        }
        $params = array();
        $sql = 'DELETE FROM ' . self::quoteIdent($this->table) . $this->whereSql($params);
        return DB::statement($sql, $params);
    }
}
