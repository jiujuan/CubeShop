<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 买家工厂（表 users）
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => 'buyer'.Str::lower(Str::random(8)),
            'password' => 'Test@1234',          // password 走 hashed 转换自动加密
            'nickname' => fake()->name(),
            'email' => null,
            'phone' => null,
            'avatar' => null,
            'status' => 1,
        ];
    }

    /** 禁用状态 */
    public function disabled(): static
    {
        return $this->state(fn () => ['status' => 0]);
    }
}
