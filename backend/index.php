<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

function json_response($code, $message = '', $data = null) {
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success($data = null, $message = 'success') {
    json_response(0, $message, $data);
}

function json_error($message = 'error', $code = 1, $data = null) {
    json_response($code, $message, $data);
}

function get_input_data() {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_REQUEST;
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $_REQUEST;
    return array_merge($_REQUEST, $data ?: []);
}

function get_query_param($key, $default = null) {
    return $_GET[$key] ?? $default;
}

function generate_order_no() {
    return ORDER_PREFIX . date('YmdHis') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generate_shipping_no() {
    return SHIPPING_PREFIX . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

function get_order_status_text($status) {
    $map = [
        0 => '待审核',
        10 => 'MOQ已通过',
        15 => '已审核',
        20 => '已生成面单',
        30 => '已发货',
        40 => '已取消',
    ];
    return $map[$status] ?? '未知';
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace(API_BASE_PATH, '', $uri);
$uri = trim($uri, '/');
$segments = explode('/', $uri);
$method = $_SERVER['REQUEST_METHOD'];

$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$action = $segments[2] ?? null;

try {
    switch ($resource) {
        case 'products':
            require_once __DIR__ . '/controllers/ProductController.php';
            $controller = new ProductController();
            if ($method === 'GET' && $id === null) $controller->index();
            elseif ($method === 'GET' && $id !== null) $controller->show($id);
            elseif ($method === 'POST' && $id === null) $controller->store();
            elseif ($method === 'PUT' && $id !== null) $controller->update($id);
            elseif ($method === 'DELETE' && $id !== null) $controller->destroy($id);
            else json_error('路由不存在', 404);
            break;

        case 'orders':
            require_once __DIR__ . '/controllers/OrderController.php';
            $controller = new OrderController();
            if ($method === 'GET' && $id === null) $controller->index();
            elseif ($method === 'GET' && $id !== null) $controller->show($id);
            elseif ($method === 'POST' && $id === null) $controller->store();
            elseif ($method === 'PUT' && $id !== null) $controller->update($id);
            elseif ($method === 'DELETE' && $id !== null) $controller->destroy($id);
            elseif ($method === 'POST' && $id === 'check-moq') $controller->checkMoq();
            elseif ($method === 'POST' && $id === 'batch-check-moq') $controller->batchCheckMoq();
            elseif ($method === 'POST' && $id === 'review' && $action !== null) $controller->review($action);
            elseif ($method === 'POST' && $id === 'batch-review') $controller->batchReview();
            else json_error('路由不存在', 404);
            break;

        case 'shipping':
            require_once __DIR__ . '/controllers/ShippingController.php';
            $controller = new ShippingController();
            if ($method === 'GET' && $id === null) $controller->index();
            elseif ($method === 'POST' && $id === null) $controller->store();
            elseif ($method === 'POST' && $id === 'generate' && $action !== null) $controller->generate($action);
            elseif ($method === 'POST' && $id === 'batch-generate') $controller->batchGenerate();
            elseif ($method === 'POST' && $id === 'print' && $action !== null) $controller->printLabel($action);
            elseif ($method === 'POST' && $id === 'batch-print') $controller->batchPrint();
            elseif ($method === 'POST' && $id === 'ship' && $action !== null) $controller->markShipped($action);
            elseif ($method === 'POST' && $id === 'batch-ship') $controller->batchMarkShipped();
            else json_error('路由不存在', 404);
            break;

        case 'dashboard':
            require_once __DIR__ . '/controllers/DashboardController.php';
            $controller = new DashboardController();
            if ($method === 'GET' && $id === 'stats') $controller->stats();
            else json_error('路由不存在', 404);
            break;

        case '':
        default:
            json_response(0, 'MOQ直发打单系统 API 已启动');
    }
} catch (Exception $e) {
    if (APP_DEBUG) {
        json_error($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
    } else {
        json_error('服务器内部错误', 500);
    }
}
