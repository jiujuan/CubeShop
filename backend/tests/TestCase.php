<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 长生命周期测试进程内，AuthManager 缓存的 RequestGuard 会绑定首个请求，
     * 导致后续请求解析到陈旧用户（真实 HTTP 服务无此问题）。
     * 每次发起请求前重置 guard 缓存。
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
