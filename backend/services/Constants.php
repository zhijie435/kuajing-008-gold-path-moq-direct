<?php

class Constants {

    const ORDER_STATUS_PENDING = 0;
    const ORDER_STATUS_MOQ_PASSED = 10;
    const ORDER_STATUS_REVIEWED = 15;
    const ORDER_STATUS_LABEL_GENERATED = 20;
    const ORDER_STATUS_SHIPPED = 30;
    const ORDER_STATUS_CANCELLED = 40;

    const MOQ_CHECK_PENDING = 0;
    const MOQ_CHECK_PASSED = 1;
    const MOQ_CHECK_FAILED = 2;

    const SHIPPING_STATUS_PENDING = 0;
    const SHIPPING_STATUS_PRINTED = 1;
    const SHIPPING_STATUS_SHIPPED = 2;
    const SHIPPING_STATUS_VOIDED = 9;

    const ERROR_CODE_SUCCESS = 0;
    const ERROR_CODE_PARAM_ERROR = 1;
    const ERROR_CODE_NOT_FOUND = 404;
    const ERROR_CODE_SERVER_ERROR = 500;

    const ERROR_CODE_ORDER_NOT_FOUND = 1001;
    const ERROR_CODE_ORDER_CANCELLED = 1002;
    const ERROR_CODE_ORDER_SHIPPED = 1003;
    const ERROR_CODE_ORDER_NOT_REVIEWED = 1004;
    const ERROR_CODE_ORDER_ALREADY_REVIEWED = 1005;
    const ERROR_CODE_ORDER_STATUS_INVALID = 1006;
    const ERROR_CODE_ORDER_NO_ITEMS = 1007;

    const ERROR_CODE_MOQ_NOT_CHECKED = 2001;
    const ERROR_CODE_MOQ_CHECK_FAILED = 2002;
    const ERROR_CODE_MOQ_ITEM_FAILED = 2003;

    const ERROR_CODE_LABEL_EXISTS = 3001;
    const ERROR_CODE_LABEL_NOT_FOUND = 3002;
    const ERROR_CODE_LABEL_ALREADY_SHIPPED = 3003;
    const ERROR_CODE_LABEL_GENERATE_FAILED = 3004;

    const ERROR_CODE_PERMISSION_DENIED = 4001;

    public static function getOrderStatusText($status) {
        $map = [
            self::ORDER_STATUS_PENDING => '待审核',
            self::ORDER_STATUS_MOQ_PASSED => 'MOQ已通过',
            self::ORDER_STATUS_REVIEWED => '已审核',
            self::ORDER_STATUS_LABEL_GENERATED => '已生成面单',
            self::ORDER_STATUS_SHIPPED => '已发货',
            self::ORDER_STATUS_CANCELLED => '已取消',
        ];
        return $map[$status] ?? '未知';
    }

    public static function getShippingStatusText($status) {
        $map = [
            self::SHIPPING_STATUS_PENDING => '待打印',
            self::SHIPPING_STATUS_PRINTED => '已打印',
            self::SHIPPING_STATUS_SHIPPED => '已发货',
            self::SHIPPING_STATUS_VOIDED => '已作废',
        ];
        return $map[$status] ?? '未知';
    }

    public static function getMoqCheckText($status) {
        $map = [
            self::MOQ_CHECK_PENDING => '未校验',
            self::MOQ_CHECK_PASSED => '已通过',
            self::MOQ_CHECK_FAILED => '未通过',
        ];
        return $map[$status] ?? '未知';
    }
}
