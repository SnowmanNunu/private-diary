@extends('layouts.app')

@section('title', '设置 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>设置</h1>
        <p class="muted">备份存储与定时任务都在这里配置，保存后立即生效，无需修改 .env。</p>
    </div>
</div>

@if ($errors->any())
    <div class="errors">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('settings.update') }}">
    @csrf
    @method('PUT')

    <section class="setting-card">
        <h2>异地存储</h2>
        <p class="muted">支持 AWS S3、腾讯云 COS、Cloudflare R2、MinIO 等 S3 兼容服务。凭证加密保存在数据库，页面不会回显。</p>
        <div class="form-grid">
            <div class="form-field">
                <label for="backup_disk">备份磁盘</label>
                <select id="backup_disk" name="backup_disk">
                    <option value="local" @selected(old('backup_disk', $values['backup_disk']) === 'local')>本地磁盘</option>
                    <option value="s3" @selected(old('backup_disk', $values['backup_disk']) === 's3')>S3 兼容对象存储</option>
                </select>
            </div>
            <div class="form-field">
                <label for="backup_path">桶内目录</label>
                <input id="backup_path" name="backup_path" value="{{ old('backup_path', $values['backup_path']) }}" maxlength="100">
            </div>
            <div class="form-field">
                <label for="backup_keep">保留份数</label>
                <input id="backup_keep" name="backup_keep" type="number" min="1" max="365" value="{{ old('backup_keep', $values['backup_keep']) }}">
            </div>
            <div class="form-field">
                <label for="s3_endpoint">端点 Endpoint</label>
                <input id="s3_endpoint" name="s3_endpoint" value="{{ old('s3_endpoint', $values['s3_endpoint']) }}" placeholder="https://cos.ap-guangzhou.myqcloud.com">
            </div>
            <div class="form-field">
                <label for="s3_region">区域 Region</label>
                <input id="s3_region" name="s3_region" value="{{ old('s3_region', $values['s3_region']) }}" placeholder="ap-guangzhou，R2 用 auto">
            </div>
            <div class="form-field">
                <label for="s3_bucket">桶名 Bucket</label>
                <input id="s3_bucket" name="s3_bucket" value="{{ old('s3_bucket', $values['s3_bucket']) }}">
            </div>
            <div class="form-field">
                <label for="s3_key">Access Key ID</label>
                <input id="s3_key" name="s3_key" value="{{ old('s3_key', $values['s3_key']) }}" autocomplete="off">
            </div>
            <div class="form-field">
                <label for="s3_secret">Secret Access Key</label>
                <input id="s3_secret" name="s3_secret" type="password" autocomplete="new-password"
                       placeholder="{{ $configured['secret'] ? '已保存，留空表示不修改' : '首次填写' }}">
            </div>
            <div class="form-field">
                <label for="s3_url">自定义访问域名（可选）</label>
                <input id="s3_url" name="s3_url" value="{{ old('s3_url', $values['s3_url']) }}">
            </div>
        </div>
        <label class="checkbox-row">
            <input type="checkbox" name="s3_path_style" value="1" @checked(old('s3_path_style', $values['s3_path_style']))>
            使用路径风格端点（MinIO 等自建服务通常需要）
        </label>
        <div class="setting-inline">
            <button type="button" id="test-storage" class="link-button">测试存储连接</button>
            <span id="storage-test-state" class="muted">保存配置后再测试。</span>
        </div>
    </section>

    <section class="setting-card">
        <h2>备份加密口令</h2>
        <p class="muted">
            备份包在此口令基础上用 AES-256-GCM 加密。
            @if ($configured['passphrase'])
                <span class="badge-ok">已配置</span>
            @else
                <span class="badge-warn">未配置</span>
            @endif
        </p>
        <div class="form-grid">
            <div class="form-field">
                <label for="backup_passphrase">口令</label>
                <input id="backup_passphrase" name="backup_passphrase" type="password" autocomplete="new-password"
                       placeholder="{{ $configured['passphrase'] ? '已保存，留空表示不修改' : '请填写至少 16 位' }}">
            </div>
        </div>
        <p class="muted">口令只加密保存在数据库，不会显示在页面上。请离线另存一份；丢失后备份无法恢复。</p>
    </section>

    <section class="setting-card">
        <h2>定时任务</h2>
        <p class="muted">时间按下方时区计算。服务器仍需配置系统 cron 来触发调度。</p>
        <label class="checkbox-row">
            <input type="checkbox" name="schedule_enabled" value="1" @checked(old('schedule_enabled', $values['schedule_enabled']))>
            启用每日自动备份
        </label>
        <label class="checkbox-row">
            <input type="checkbox" name="verify_enabled" value="1" @checked(old('verify_enabled', $values['verify_enabled']))>
            启用每季度恢复演练（只读取备份，不改生产数据）
        </label>
        <div class="form-grid">
            <div class="form-field">
                <label for="backup_time">每日备份时间</label>
                <input id="backup_time" name="backup_time" type="time" value="{{ old('backup_time', $values['backup_time']) }}">
            </div>
            <div class="form-field">
                <label for="verify_day">季度演练日（每月 1-28）</label>
                <input id="verify_day" name="verify_day" type="number" min="1" max="28" value="{{ old('verify_day', $values['verify_day']) }}">
            </div>
            <div class="form-field">
                <label for="verify_time">季度演练时间</label>
                <input id="verify_time" name="verify_time" type="time" value="{{ old('verify_time', $values['verify_time']) }}">
            </div>
            <div class="form-field">
                <label for="timezone">时区</label>
                <input id="timezone" name="timezone" value="{{ old('timezone', $values['timezone']) }}" placeholder="Asia/Shanghai">
            </div>
        </div>
        <p class="muted">季度演练在 1、4、7、10 月的设定日期执行。cron 示例：</p>
        <pre class="cron-line">* * * * * cd /path/to/private-diary &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
    </section>

    <div class="setting-actions">
        <label for="current_password">当前登录密码（保存敏感设置需要验证）</label>
        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
        <button type="submit" class="primary">保存设置</button>
    </div>
</form>

<section class="setting-card">
    <h2>备份与演练记录</h2>
    <p class="muted">最近 5 次恢复演练的审计结果。</p>
    @if ($audits->isEmpty())
        <p class="muted">还没有演练记录。可先手动创建一份备份再演练。</p>
    @else
        <table class="audit-table">
            <thead>
                <tr><th>时间</th><th>结果</th><th>备份文件</th><th>耗时</th></tr>
            </thead>
            <tbody>
                @foreach ($audits as $audit)
                    <tr>
                        <td>{{ $audit->checked_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $audit->status }}</td>
                        <td>{{ $audit->backup_path ?: '—' }}</td>
                        <td>{{ $audit->duration_ms }} ms</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <form method="POST" action="{{ route('settings.backup-now') }}" class="setting-inline">
        @csrf
        <button type="submit" class="primary">立即备份一次</button>
        <span class="muted">使用上面已保存的配置。</span>
    </form>
</section>
@endsection

@push('scripts')
<script>
(function () {
    const button = document.getElementById('test-storage');
    const state = document.getElementById('storage-test-state');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    button.addEventListener('click', async () => {
        state.textContent = '正在测试…';
        state.className = 'muted';
        try {
            const response = await fetch('{{ route('settings.test-storage') }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            });
            const data = await response.json();
            state.textContent = data.message || '测试失败。';
            state.className = data.ok ? 'test-ok' : 'test-fail';
        } catch (error) {
            state.textContent = '测试失败，请检查网络或服务配置。';
            state.className = 'test-fail';
        }
    });
})();
</script>
@endpush
