<?php
class ProductController {
    private $db;
    private $table = 'moq_products';

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function index() {
        $page = max(1, (int)get_query_param('page', 1));
        $pageSize = max(1, (int)get_query_param('page_size', 20));
        $keyword = trim((string)get_query_param('keyword', ''));
        $moqWarning = (int)get_query_param('moq_warning', 0);
        $offset = ($page - 1) * $pageSize;

        $where = ['status = 1'];
        $params = [];

        if ($keyword) {
            $where[] = '(sku LIKE ? OR name LIKE ? OR category LIKE ?)';
            $like = "%{$keyword}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($moqWarning === 1) {
            $where[] = 'stock < moq';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM `{$this->table}` WHERE {$whereSql}";
        $total = (int)($this->db->fetchOne($countSql, $params)['total'] ?? 0);

        $sql = "SELECT * FROM `{$this->table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$pageSize}";
        $list = $this->db->fetchAll($sql, $params);

        json_success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function show($id) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('产品不存在', 404);
        json_success($row);
    }

    public function store() {
        $data = get_input_data();
        $sku = trim($data['sku'] ?? '');
        $name = trim($data['name'] ?? '');

        if (!$sku || !$name) {
            json_error('SKU和产品名称不能为空');
        }

        $exists = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE sku = ?", [$sku]);
        if ($exists) {
            json_error('SKU已存在');
        }

        $insert = [
            'sku' => $sku,
            'name' => $name,
            'category' => trim($data['category'] ?? ''),
            'moq' => max(1, (int)($data['moq'] ?? 1)),
            'unit' => trim($data['unit'] ?? '件'),
            'price' => round((float)($data['price'] ?? 0), 2),
            'stock' => (int)($data['stock'] ?? 0),
            'weight' => round((float)($data['weight'] ?? 0), 2),
            'remark' => trim($data['remark'] ?? ''),
        ];

        $id = $this->db->insert($this->table, $insert);
        json_success(['id' => $id], '创建成功');
    }

    public function update($id) {
        $row = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('产品不存在', 404);

        $data = get_input_data();

        $update = [];
        foreach (['sku', 'name', 'category', 'moq', 'unit', 'price', 'stock', 'weight', 'remark'] as $field) {
            if (isset($data[$field])) {
                $update[$field] = $data[$field];
            }
        }

        if (isset($update['moq'])) $update['moq'] = max(1, (int)$update['moq']);
        if (isset($update['price'])) $update['price'] = round((float)$update['price'], 2);
        if (isset($update['stock'])) $update['stock'] = (int)$update['stock'];
        if (isset($update['weight'])) $update['weight'] = round((float)$update['weight'], 2);

        if (isset($update['sku'])) {
            $exists = $this->db->fetchOne(
                "SELECT id FROM `{$this->table}` WHERE sku = ? AND id != ?",
                [$update['sku'], $id]
            );
            if ($exists) json_error('SKU已存在');
        }

        if ($update) {
            $this->db->update($this->table, $update, 'id = ?', [$id]);
        }

        json_success(null, '更新成功');
    }

    public function destroy($id) {
        $row = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('产品不存在', 404);

        $this->db->update($this->table, ['status' => 0], 'id = ?', [$id]);
        json_success(null, '删除成功');
    }
}
