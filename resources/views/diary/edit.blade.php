@extends('layouts.app')

@section('title', '写日记 · '.config('app.name'))

@section('content')
<div class="editor-head">
    <div>
        <h1>{{ $entry?->exists ? '编辑日记' : '写日记' }}</h1>
        <p class="muted save-state" id="save-state">输入内容会自动保存。</p>
    </div>
    <div class="editor-date">
        <label for="editor-date-picker">日期</label>
        <input id="editor-date-picker" type="date" value="{{ $date }}" min="1900-01-01">
    </div>
</div>

<div class="toolbar" aria-label="Markdown 工具栏">
    <button type="button" data-md="bold">粗体</button>
    <button type="button" data-md="italic">斜体</button>
    <button type="button" data-md="heading">标题</button>
    <button type="button" data-md="list">列表</button>
    <button type="button" data-md="quote">引用</button>
    <button type="button" data-md="code">代码</button>
    <button type="button" data-md="link">链接</button>
    <button type="button" id="preview-toggle">预览</button>
</div>

<form id="editor-form" method="POST" action="{{ route('diary.store', $date) }}">
    @csrf
    <div class="editor-paper">
        <input type="text" name="title" id="entry-title" class="title-input"
               placeholder="标题（可留空）" maxlength="200"
               value="{{ old('title', $entry?->title ?? '') }}">

        <div class="field-row">
            <label for="entry-tags">标签</label>
            <input type="text" name="tags" id="entry-tags" class="tags-input"
                   placeholder="用逗号分隔，例如：生活, 旅行"
                   value="{{ old('tags', $entry?->tags->pluck('name')->implode(', ') ?? '') }}">
        </div>

        <div class="field-row field-row-options">
            <label for="entry-mood">心情</label>
            <select id="entry-mood" name="mood" class="select-input">
                <option value="">未记录</option>
                @foreach ($moods as $key => $label)
                    <option value="{{ $key }}" @selected(old('mood', $entry?->mood) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="entry-weather">天气</label>
            <select id="entry-weather" name="weather" class="select-input">
                <option value="">未记录</option>
                @foreach ($weathers as $key => $label)
                    <option value="{{ $key }}" @selected(old('weather', $entry?->weather) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="editor-grid" id="editor-grid">
            <textarea name="body" id="entry-body" class="body-input"
                      placeholder="用 Markdown 写点什么……">{{ old('body', $entry?->body ?? '') }}</textarea>
            <div id="preview-pane" class="preview-pane prose" hidden></div>
        </div>

        @if ($errors->any())
            <div class="errors">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
    </div>
</form>

<div class="attachments-panel">
    <div class="attachments-head">
        <h2>图片附件</h2>
        <label class="upload-button">
            上传图片
            <input type="file" id="image-input" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
        </label>
    </div>
    <p class="muted">单张最多 8 MB，一次最多 6 张。图片只存在你的私有目录。</p>
    <div id="upload-state" class="muted"></div>
    <div id="attachment-list" class="attachment-list">
        @foreach ($entry?->attachments ?? [] as $attachment)
            <div class="attachment-item" data-id="{{ $attachment->id }}">
                <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener">
                    <img src="{{ route('attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}">
                </a>
                <div class="attachment-actions">
                    <button type="button" class="insert-image" data-markdown="![{{ $attachment->original_name }}]({{ route('attachments.show', $attachment) }})">插入</button>
                    <button type="button" class="delete-image danger">移除</button>
                </div>
            </div>
        @endforeach
    </div>
</div>

<div class="editor-actions">
    <button type="submit" form="editor-form" class="primary">保存</button>
    <span class="shortcut">Ctrl/⌘ + S</span>
    @if ($entry?->exists)
        <form method="POST" action="{{ route('diary.destroy', $date) }}" class="delete-form"
              onsubmit="return confirm('确定删除这一天的日记？');">
            @csrf
            @method('DELETE')
            <button type="submit" class="danger">删除</button>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const form = document.getElementById('editor-form');
    const title = document.getElementById('entry-title');
    const tags = document.getElementById('entry-tags');
    const mood = document.getElementById('entry-mood');
    const weather = document.getElementById('entry-weather');
    const body = document.getElementById('entry-body');
    const state = document.getElementById('save-state');
    const previewPane = document.getElementById('preview-pane');
    const previewToggle = document.getElementById('preview-toggle');
    const picker = document.getElementById('editor-date-picker');
    const previewUrl = '{{ route('diary.preview') }}';
    const uploadUrl = '{{ route('attachments.store', $date) }}';
    const imageInput = document.getElementById('image-input');
    const uploadState = document.getElementById('upload-state');
    const attachmentList = document.getElementById('attachment-list');
    let timer = null;
    let previewTimer = null;

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error('request failed');
        return response.json();
    }

    function scheduleSave() {
        state.textContent = '正在保存…';
        clearTimeout(timer);
        timer = setTimeout(async () => {
            try {
                const data = await postJson(form.action, {
                    title: title.value,
                    tags: tags.value,
                    mood: mood.value,
                    weather: weather.value,
                    body: body.value,
                    autosave: true,
                });
                state.textContent = '已自动保存 ' + data.saved_at;
            } catch (e) {
                state.textContent = '自动保存失败，请手动保存。';
            }
        }, 1200);
    }

    function schedulePreview() {
        if (previewPane.hidden) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            try {
                const data = await postJson(previewUrl, { body: body.value });
                previewPane.innerHTML = data.html;
            } catch (e) {
                previewPane.textContent = '预览失败。';
            }
        }, 250);
    }

    [title, tags, body].forEach((el) => el.addEventListener('input', () => {
        scheduleSave();
        schedulePreview();
    }));

    [mood, weather].forEach((el) => el.addEventListener('change', scheduleSave));

    previewToggle.addEventListener('click', async () => {
        const showing = !previewPane.hidden;
        previewPane.hidden = showing;
        document.getElementById('editor-grid').classList.toggle('with-preview', !showing);
        previewToggle.textContent = showing ? '预览' : '返回编辑';
        if (!showing) {
            try {
                const data = await postJson(previewUrl, { body: body.value });
                previewPane.innerHTML = data.html;
            } catch (e) {
                previewPane.textContent = '预览失败。';
            }
        }
    });

    document.querySelectorAll('[data-md]').forEach((button) => {
        button.addEventListener('click', () => {
            const start = body.selectionStart;
            const end = body.selectionEnd;
            const selected = body.value.slice(start, end);
            const rules = {
                bold: ['**', '**', '粗体'],
                italic: ['*', '*', '斜体'],
                heading: ['## ', '', '标题'],
                list: ['- ', '', '列表项'],
                quote: ['> ', '', '引用'],
                code: ['`', '`', '代码'],
                link: ['[', '](https://)', '链接文字'],
            };
            const [before, after, placeholder] = rules[button.dataset.md];
            const text = selected || placeholder;
            body.value = body.value.slice(0, start) + before + text + after + body.value.slice(end);
            body.focus();
            body.selectionStart = start + before.length;
            body.selectionEnd = start + before.length + text.length;
            body.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    function insertAtCursor(text) {
        const start = body.selectionStart;
        const end = body.selectionEnd;
        body.value = body.value.slice(0, start) + text + body.value.slice(end);
        body.focus();
        body.selectionStart = body.selectionEnd = start + text.length;
        body.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function addAttachment(attachment) {
        const item = document.createElement('div');
        item.className = 'attachment-item';
        item.dataset.id = attachment.id;
        item.innerHTML = '<a href="' + attachment.url + '" target="_blank" rel="noopener"><img src="' + attachment.url + '" alt="图片"></a>'
            + '<div class="attachment-actions">'
            + '<button type="button" class="insert-image">插入</button>'
            + '<button type="button" class="delete-image danger">移除</button>'
            + '</div>';
        item.querySelector('.insert-image').dataset.markdown = attachment.markdown;
        attachmentList.appendChild(item);
    }

    imageInput.addEventListener('change', async () => {
        if (!imageInput.files.length) return;
        const data = new FormData();
        Array.from(imageInput.files).forEach((file) => data.append('images[]', file));
        uploadState.textContent = '正在上传…';
        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: data,
            });
            if (!response.ok) throw new Error('upload failed');
            const result = await response.json();
            result.attachments.forEach(addAttachment);
            uploadState.textContent = '已上传 ' + result.attachments.length + ' 张图片。';
        } catch (e) {
            uploadState.textContent = '上传失败，请检查文件类型和大小。';
        }
        imageInput.value = '';
    });

    attachmentList.addEventListener('click', async (event) => {
        const item = event.target.closest('.attachment-item');
        if (!item) return;

        if (event.target.classList.contains('insert-image')) {
            insertAtCursor('\n\n' + event.target.dataset.markdown + '\n');
            return;
        }

        if (event.target.classList.contains('delete-image')) {
            if (!confirm('确定移除这张图片？')) return;
            try {
                const response = await fetch('/attachments/' + item.dataset.id, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                });
                if (!response.ok) throw new Error('delete failed');
                item.remove();
            } catch (e) {
                uploadState.textContent = '移除失败。';
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            clearTimeout(timer);
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', () => clearTimeout(timer));

    picker.addEventListener('change', () => {
        if (picker.value) window.location.href = '{{ route('diary.create') }}?date=' + picker.value;
    });
})();
</script>
@endpush
