<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * 统一 API 响应封装
 *
 * 响应结构（见架构文档 8.1）：
 * {
 *   "code": 0,          // 0=成功，非 0=业务/系统错误码
 *   "message": "ok",
 *   "data": { ... }
 * }
 */
trait ApiResponse
{
    /**
     * 成功响应
     */
    protected function success(mixed $data = null, string $message = 'ok', int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    /**
     * 业务失败响应
     */
    protected function fail(string $message = 'fail', int $code = 1, mixed $data = null, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $httpStatus);
    }

    /**
     * 分页数据响应（Laravel Paginator）
     */
    protected function paginated(mixed $paginator, string $message = 'ok'): JsonResponse
    {
        return $this->success([
            'list' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ], $message);
    }
}
