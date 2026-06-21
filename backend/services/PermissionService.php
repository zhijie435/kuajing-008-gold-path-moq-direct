<?php

class PermissionService {

    const ROLE_ADMIN = 'admin';
    const ROLE_OPERATOR = 'operator';
    const ROLE_VIEWER = 'viewer';

    const PERM_VIEW_ORDERS = 'view_orders';
    const PERM_VIEW_ALL_ORDERS = 'view_all_orders';
    const PERM_CREATE_ORDER = 'create_order';
    const PERM_REVIEW_ORDER = 'review_order';
    const PERM_CHECK_MOQ = 'check_moq';
    const PERM_GENERATE_LABEL = 'generate_label';
    const PERM_PRINT_LABEL = 'print_label';
    const PERM_MARK_SHIPPED = 'mark_shipped';
    const PERM_MANAGE_PRODUCTS = 'manage_products';

    private static $rolePermissions = [
        self::ROLE_ADMIN => [
            self::PERM_VIEW_ORDERS,
            self::PERM_VIEW_ALL_ORDERS,
            self::PERM_CREATE_ORDER,
            self::PERM_REVIEW_ORDER,
            self::PERM_CHECK_MOQ,
            self::PERM_GENERATE_LABEL,
            self::PERM_PRINT_LABEL,
            self::PERM_MARK_SHIPPED,
            self::PERM_MANAGE_PRODUCTS,
        ],
        self::ROLE_OPERATOR => [
            self::PERM_VIEW_ORDERS,
            self::PERM_VIEW_ALL_ORDERS,
            self::PERM_CREATE_ORDER,
            self::PERM_CHECK_MOQ,
            self::PERM_GENERATE_LABEL,
            self::PERM_PRINT_LABEL,
            self::PERM_MARK_SHIPPED,
        ],
        self::ROLE_VIEWER => [
            self::PERM_VIEW_ORDERS,
        ],
    ];

    private $db;
    private $currentUser;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->currentUser = $this->getCurrentUser();
    }

    private function getCurrentUser() {
        $userId = $this->getUserIdFromToken();
        if ($userId > 0) {
            $user = $this->db->fetchOne(
                "SELECT id, username, role, department_id FROM moq_users WHERE id = ? AND status = 1",
                [$userId]
            );
            if ($user) {
                return $user;
            }
        }

        return [
            'id' => 0,
            'username' => 'guest',
            'role' => self::ROLE_VIEWER,
            'department_id' => 0,
        ];
    }

    private function getUserIdFromToken() {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);

        if (empty($token)) {
            return 0;
        }

        return (int)$this->decodeToken($token);
    }

    private function decodeToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload = json_decode(base64_decode($parts[1]), true);
        return $payload['uid'] ?? 0;
    }

    public function getCurrentUserId() {
        return (int)($this->currentUser['id'] ?? 0);
    }

    public function getCurrentUserRole() {
        return $this->currentUser['role'] ?? self::ROLE_VIEWER;
    }

    public function getCurrentUserDepartmentId() {
        return (int)($this->currentUser['department_id'] ?? 0);
    }

    public function hasPermission($permission) {
        $role = $this->getCurrentUserRole();
        $permissions = self::$rolePermissions[$role] ?? [];
        return in_array($permission, $permissions, true);
    }

    public function checkPermission($permission) {
        if (!$this->hasPermission($permission)) {
            throw new BusinessException(
                '权限不足，无法执行该操作',
                Constants::ERROR_CODE_PERMISSION_DENIED,
                [
                    'permission' => $permission,
                    'user_id' => $this->getCurrentUserId(),
                    'role' => $this->getCurrentUserRole(),
                ],
                false,
                false
            );
        }
        return true;
    }

    public function canViewAllOrders() {
        return $this->hasPermission(self::PERM_VIEW_ALL_ORDERS);
    }

    public function applyDataPermissionFilter($tableAlias = '') {
        $alias = $tableAlias ? "`{$tableAlias}`." : '';

        if ($this->canViewAllOrders()) {
            return ['1=1', []];
        }

        $userId = $this->getCurrentUserId();
        $departmentId = $this->getCurrentUserDepartmentId();

        if ($departmentId > 0) {
            return [
                "{$alias}department_id = ? OR {$alias}created_by = ?",
                [$departmentId, $userId]
            ];
        }

        return [
            "{$alias}created_by = ?",
            [$userId]
        ];
    }

    public function applyOrderPermissionFilter() {
        return $this->applyDataPermissionFilter('o');
    }

    public function applyShippingPermissionFilter() {
        return $this->applyDataPermissionFilter('sl');
    }
}
