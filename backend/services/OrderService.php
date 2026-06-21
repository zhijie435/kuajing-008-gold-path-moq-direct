<?php

class OrderService {

    private $db;
    private $moqService;
    private $permissionService;
    private $table = 'moq_orders';
    private $itemTable = 'moq_order_items';
    private $productTable = 'moq_products';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->moqService = new MoqService();
        $this->permissionService = new PermissionService();
    }

    public function getOrderList($params) {
        $this->permissionService->checkPermission(PermissionService::PERM_VIEW_ORDERS);

        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? 20));
        $keyword = trim((string)($params['keyword'] ?? ''));
        $status = $params['status'] ?? null;
        $startDate = trim((string)($params['start_date'] ?? ''));
        $endDate = trim((string)($params['end_date'] ?? ''));
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $paramsList = [];

        if ($keyword) {
            $where[] = '(o.order_no LIKE ? OR o.receiver_name LIKE ? OR o.receiver_phone LIKE ?)';
            $like = "%{$keyword}%";
            $paramsList[] = $like;
            $paramsList[] = $like;
            $paramsList[] = $like;
        }

        if ($status !== null && $status !== '') {
            $where[] = 'o.status = ?';
            $paramsList[] = (int)$status;
        }

        if ($startDate) {
            $where[] = 'DATE(o.created_at) >= ?';
            $paramsList[] = $startDate;
        }
        if ($endDate) {
            $where[] = 'DATE(o.created_at) <= ?';
            $paramsList[] = $endDate;
        }

        list($permWhere, $permParams) = $this->permissionService->applyOrderPermissionFilter();
        if ($permWhere !== '1=1') {
            $where[] = $permWhere;
            $paramsList = array_merge($paramsList, $permParams);
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM `{$this->table}` o WHERE {$whereSql}";
        $total = (int)($this->db->fetchOne($countSql, $paramsList)['total'] ?? 0);

        $sql = "SELECT o.* FROM `{$this->table}` o WHERE {$whereSql} ORDER BY o.id DESC LIMIT {$offset}, {$pageSize}";
        $list = $this->db->fetchAll($sql, $paramsList);

        $list = array_map(function($order) {
            return $this->formatOrder($order);
        }, $list);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getOrderDetail($id) {
        $this->permissionService->checkPermission(PermissionService::PERM_VIEW_ORDERS);

        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_NOT_FOUND,
                ['order_id' => $id],
                false,
                false
            );
        }
        return $this->formatOrder($row);
    }

    public function createOrder($data) {
        $this->permissionService->checkPermission(PermissionService::PERM_CREATE_ORDER);

        $receiverName = trim($data['receiver_name'] ?? '');
        $receiverPhone = trim($data['receiver_phone'] ?? '');
        $receiverAddress = trim($data['receiver_address'] ?? '');
        $items = $data['items'] ?? [];

        if (!$receiverName || !$receiverPhone || !$receiverAddress) {
            throw new BusinessException(
                '收件信息不完整',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }
        if (!is_array($items) || count($items) === 0) {
            throw new BusinessException(
                '请添加商品',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }

        $moqResult = $this->moqService->validateItems($items);
        if (!$moqResult['passed']) {
            throw new BusinessException(
                '订单包含未达到起订量的商品：' . $moqResult['message'],
                Constants::ERROR_CODE_MOQ_CHECK_FAILED,
                ['failed_items' => $moqResult['failed_list']],
                false,
                false
            );
        }

        $this->db->beginTransaction();
        try {
            $orderNo = generate_order_no();
            $totalQty = 0;
            $totalAmount = 0;
            $totalWeight = 0;

            $orderId = $this->db->insert($this->table, [
                'order_no' => $orderNo,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'receiver_address' => $receiverAddress,
                'remark' => trim($data['remark'] ?? ''),
                'total_quantity' => 0,
                'total_amount' => 0,
                'total_weight' => 0,
                'status' => Constants::ORDER_STATUS_PENDING,
                'moq_checked' => Constants::MOQ_CHECK_PASSED,
                'moq_fail_reason' => '',
                'created_by' => $this->permissionService->getCurrentUserId(),
                'department_id' => $this->permissionService->getCurrentUserDepartmentId(),
            ]);

            $productMap = $this->loadProductMap($items);

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = max(1, (int)($item['quantity'] ?? 1));
                $moq = max(1, (int)($item['moq'] ?? 1));
                $price = round((float)($item['price'] ?? 0), 2);
                $weight = round((float)($item['weight'] ?? 0), 2);
                $sku = trim($item['sku'] ?? '');
                $name = trim($item['name'] ?? '');
                $unit = trim($item['unit'] ?? '件');

                if (isset($productMap[$productId])) {
                    $p = $productMap[$productId];
                    if (!$sku) $sku = $p['sku'];
                    if (!$name) $name = $p['name'];
                    if ($moq <= 0) $moq = (int)$p['moq'];
                    if ($unit === '') $unit = $p['unit'];
                    if ($price <= 0) $price = round((float)$p['price'], 2);
                    if ($weight <= 0) $weight = round((float)$p['weight'], 2);
                }

                $moqPassed = $quantity >= $moq ? 1 : 0;

                $this->db->insert($this->itemTable, [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'sku' => $sku,
                    'name' => $name,
                    'moq' => $moq,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'price' => $price,
                    'weight' => $weight,
                    'moq_passed' => $moqPassed,
                ]);

                $totalQty += $quantity;
                $totalAmount += $quantity * $price;
                $totalWeight += $quantity * $weight;
            }

            $this->db->update($this->table, [
                'total_quantity' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'total_weight' => round($totalWeight, 2),
            ], 'id = ?', [$orderId]);

            $this->db->commit();
            return ['id' => $orderId, 'order_no' => $orderNo];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                '订单创建失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_SERVER_ERROR,
                null,
                true,
                true
            );
        }
    }

    public function reviewOrder($id, $data) {
        $this->permissionService->checkPermission(PermissionService::PERM_REVIEW_ORDER);

        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_NOT_FOUND,
                ['order_id' => $id],
                false,
                false
            );
        }

        $this->moqService->checkOrderReviewReady($row);

        $approved = (bool)($data['approved'] ?? true);
        $reviewRemark = trim($data['review_remark'] ?? '');

        $this->db->beginTransaction();
        try {
            if ($approved) {
                $this->db->update($this->table, [
                    'status' => Constants::ORDER_STATUS_REVIEWED,
                    'moq_checked' => Constants::MOQ_CHECK_PASSED,
                    'moq_fail_reason' => '',
                    'review_remark' => $reviewRemark,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
            } else {
                $this->db->update($this->table, [
                    'status' => Constants::ORDER_STATUS_MOQ_PASSED,
                    'review_remark' => $reviewRemark,
                ], 'id = ?', [$id]);
            }

            $this->db->commit();
            $order = $this->getOrderById($id);
            return [
                'order' => $order,
                'approved' => $approved,
                'message' => $approved ? '审核通过' : '审核驳回',
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                '审核操作失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_SERVER_ERROR,
                ['order_id' => $id],
                true,
                true
            );
        }
    }

    public function batchReviewOrders($orderIds, $data) {
        $this->permissionService->checkPermission(PermissionService::PERM_REVIEW_ORDER);

        if (!is_array($orderIds) || count($orderIds) === 0) {
            throw new BusinessException(
                '请选择订单',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }

        $approved = (bool)($data['approved'] ?? true);
        $reviewRemark = trim($data['review_remark'] ?? '');

        $successCount = 0;
        $failCount = 0;
        $updatedOrders = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            try {
                $result = $this->reviewOrder($oid, [
                    'approved' => $approved,
                    'review_remark' => $reviewRemark,
                ]);
                $successCount++;
                $updatedOrders[] = $result['order'];
            } catch (BusinessException $e) {
                $failCount++;
                continue;
            }
        }

        return [
            'success' => $successCount,
            'failed' => $failCount,
            'orders' => $updatedOrders,
        ];
    }

    public function updateOrder($id, $data) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_NOT_FOUND,
                ['order_id' => $id],
                false,
                false
            );
        }

        $allowed = ['receiver_name', 'receiver_phone', 'receiver_address', 'remark', 'status'];
        $update = [];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update[$field] = $data[$field];
            }
        }

        if ($update) {
            $this->db->update($this->table, $update, 'id = ?', [$id]);
        }
        return true;
    }

    public function deleteOrder($id) {
        $row = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_NOT_FOUND,
                ['order_id' => $id],
                false,
                false
            );
        }

        $this->db->update($this->table, ['status' => Constants::ORDER_STATUS_CANCELLED], 'id = ?', [$id]);
        return true;
    }

    private function loadProductMap($items) {
        $productIds = [];
        foreach ($items as $item) {
            if (!empty($item['product_id'])) {
                $productIds[] = (int)$item['product_id'];
            }
        }

        $productMap = [];
        if ($productIds) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $products = $this->db->fetchAll(
                "SELECT * FROM {$this->productTable} WHERE id IN ({$placeholders})",
                $productIds
            );
            foreach ($products as $p) {
                $productMap[$p['id']] = $p;
            }
        }
        return $productMap;
    }

    private function loadOrderItems($orderId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->itemTable}` WHERE order_id = ? ORDER BY id ASC",
            [$orderId]
        );
    }

    private function getOrderById($orderId) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$orderId]);
        if (!$row) return null;
        return $this->formatOrder($row);
    }

    private function formatOrder($order) {
        $order['status_text'] = Constants::getOrderStatusText($order['status']);
        $order['moq_check_text'] = Constants::getMoqCheckText($order['moq_checked']);
        $order['items'] = $this->loadOrderItems($order['id']);
        return $order;
    }
}
