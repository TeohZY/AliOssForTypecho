var currentPage = 1;
var isLoading = false;
var allFiles = [];
var totalFiles = 0;
var pageSize = 20;
var sortBy = 'time';
var sortOrder = 'desc';
var deletingKeys = {};

function requestJson(url) {
    return fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(function(response) {
        if (!response.ok) {
            throw new Error('请求失败: ' + response.status);
        }
        return response.json();
    });
}

function showError(message) {
    document.getElementById('ossFilesLoading').style.display = 'none';
    document.getElementById('ossFilesList').style.display = 'none';
    document.getElementById('ossFilesEmpty').style.display = 'none';
    document.getElementById('ossFilesError').style.display = 'block';
    document.getElementById('errorMessage').textContent = message || '发生未知错误';
}

function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    return new Promise(function(resolve, reject) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            if (document.execCommand('copy')) {
                resolve();
            } else {
                reject(new Error('浏览器不支持复制'));
            }
        } catch (error) {
            reject(error);
        } finally {
            document.body.removeChild(textarea);
        }
    });
}

function openFileUrl(url) {
    if (!url) {
        alert('文件地址无效');
        return;
    }

    window.open(url);
}

function getViewButtonHtml(file, index, className) {
    if (file.isImage) {
        return '<button class="' + className + '" onclick="previewImage(' + index + ')">查看</button>';
    }

    return '<button class="' + className + '" onclick="openFileUrl(\'' + file.url + '\')">查看</button>';
}

function getThumbHtml(file, imageClass, fallbackHtml) {
    if (file.isImage) {
        return '<a class="pswp-image" href="' + file.url + '" data-pswp-width="1200" data-pswp-height="1200"><img src="' + file.url + '" class="' + imageClass + '" /></a>';
    }

    return fallbackHtml || '';
}

function getManagedBadgeHtml(file) {
    if (!file.managedByTypecho) {
        return '';
    }

    return '<small class="oss-managed-badge">Typecho</small>';
}

function getDeleteAttachmentButtonHtml(file) {
    if (file.managedByTypecho) {
        return '<button class="btn btn-s oss-btn-warn" onclick="deleteAttachment(\'' + file.key + '\')">删除</button>';
    }

    return '<button class="btn btn-s oss-btn-warn" onclick="deleteFile(\'' + file.key + '\')">删除</button>';
}

function bindCopyButtons() {
    document.querySelectorAll('.copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            copyText(this.getAttribute('data-url'))
                .then(function() {
                    alert('链接已复制');
                })
                .catch(function(error) {
                    alert('复制失败: ' + (error.message || '浏览器不支持'));
                });
        });
    });
}

function bindImagePreviewLinks() {
    document.querySelectorAll('.pswp-image').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('href');
            var imageFiles = allFiles.filter(function(f) { return f.isImage; });
            var index = imageFiles.findIndex(function(f) { return f.url === url; });
            if (index !== -1) {
                var currentPageFiles = allFiles.slice((currentPage - 1) * pageSize, currentPage * pageSize);
                var cardIndex = currentPageFiles.findIndex(function(f) { return f.url === url; });
                previewImage(cardIndex !== -1 ? cardIndex : index);
            }
        });
    });
}

function getPreviewDialogMarkup() {
    return '' +
        '<div style="position:absolute;top:0;left:0;right:0;height:50px;display:flex;align-items:center;justify-content:space-between;padding:0 20px;background:rgba(0,0,0,0.7);z-index:20;user-select:none;-webkit-user-select:none;">' +
            '<div id="previewImgName" style="color:#fff;font-size:14px;max-width:40%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>' +
            '<div style="display:flex;gap:8px;">' +
                '<button id="previewZoomOut" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:4px 10px;cursor:pointer;font-size:16px;line-height:1;">−</button>' +
                '<button id="previewZoomReset" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:4px 8px;cursor:pointer;font-size:12px;">100%</button>' +
                '<button id="previewZoomIn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:4px 10px;cursor:pointer;font-size:16px;line-height:1;">+</button>' +
                '<a id="previewImgDown" href="#" download style="color:#fff;font-size:13px;text-decoration:none;padding:4px 12px;border:1px solid rgba(255,255,255,0.3);border-radius:4px;">下载</a>' +
                '<button id="previewCloseBtn" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:4px;color:#fff;padding:4px 10px;cursor:pointer;font-size:16px;line-height:1;">✕</button>' +
            '</div>' +
        '</div>' +
        '<div id="previewImgWrap" style="position:absolute;top:50px;left:0;right:0;bottom:80px;display:flex;justify-content:center;align-items:center;overflow:hidden;user-select:none;-webkit-user-select:none;">' +
            '<img id="previewImg" style="max-width:100%;max-height:100%;object-fit:contain;transition:transform 0.15s;user-select:none;-webkit-user-select:none;-webkit-user-drag:none;transform-origin:center center;" />' +
        '</div>' +
        '<div id="previewLoading" style="position:absolute;color:#fff;font-size:14px;display:none;top:50%;left:50%;transform:translate(-50%,-50%);">加载中...</div>' +
        '<div id="previewCounter" style="position:absolute;bottom:15px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.7);font-size:13px;"></div>' +
        '<div class="preview-nav-btn preview-prev" style="position:absolute;top:50%;left:10px;transform:translateY(-50%);width:44px;height:70px;background:rgba(0,0,0,0.4);border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:30px;opacity:0.8;">‹</div>' +
        '<div class="preview-nav-btn preview-next" style="position:absolute;top:50%;right:10px;transform:translateY(-50%);width:44px;height:70px;background:rgba(0,0,0,0.4);border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:30px;opacity:0.8;">›</div>' +
        '<div class="preview-thumbs" style="position:absolute;bottom:40px;left:50%;transform:translateX(-50%);display:flex;gap:6px;max-width:95%;overflow-x:auto;padding:8px;"></div>';
}

function bindPreviewDialogEvents(dialog) {
    document.getElementById('previewCloseBtn').onclick = closePreviewDialog;
    document.getElementById('previewZoomIn').onclick = function(e) { e.stopPropagation(); previewZoom(0.2); };
    document.getElementById('previewZoomOut').onclick = function(e) { e.stopPropagation(); previewZoom(-0.2); };
    document.getElementById('previewZoomReset').onclick = function(e) { e.stopPropagation(); previewZoomReset(); };
    document.getElementById('previewImgWrap').onwheel = function(e) {
        e.preventDefault();
        previewZoom(e.deltaY > 0 ? -0.1 : 0.1);
    };
    dialog.onclick = function(e) {
        if (e.target === dialog || e.target.id === 'previewImgWrap') closePreviewDialog();
    };
    dialog.querySelector('.preview-prev').onclick = function(e) { e.stopPropagation(); previewNav(-1); };
    dialog.querySelector('.preview-next').onclick = function(e) { e.stopPropagation(); previewNav(1); };

    var touchStartX = 0;
    dialog.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    dialog.addEventListener('touchend', function(e) {
        var diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) {
            previewNav(diff > 0 ? 1 : -1);
        }
    }, { passive: true });
}

function ensurePreviewDialog() {
    var dialog = document.getElementById('imagePreviewDialog');
    if (dialog) {
        return dialog;
    }

    dialog = document.createElement('div');
    dialog.id = 'imagePreviewDialog';
    dialog.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:100000;display:none;opacity:0;transition:opacity 0.2s;user-select:none;-webkit-user-select:none;';
    dialog.innerHTML = getPreviewDialogMarkup();
    document.body.appendChild(dialog);
    bindPreviewDialogEvents(dialog);

    return dialog;
}

function previewImage(index) {
    var start = (currentPage - 1) * pageSize;
    var end = start + pageSize;
    var pageFiles = allFiles.slice(start, end);
    var imageFiles = pageFiles.filter(function(f) { return f.isImage; });
    var imageIndex = imageFiles.findIndex(function(f) { return pageFiles.indexOf(f) === index; });
    if (imageIndex === -1) imageIndex = 0;

    var files = imageFiles.map(function(file) {
        return { src: file.url, name: file.name };
    });

    if (files.length === 0) return;

    var dialog = ensurePreviewDialog();

    window.previewFiles = files;
    window.previewIndex = imageIndex;
    window.previewScale = 1;

    dialog.style.display = 'flex';
    setTimeout(function() { dialog.style.opacity = '1'; }, 10);

    updatePreview();
    dialog.focus();
    document.addEventListener('keydown', previewEscHandler);
}

function previewZoom(delta) {
    window.previewScale = Math.max(0.5, Math.min(3, window.previewScale + delta));
    var img = document.getElementById('previewImg');
    var resetBtn = document.getElementById('previewZoomReset');
    img.style.transform = 'scale(' + window.previewScale + ')';
    resetBtn.textContent = Math.round(window.previewScale * 100) + '%';
}

function previewZoomReset() {
    window.previewScale = 1;
    document.getElementById('previewImg').style.transform = 'scale(1)';
    document.getElementById('previewZoomReset').textContent = '100%';
}

function updatePreview() {
    var file = window.previewFiles[window.previewIndex];
    document.getElementById('previewImg').src = file.src;
    document.getElementById('previewImgName').textContent = file.name;
    document.getElementById('previewCounter').textContent = (window.previewIndex + 1) + ' / ' + window.previewFiles.length;
    document.getElementById('previewImgDown').href = file.src;
    document.getElementById('previewImgDown').download = file.name;

    previewZoomReset();

    var thumbs = document.querySelector('.preview-thumbs');
    thumbs.innerHTML = '';
    window.previewFiles.forEach(function(f, i) {
        var thumb = document.createElement('div');
        thumb.style.cssText = 'width:40px;height:40px;flex-shrink:0;border-radius:4px;overflow:hidden;cursor:pointer;border:2px solid ' + (i === window.previewIndex ? '#fff' : 'transparent') + ';opacity:' + (i === window.previewIndex ? '1' : '0.5') + ';transition:all 0.2s;';
        thumb.innerHTML = '<img src="' + f.src + '" style="width:100%;height:100%;object-fit:cover;" />';
        thumb.onclick = function(e) {
            e.stopPropagation();
            window.previewIndex = i;
            updatePreview();
        };
        thumbs.appendChild(thumb);
    });
}

function closePreviewDialog() {
    document.removeEventListener('keydown', previewEscHandler);
    var dialog = document.getElementById('imagePreviewDialog');
    if (dialog) {
        dialog.style.opacity = '0';
        setTimeout(function() { dialog.style.display = 'none'; }, 200);
    }
}

function previewEscHandler(e) {
    if (e.key === 'Escape') closePreviewDialog();
    if (e.key === 'ArrowLeft') previewNav(-1);
    if (e.key === 'ArrowRight') previewNav(1);
}

function previewNav(dir) {
    window.previewIndex += dir;
    if (window.previewIndex < 0) window.previewIndex = window.previewFiles.length - 1;
    if (window.previewIndex >= window.previewFiles.length) window.previewIndex = 0;
    updatePreview();
}

document.addEventListener('DOMContentLoaded', function() {
    loadAllFiles();
});

function loadAllFiles() {
    if (isLoading) return;
    isLoading = true;

    document.getElementById('ossFilesLoading').style.display = 'block';
    document.getElementById('ossFilesError').style.display = 'none';
    document.getElementById('ossFilesList').style.display = 'none';
    document.getElementById('ossFilesEmpty').style.display = 'none';

    var url = new URL(window.location.href);
    url.searchParams.set('do', 'list');
    url.searchParams.set('all', '1');

    requestJson(url.toString())
    .then(function(data) {
        isLoading = false;

        if (!data.success) {
            showError(data.message || '加载失败');
            return;
        }

        document.getElementById('ossFilesLoading').style.display = 'none';
        allFiles = data.files || [];
        totalFiles = allFiles.length;
        document.getElementById('totalFiles').textContent = totalFiles;
        applySortAndRender();
    })
    .catch(function(error) {
        isLoading = false;
        showError(error.message || '网络错误');
    });
}

function applySortAndRender() {
    allFiles.sort(function(a, b) {
        var valA;
        var valB;
        if (sortBy === 'name') {
            valA = a.name.toLowerCase();
            valB = b.name.toLowerCase();
        } else if (sortBy === 'size') {
            valA = a.sizeRaw;
            valB = b.sizeRaw;
        } else {
            valA = a.lastModified;
            valB = b.lastModified;
        }

        if (sortOrder === 'asc') {
            return valA < valB ? -1 : (valA > valB ? 1 : 0);
        }

        return valA > valB ? -1 : (valA < valB ? 1 : 0);
    });

    var totalPages = Math.ceil(totalFiles / pageSize) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    document.getElementById('totalPages').textContent = totalPages;
    document.getElementById('jumpPage').max = totalPages;
    document.getElementById('jumpPage').value = currentPage;

    var start = (currentPage - 1) * pageSize;
    var end = start + pageSize;
    var pageFiles = allFiles.slice(start, end);

    if (allFiles.length === 0) {
        document.getElementById('ossFilesEmpty').style.display = 'block';
        return;
    }

    renderFiles(pageFiles);
    document.getElementById('ossFilesList').style.display = 'block';
    document.getElementById('prevPage').disabled = currentPage <= 1;
    document.getElementById('nextPage').disabled = currentPage >= totalPages;
}

function loadPage(page) {
    var totalPages = Math.ceil(totalFiles / pageSize) || 1;
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;

    currentPage = page;
    applySortAndRender();
}

function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSize').value, 10);
    currentPage = 1;
    applySortAndRender();
}

function changeSort() {
    sortBy = document.getElementById('sortBy').value;
    sortOrder = document.getElementById('sortOrder').value;
    currentPage = 1;
    applySortAndRender();
}

function jumpToPage() {
    var page = parseInt(document.getElementById('jumpPage').value, 10) || 1;
    loadPage(page);
}

function toggleSidebar() {
    var sidebar = document.querySelector('.oss-sidebar');
    sidebar.classList.toggle('oss-sidebar-open');
}

function renderFiles(files) {
    var tbody = document.getElementById('ossFilesBody');
    var cardsContainer = document.getElementById('ossFilesCards');
    tbody.innerHTML = '';
    cardsContainer.innerHTML = '';

    files.forEach(function(file, index) {
        var row = document.createElement('tr');
        row.innerHTML =
            '<td><div class="oss-file-name">' +
            getThumbHtml(file, 'oss-file-thumb', '') +
            '<span title="' + file.key + '">' + file.name + '</span>' +
            getManagedBadgeHtml(file) +
            '</div></td>' +
            '<td>' + file.size + '</td>' +
            '<td>' + new Date(file.lastModified).toLocaleString('zh-CN') + '</td>' +
            '<td><div class="oss-file-actions">' +
            getViewButtonHtml(file, index, 'btn btn-s oss-btn-secondary') +
            '<button class="btn btn-s oss-btn-secondary copy-btn" data-url="' + file.url + '">复制</button>' +
            getDeleteAttachmentButtonHtml(file) +
            '</div></td>';
        tbody.appendChild(row);

        var card = document.createElement('div');
        card.className = 'oss-file-card';
        card.innerHTML =
            '<div class="oss-card-header">' +
            getThumbHtml(file, 'oss-card-thumb', '<div class="oss-card-icon"></div>') +
            '<div class="oss-card-info">' +
            '<div class="oss-card-name" title="' + file.key + '">' + file.name + getManagedBadgeHtml(file) + '</div>' +
            '<div class="oss-card-meta">' + file.size + ' · ' + new Date(file.lastModified).toLocaleDateString('zh-CN') + '</div>' +
            '</div>' +
            '</div>' +
            '<div class="oss-card-actions">' +
            getViewButtonHtml(file, index, 'btn oss-btn-secondary') +
            '<button class="btn oss-btn-secondary copy-btn" data-url="' + file.url + '">复制</button>' +
            getDeleteAttachmentButtonHtml(file)
                .replace('btn btn-s oss-btn-warn', 'btn oss-btn-warn') +
            '</div>';
        cardsContainer.appendChild(card);
    });

    bindCopyButtons();
    bindImagePreviewLinks();
}

function deleteFile(key) {
    if (!key) {
        alert('文件 key 无效');
        return;
    }

    if (deletingKeys[key]) {
        return;
    }

    if (!confirm('确认删除文件 ' + key + ' 吗？')) {
        return;
    }

    deletingKeys[key] = true;

    var url = new URL(window.location.href);
    url.searchParams.set('do', 'delete');
    url.searchParams.set('key', key);

    requestJson(url.toString())
    .then(function(data) {
        if (data.success) {
            alert('删除成功');
            loadAllFiles();
        } else {
            alert('删除失败: ' + (data.message || '未知错误'));
        }
    })
    .catch(function(error) {
        alert('网络错误: ' + error.message);
    })
    .finally(function() {
        delete deletingKeys[key];
    });
}

function deleteAttachment(key) {
    if (!key) {
        alert('文件 key 无效');
        return;
    }

    if (deletingKeys[key]) {
        return;
    }

    if (!confirm('确认删除 Typecho 附件记录并同步删除 OSS 文件 ' + key + ' 吗？')) {
        return;
    }

    deletingKeys[key] = true;

    var url = new URL(window.location.href);
    url.searchParams.set('do', 'deleteAttachment');
    url.searchParams.set('key', key);

    requestJson(url.toString())
    .then(function(data) {
        if (data.success) {
            alert('Typecho 附件记录和 OSS 文件已删除');
            loadAllFiles();
        } else {
            alert('删除附件失败: ' + (data.message || '未知错误'));
        }
    })
    .catch(function(error) {
        alert('网络错误: ' + error.message);
    })
    .finally(function() {
        delete deletingKeys[key];
    });
}
