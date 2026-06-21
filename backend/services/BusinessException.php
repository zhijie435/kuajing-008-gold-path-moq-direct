<?php

class BusinessException extends Exception {

    private $errorData;
    private $retryable;
    private $rollback;

    public function __construct(
        $message,
        $code = 1,
        $errorData = null,
        $retryable = false,
        $rollback = false
    ) {
        parent::__construct($message, $code);
        $this->errorData = $errorData;
        $this->retryable = $retryable;
        $this->rollback = $rollback;
    }

    public function getErrorData() {
        return $this->errorData;
    }

    public function isRetryable() {
        return $this->retryable;
    }

    public function shouldRollback() {
        return $this->rollback;
    }

    public function toArray() {
        return array_merge([
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'retryable' => $this->retryable,
            'rollback' => $this->rollback,
        ], (array)$this->errorData);
    }
}
