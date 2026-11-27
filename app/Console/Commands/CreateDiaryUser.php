<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateDiaryUser extends Command
{
    protected $signature = 'diary:user
                            {--email= : 登录邮箱}
                            {--name= : 显示名称}
                            {--password= : 登录密码（不建议在命令行明文传入）}
                            {--force : 覆盖已有用户的密码}';

    protected $description = '创建或重置唯一日记用户的登录凭据';

    public function handle(): int
    {
        $user = User::query()->first();

        if ($user && ! $this->option('force')) {
            $this->error('已有用户。如需重置密码，请加 --force 参数。');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: ($user?->email) ?: $this->ask('登录邮箱');
        $name = $this->option('name') ?: ($user?->name) ?: $this->ask('显示名称', '我');
        $password = $this->option('password') ?: $this->secret('登录密码（至少 8 位）');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($user) {
            $user->forceFill([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make($password),
            ])->save();
        } else {
            User::create([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make($password),
            ]);
        }

        $this->info($user ? '密码已更新。' : '用户已创建。');

        return self::SUCCESS;
    }
}
