(function ($) {
    function requestJson(url, options) {
        var fetchOptions = options || {};
        fetchOptions.headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest'
        }, fetchOptions.headers || {});

        return fetch(url, fetchOptions).then(function (response) {
            if (!response.ok) {
                throw new Error(response.statusText || '请求失败');
            }
            return response.json();
        });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char];
        });
    }

    $(function () {
        var config = window.AliOssForTypechoEditorConfig || {};
        var uploadPanel = $('#upload-panel');
        var fileList = $('#file-list');
        var loading = false;
        var attaching = {};
        var allFiles = [];
        var modal;
        var currentFilter = 'all';
        var currentSort = 'time_desc';

        if (!config.apiUrl || !uploadPanel.length || !fileList.length) {
            return;
        }

        uploadPanel.append(
            '<div class="oss-editor-toolbar">' +
            '<div class="oss-editor-toolbar-card">' +
            '<div class="oss-editor-toolbar-copy">' +
            '<strong>OSS 文件</strong>' +
            '<span class="oss-editor-hint">直接选择已上传到 OSS 的文件并加入当前附件列表</span>' +
            '</div>' +
            '<button type="button" class="btn btn-s primary" id="oss-editor-open">从 OSS 选择</button>' +
            '</div>' +
            '</div>'
        );

        function ensureModal() {
            if (modal) {
                return modal;
            }

            modal = $(
                '<div class="oss-editor-modal" id="oss-editor-modal">' +
                '<div class="oss-editor-modal-backdrop"></div>' +
                '<div class="oss-editor-modal-dialog">' +
                '<div class="oss-editor-modal-header">' +
                '<h3 class="oss-editor-modal-title">从 OSS 选择文件</h3>' +
                '<div class="oss-editor-modal-tools">' +
                '<input type="text" class="text w-100 oss-editor-search" placeholder="搜索文件名" />' +
                '<select class="oss-editor-select oss-editor-filter">' +
                '<option value="all">全部文件</option>' +
                '<option value="image">仅图片</option>' +
                '</select>' +
                '<select class="oss-editor-select oss-editor-sort">' +
                '<option value="time_desc">最新修改</option>' +
                '<option value="time_asc">最早修改</option>' +
                '<option value="name_asc">文件名 A-Z</option>' +
                '<option value="name_desc">文件名 Z-A</option>' +
                '<option value="size_desc">文件最大</option>' +
                '<option value="size_asc">文件最小</option>' +
                '</select>' +
                '<button type="button" class="btn btn-s oss-editor-refresh">刷新</button>' +
                '<button type="button" class="btn btn-s oss-editor-close">关闭</button>' +
                '</div>' +
                '</div>' +
                '<div class="oss-editor-modal-body">' +
                '<div class="oss-editor-loading">加载中...</div>' +
                '</div>' +
                '<div class="oss-editor-modal-footer">' +
                '<span class="oss-editor-result">共 0 个文件</span>' +
                '<span>选中文件后会加入当前文章的附件列表，可直接点击“插入”写入正文。</span>' +
                '</div>' +
                '</div>' +
                '</div>'
            );

            $('body').append(modal);

            modal.on('click', '.oss-editor-close, .oss-editor-modal-backdrop', function () {
                closeModal();
            });

            modal.on('input', '.oss-editor-search', function () {
                renderFiles();
            });

            modal.on('change', '.oss-editor-filter', function () {
                currentFilter = $(this).val() || 'all';
                renderFiles();
            });

            modal.on('change', '.oss-editor-sort', function () {
                currentSort = $(this).val() || 'time_desc';
                renderFiles();
            });

            modal.on('click', '.oss-editor-refresh', function () {
                loadFiles(true);
            });

            modal.on('click', '.oss-editor-pick', function () {
                var index = parseInt($(this).attr('data-index'), 10);
                if (!isNaN(index) && allFiles[index]) {
                    attachFile(allFiles[index], this);
                }
            });

            return modal;
        }

        function openModal() {
            ensureModal().addClass('is-open');
            if (!allFiles.length) {
                loadFiles(false);
            }
        }

        function closeModal() {
            ensureModal().removeClass('is-open');
        }

        function setBodyHtml(html) {
            ensureModal().find('.oss-editor-modal-body').html(html);
        }

        function setResultText(text) {
            ensureModal().find('.oss-editor-result').text(text);
        }

        function loadFiles(forceRefresh) {
            if (loading) {
                return;
            }

            if (!forceRefresh && allFiles.length) {
                renderFiles();
                return;
            }

            loading = true;
            setBodyHtml('<div class="oss-editor-loading">加载中...</div>');

            requestJson(config.apiUrl + '&do=editorList&all=1')
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || '文件列表加载失败');
                    }

                    allFiles = Array.isArray(data.files) ? data.files : [];
                    renderFiles();
                })
                .catch(function (error) {
                    setBodyHtml('<div class="oss-editor-error">' + escapeHtml(error.message) + '</div>');
                })
                .finally(function () {
                    loading = false;
                });
        }

        function sortFiles(files) {
            var sorted = files.slice();
            sorted.sort(function (a, b) {
                if (currentSort === 'name_asc') {
                    return String(a.name || '').localeCompare(String(b.name || ''), 'zh-CN');
                }
                if (currentSort === 'name_desc') {
                    return String(b.name || '').localeCompare(String(a.name || ''), 'zh-CN');
                }
                if (currentSort === 'size_asc') {
                    return Number(a.sizeRaw || 0) - Number(b.sizeRaw || 0);
                }
                if (currentSort === 'size_desc') {
                    return Number(b.sizeRaw || 0) - Number(a.sizeRaw || 0);
                }
                if (currentSort === 'time_asc') {
                    return Number(a.lastModified || 0) - Number(b.lastModified || 0);
                }
                return Number(b.lastModified || 0) - Number(a.lastModified || 0);
            });
            return sorted;
        }

        function renderFiles() {
            var search = String(ensureModal().find('.oss-editor-search').val() || '').toLowerCase();
            var files = allFiles.filter(function (file) {
                if (!search) {
                    return currentFilter !== 'image' || !!file.isImage;
                }
                return String(file.name || '').toLowerCase().indexOf(search) !== -1
                    && (currentFilter !== 'image' || !!file.isImage);
            });
            files = sortFiles(files);

            if (!files.length) {
                setResultText('共 0 个文件');
                setBodyHtml('<div class="oss-editor-empty">没有匹配的文件</div>');
                return;
            }

            setResultText('共 ' + files.length + ' 个文件');

            var html = '<ul class="oss-editor-list">';
            files.forEach(function (file) {
                var index = allFiles.indexOf(file);
                var preview = file.isImage
                    ? '<img class="oss-editor-thumb" src="' + escapeHtml(file.url) + '" alt="' + escapeHtml(file.name) + '" />'
                    : '<div class="oss-editor-icon">文</div>';

                html +=
                    '<li class="oss-editor-item">' +
                    '<div class="oss-editor-file">' +
                    preview +
                    '<div class="oss-editor-name">' +
                    '<strong title="' + escapeHtml(file.key) + '">' + escapeHtml(file.name) + '</strong>' +
                    '<span>' + escapeHtml(file.key) + '</span>' +
                    '</div>' +
                    '</div>' +
                    '<div class="oss-editor-meta">' + escapeHtml(file.size) + '</div>' +
                    '<div class="oss-editor-meta">' + escapeHtml(new Date(file.lastModified).toLocaleString('zh-CN')) + '</div>' +
                    '<div class="oss-editor-action"><button type="button" class="btn btn-s primary oss-editor-pick" data-index="' + index + '">使用</button></div>' +
                    '</li>';
            });
            html += '</ul>';
            setBodyHtml(html);
        }

        function updateAttachmentNumber() {
            var btn = $('#tab-files-btn');
            var balloon = $('.balloon', btn);
            var count = $('#file-list li .insert').length;

            if (count > 0) {
                if (!balloon.length) {
                    btn.html($.trim(btn.html()) + ' ');
                    balloon = $('<span class="balloon"></span>').appendTo(btn);
                }
                balloon.html(count);
            } else if (balloon.length) {
                balloon.remove();
            }
        }

        function attachInsertEvent(el) {
            $('.insert', el).off('click.ossPicker').on('click.ossPicker', function () {
                var link = $(this);
                var li = link.parents('li');
                Typecho.insertFileToEditor(link.text(), li.data('url'), li.data('image'));
                return false;
            });
        }

        function attachDeleteEvent(el) {
            var file = $('a.insert', el).text();
            $('.delete', el).off('click.ossPicker').on('click.ossPicker', function () {
                if (!confirm('确认要删除文件 ' + file + ' 吗?')) {
                    return false;
                }

                var cid = $(this).parents('li').data('cid');
                $.post(config.deleteUrl, { 'do': 'delete', 'cid': cid }, function () {
                    $(el).fadeOut(function () {
                        $(this).remove();
                        updateAttachmentNumber();
                    });
                });

                return false;
            });
        }

        function buildAttachmentItem(attachment, key) {
            return $(
                '<li data-cid="' + attachment.cid + '" data-url="' + escapeHtml(attachment.url) + '" data-image="' + (attachment.isImage ? 1 : 0) + '" data-oss-key="' + escapeHtml(key) + '">' +
                '<input type="hidden" name="attachment[]" value="' + attachment.cid + '" />' +
                '<a class="insert" target="_blank" href="###" title="点击插入文件">' + escapeHtml(attachment.title) + '</a>' +
                '<div class="info">' + escapeHtml(attachment.bytes) +
                ' <a class="file" target="_blank" href="' + escapeHtml(config.mediaBaseUrl + attachment.cid) + '" title="编辑"><i class="i-edit"></i></a>' +
                ' <a class="delete" href="###" title="删除"><i class="i-delete"></i></a></div>' +
                '</li>'
            );
        }

        function appendAttachment(attachment, key) {
            if (!attachment || !attachment.cid) {
                return;
            }

            var existingByCid = fileList.find('li').filter(function () {
                return String($(this).data('cid')) === String(attachment.cid);
            });
            if (existingByCid.length) {
                return;
            }

            var item = buildAttachmentItem(attachment, key).appendTo(fileList).effect('highlight', 1000);
            attachInsertEvent(item);
            attachDeleteEvent(item);
            updateAttachmentNumber();
        }

        function attachFile(file, button) {
            if (!file || !file.key || attaching[file.key]) {
                return;
            }

            attaching[file.key] = true;
            var buttonEl = $(button);
            var originalText = buttonEl.text();
            buttonEl.prop('disabled', true).text('处理中...');

            var payload = new URLSearchParams();
            payload.set('key', file.key);
            payload.set('cid', $('input[name=cid]').val() || '0');
            payload.set('size', String(file.sizeRaw || 0));

            requestJson(config.apiUrl + '&do=attachExisting', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload.toString()
            }).then(function (data) {
                if (!data.success || !data.attachment) {
                    throw new Error(data.message || '添加失败');
                }

                appendAttachment(data.attachment, file.key);
                closeModal();
            }).catch(function (error) {
                alert('添加 OSS 文件失败: ' + error.message);
            }).finally(function () {
                delete attaching[file.key];
                buttonEl.prop('disabled', false).text(originalText);
            });
        }

        $('#oss-editor-open').on('click', function () {
            openModal();
            return false;
        });
    });
})(jQuery);
