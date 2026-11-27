@if ($errors->any())
    <div class="errors">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ $action }}" class="form-stack">
    @csrf
    <label>
        <span>密码</span>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <label>
        <span>验证码 / 恢复码</span>
        <input type="text" name="code" autocomplete="one-time-code" autocapitalize="characters" required>
    </label>
    <button type="submit" class="primary">{{ $button }}</button>
</form>
