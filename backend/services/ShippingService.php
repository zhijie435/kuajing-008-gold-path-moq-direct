<?php

class ShippingService {

    private $db;
    private $moqService;
    private $permissionService;
    private $table = 'moq_shipping_labels';
    private $itemTable = 'moq_shipping_items';
    private $orderTable = 'moq_orders';
    private $orderItemTable = 'moq_order_items';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->moqService = new MoqService();
        $this->permissionService = new PermissionService();
    }

    public function getShippingLabelList($params) {
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
            $where[] = '(sl.shipping_no LIKE ? OR sl.order_no LIKE ? OR sl.receiver_name LIKE ? OR sl.receiver_phone LIKE ?)';
            $like = "%{$keyword}%";
            $paramsList[] = $like;
            $paramsList[] = $like;
            $paramsList[] = $like;
            $paramsList[] = $like;
        }

        if ($status !== null && $status !== '') {
            $where[] = 'sl.status = ?';
            $paramsList[] = (int)$status;
        }

        if ($startDate) {
            $where[] = 'DATE(sl.created_at) >= ?';
            $paramsList[] = $startDate;
        }
        if ($endDate) {
            $where[] = 'DATE(sl.created_at) <= ?';
            $paramsList[] = $endDate;
        }

        list($permWhere, $permParams) = $this->permissionService->applyShippingPermissionFilter();
        if ($permWhere !== '1=1') {
            $where[] = $permWhere;
            $paramsList = array_merge($paramsList, $permParams);
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM `{$this->table}` sl WHERE {$whereSql}";
        $total = (int)($this->db->fetchOne($countSql, $paramsList)['total'] ?? 0);

        $sql = "SELECT sl.* FROM `{$this->table}` sl WHERE {$whereSql} ORDER BY sl.id DESC LIMIT {$offset}, {$pageSize}";
        $list = $this->db->fetchAll($sql, $paramsList);

        $list = array_map(function($label) {
            return $this->formatLabel($label);
        }, $list);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function generateShippingLabel($orderId) {
        $this->permissionService->checkPermission(PermissionService::PERM_GENERATE_LABEL);

        $orderId = (int)$orderId;
        $order = $this->getOrderById($orderId);

        $this->validateOrderForLabelGeneration($order);
        $this->moqService->ensureMoqChecked($order);
        $this->ensureOrderReviewed($order);

        $orderItems = $this->getOrderItems($orderId);
        $this->ensureHasOrderItems($orderId, $orderItems);
        $this->moqService->ensureAllItemsPassed($orderId, $orderItems);

        return $this->createLabelWithTransaction($order, $orderItems);
    }

    public function batchGenerateShippingLabels($orderIds) {
        $this->permissionService->checkPermission(PermissionService::PERM_GENERATE_LABEL);

        if (!is_array($orderIds) || count($orderIds) === 0) {
            throw new BusinessException(
                '请选择订单',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }

        $successCount = 0;
        $failCount = 0;
        $results = [];
        $failedOrders = [];
        $retryableOrders = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            try {
                $result = $this->generateShippingLabel($oid);
                $successCount++;
                $results[] = $result;
            } catch (BusinessException $e) {
                $failCount++;
                $errorData = $e->getErrorData() ?? [];
                $failedOrders[] = array_merge([
                    'order_id' => $errorData['order_id'] ?? $oid,
                    'order_no' => $errorData['order_no'] ?? null,
                    'message' => $e->getMessage(),
                    'rollback' => $e->shouldRollback(),
                    'retryable' => $e->isRetryable(),
                ], $errorData);
                if ($e->isRetryable()) {
                    $retryableOrders[] = $errorData['order_id'] ?? $oid;
                }
            } catch (Exception $e) {
                $failCount++;
                $failedOrders[] = [
                    'order_id' => $oid,
                    'order_no' => null,
                    'message' => '系统错误：' . $e->getMessage(),
                    'rollback' => false,
                    'retryable' => true,
                ];
                $retryableOrders[] = $oid;
            }
        }

        return [
            'success' => $successCount,
            'failed' => $failCount,
            'labels' => $results,
            'failed_orders' => $failedOrders,
            'retryable_order_ids' => $retryableOrders,
        ];
    }

    public function printLabel($shippingId) {
        $this->permissionService->checkPermission(PermissionService::PERM_PRINT_LABEL);

        $shippingId = (int)$shippingId;
        $label = $this->getShippingLabelById($shippingId);
        if (!$label) {
            throw new BusinessException(
                '面单不存在',
                Constants::ERROR_CODE_LABEL_NOT_FOUND,
                ['shipping_id' => $shippingId],
                false,
                false
            );
        }

        $now = date('Y-m-d H:i:s');
        $newStatus = (int)$label['status'] === Constants::SHIPPING_STATUS_PENDING
            ? Constants::SHIPPING_STATUS_PRINTED
            : (int)$label['status'];

        $this->db->update($this->table, [
            'status' => $newStatus,
            'printed_at' => $label['printed_at'] ?? $now,
        ], 'id = ?', [$shippingId]);

        return [
            'shipping_id' => $shippingId,
            'printed' => true,
        ];
    }

    public function batchPrintLabels($shippingIds) {
        $this->permissionService->checkPermission(PermissionService::PERM_PRINT_LABEL);

        if (!is_array($shippingIds) || count($shippingIds) === 0) {
            throw new BusinessException(
                '请选择面单',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($shippingIds as $sid) {
            $sid = (int)$sid;
            $label = $this->db->fetchOne(
                "SELECT id, status, printed_at FROM `{$this->table}` WHERE id = ?",
                [$sid]
            );
            if (!$label) continue;

            $newStatus = (int)$label['status'] === Constants::SHIPPING_STATUS_PENDING
                ? Constants::SHIPPING_STATUS_PRINTED
                : (int)$label['status'];

            $this->db->update($this->table, [
                'status' => $newStatus,
                'printed_at' => $label['printed_at'] ?? $now,
            ], 'id = ?', [$sid]);
            $count++;
        }

        return ['count' => $count];
    }

    public function markShipped($shippingId) {
        $this->permissionService->checkPermission(PermissionService::PERM_MARK_SHIPPED);

        $shippingId = (int)$shippingId;
        $label = $this->getShippingLabelById($shippingId);
        if (!$label) {
            throw new BusinessException(
                '面单不存在',
                Constants::ERROR_CODE_LABEL_NOT_FOUND,
                ['shipping_id' => $shippingId],
                false,
                false
            );
        }

        if ((int)$label['status'] >= Constants::SHIPPING_STATUS_SHIPPED) {
            throw new BusinessException(
                '面单已标记为发货状态',
                Constants::ERROR_CODE_LABEL_ALREADY_SHIPPED,
                ['shipping_id' => $shippingId],
                false,
                false
            );
        }

        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $this->db->update($this->table, [
                'status' => Constants::SHIPPING_STATUS_SHIPPED,
                'shipped_at' => $now,
            ], 'id = ?', [$shippingId]);

            if (!empty($label['order_id'])) {
                $this->db->update($this->orderTable, [
                    'status' => Constants::ORDER_STATUS_SHIPPED,
                ], 'id = ?', [$label['order_id']]);
            }

            $this->db->commit();

            return [
                'shipping_id' => $shippingId,
                'shipped' => true,
                'shipped_at' => $now,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                '标记发货失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_LABEL_ALREADY_SHIPPED,
                ['shipping_id' => $shippingId],
                true,
                true
            );
        }
    }

    public function batchMarkShipped($shippingIds) {
        $this->permissionService->checkPermission(PermissionService::PERM_MARK_SHIPPED);

        if (!is_array($shippingIds) || count($shippingIds) === 0) {
            throw new BusinessException(
                '请选择面单',
                Constants::ERROR_CODE_PARAM_ERROR,
                null,
                false,
                false
            );
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;
        $updatedOrderIds = [];

        $this->db->beginTransaction();
        try {
            foreach ($shippingIds as $sid) {
                $sid = (int)$sid;
                $label = $this->db->fetchOne(
                    "SELECT id, status, order_id FROM `{$this->table}` WHERE id = ?",
                    [$sid]
                );
                if (!$label || (int)$label['status'] >= Constants::SHIPPING_STATUS_SHIPPED) {
                    continue;
                }

                $this->db->update($this->table, [
                    'status' => Constants::SHIPPING_STATUS_SHIPPED,
                    'shipped_at' => $now,
                ], 'id = ?', [$sid]);

                if (!empty($label['order_id'])) {
                    $this->db->update($this->orderTable, [
                        'status' => Constants::ORDER_STATUS_SHIPPED,
                    ], 'id = ?', [$label['order_id']]);
                    $updatedOrderIds[] = $label['order_id'];
                }

                $count++;
            }

            $this->db->commit();

            return [
                'count' => $count,
                'shipped_at' => $now,
                'updated_order_ids' => $updatedOrderIds,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                '批量标记发货失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_LABEL_ALREADY_SHIPPED,
                null,
                true,
                true
            );
        }
    }

    private function validateOrderForLabelGeneration($order) {
        $orderId = (int)($order['id'] ?? 0);
        $orderNo = $order['order_no'] ?? null;
        $orderStatus = (int)($order['status'] ?? 0);
        $orderStatusText = Constants::getOrderStatusText($orderStatus);

        if ($orderStatus === Constants::ORDER_STATUS_CANCELLED) {
            throw new BusinessException(
                '订单已取消，无法生成面单',
                Constants::ERROR_CODE_ORDER_CANCELLED,
                ['order_id' => $orderId, 'order_no' => $orderNo],
                false,
                false
            );
        }

        if ($orderStatus === Constants::ORDER_STATUS_SHIPPED) {
            throw new BusinessException(
                '订单已发货，无需重复生成面单',
                Constants::ERROR_CODE_ORDER_SHIPPED,
                ['order_id' => $orderId, 'order_no' => $orderNo],
                false,
                false
            );
        }

        if ($orderStatus >= Constants::ORDER_STATUS_LABEL_GENERATED) {
            throw new BusinessException(
                '订单已生成面单',
                Constants::ERROR_CODE_ORDER_STATUS_INVALID,
                ['order_id' => $orderId, 'order_no' => $orderNo],
                false,
                false
            );
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM `{$this->table}` WHERE order_id = ?",
            [$orderId]
        );
        if ($existing) {
            throw new BusinessException(
                '面单已存在，无需重复生成',
                Constants::ERROR_CODE_LABEL_EXISTS,
                ['order_id' => $orderId, 'order_no' => $orderNo],
                false,
                false
            );
        }

        return true;
    }

    private function ensureOrderReviewed($order) {
        $orderStatus = (int)($order['status'] ?? 0);
        if ($orderStatus < Constants::ORDER_STATUS_REVIEWED) {
            $orderStatusText = Constants::getOrderStatusText($orderStatus);
            throw new BusinessException(
                '订单未审核通过（当前状态：' . $orderStatusText . '），请先完成审核',
                Constants::ERROR_CODE_ORDER_NOT_REVIEWED,
                [
                    'order_id' => $order['id'],
                    'order_no' => $order['order_no'],
                    'current_status' => $orderStatus,
                ],
                false,
                false
            );
        }
        return true;
    }

    private function ensureHasOrderItems($orderId, $orderItems) {
        if (count($orderItems) === 0) {
            throw new BusinessException(
                '订单没有商品明细，无法生成面单',
                Constants::ERROR_CODE_ORDER_NO_ITEMS,
                ['order_id' => $orderId],
                false,
                false
            );
        }
        return true;
    }

    private function createLabelWithTransaction($order, $orderItems) {
        $orderId = (int)$order['id'];
        $orderNo = $order['order_no'];

        $this->db->beginTransaction();
        try {
            $shippingNo = generate_shipping_no();

            $shippingId = $this->db->insert($this->table, [
                'shipping_no' => $shippingNo,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'carrier' => CARRIER_DEFAULT,
                'receiver_name' => $order['receiver_name'],
                'receiver_phone' => $order['receiver_phone'],
                'receiver_address' => $order['receiver_address'],
                'total_weight' => round((float)$order['total_weight'], 2),
                'status' => Constants::SHIPPING_STATUS_PENDING,
            ]);

            foreach ($orderItems as $oi) {
                $this->db->insert($this->itemTable, [
                    'shipping_id' => $shippingId,
                    'sku' => $oi['sku'],
                    'name' => $oi['name'],
                    'quantity' => (int)$oi['quantity'],
                    'unit' => $oi['unit'],
                ]);
            }

            $this->db->update($this->orderTable, [
                'status' => Constants::ORDER_STATUS_LABEL_GENERATED,
                'shipping_id' => $shippingId,
            ], 'id = ?', [$orderId]);

            $this->db->commit();
            return [
                'success' => true,
                'shipping_id' => $shippingId,
                'shipping_no' => $shippingNo,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                '面单生成失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_LABEL_GENERATE_FAILED,
                [
                    'order_id' => $orderId,
                    'order_no' => $orderNo,
                ],
                true,
                true
            );
        }
    }

    private function getOrderById($orderId) {
        $order = $this->db->fetchOne(
            "SELECT * FROM `{$this->orderTable}` WHERE id = ?",
            [$orderId]
        );
        if (!$order) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_ORDER_NOT_FOUND,
                ['order_id' => $orderId],
                false,
                false
            );
        }
        return $order;
    }

    private function getOrderItems($orderId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->orderItemTable}` WHERE order_id = ?",
            [$orderId]
        );
    }

    private function getShippingLabelById($shippingId) {
        return $this->db->fetchOne(
            "SELECT * FROM `{$this->table}` WHERE id = ?",
            [$shippingId]
        );
    }

    private function loadShippingItems($shippingId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->itemTable}` WHERE shipping_id = ? ORDER BY id ASC",
            [$shippingId]
        );
    }

    private function formatLabel($label) {
        $label['items'] = $this->loadShippingItems($label['id']);
        $label['status_text'] = Constants::getShippingStatusText($label['status']);
        return $label;
    }
}
