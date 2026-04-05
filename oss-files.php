<?php
/**
 * AliOssForTypecho - OSS 文件管理页面
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 处理 AJAX 请求 - 必须在 include 其他文件之前检查
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    // 检查是否为超级管理员
    $isAdmin = false;
    try {
        include 'common.php';

        // 使用 Widget_User 获取当前用户
        $user = Widget_User::alloc();
        if ($user && isset($user->group) && $user->group === 'administrator') {
            $isAdmin = true;
        }
    } catch (Exception $e) {
        $isAdmin = false;
    }

    if (!$isAdmin) {
        echo json_encode([
            'success' => false,
            'message' => '权限不足，需要管理员权限'
        ]);
        exit;
    }

    if (isset($_GET['do']) && $_GET['do'] === 'delete') {
        deleteFile();
    } else {
        listFiles();
    }
    exit;
}

include 'common.php';
include 'header.php';
include 'menu.php';

$options = Widget_Options::alloc()->plugin('AliOssForTypecho');

// 检查是否配置了 OSS
if (empty($options->accessKeyId) || empty($options->accessKeySecret) || empty($options->bucket)) {
    $adminUrl = Widget_Options::alloc()->adminUrl;
    ?>
    <main class="main">
        <div class="body container">
            <?php include 'page-title.php'; ?>
            <div class="row typecho-page-main" role="main">
                <div class="col-mb-12">
                    <div class="typecho-message notice">
                        <p>请先配置 OSS 参数才能使用文件管理功能</p>
                        <p><a href="<?php echo $adminUrl; ?>options-plugin.php?config=AliOssForTypecho" class="btn oss-btn-primary">前往设置</a></p>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php
    include 'copyright.php';
    include 'common-js.php';
    include 'footer.php';
    exit;
}
?>

<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="oss-layout">
            <aside class="oss-sidebar">
                <div class="oss-bucket-icon">
                    <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                </div>
                <h3>存储桶信息</h3>
                <div class="oss-info-item">
                    <div class="oss-info-label">Bucket 名称</div>
                    <div class="oss-info-value"><?php echo htmlspecialchars($options->bucket); ?></div>
                </div>
                <div class="oss-info-item">
                    <div class="oss-info-label">区域</div>
                    <div class="oss-info-value"><?php echo htmlspecialchars($options->region); ?></div>
                </div>
                <div class="oss-info-item">
                    <div class="oss-info-label">路径前缀</div>
                    <div class="oss-info-value"><?php echo htmlspecialchars($options->pathPrefix ?: '/'); ?></div>
                </div>
                <div class="oss-info-item">
                    <div class="oss-info-label">访问方式</div>
                    <div class="oss-info-value"><?php echo $options->suffix == '-internal.aliyuncs.com' ? '内网' : '外网'; ?></div>
                </div>
                <?php if (!empty($options->domain)): ?>
                <div class="oss-info-item">
                    <div class="oss-info-label">自定义域名</div>
                    <div class="oss-info-value"><a href="<?php echo htmlspecialchars($options->domain); ?>" target="_blank"><?php echo htmlspecialchars($options->domain); ?></a></div>
                </div>
                <?php endif; ?>
            </aside>

            <div class="oss-main">
                <div class="oss-toolbar">
                    <div class="oss-toolbar-left">
                        <label>每页
                            <select id="pageSize" class="oss-select" onchange="changePageSize()">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select> 条
                        </label>
                        <label>排序
                            <select id="sortBy" class="oss-select" onchange="changeSort()">
                                <option value="name">文件名</option>
                                <option value="size">大小</option>
                                <option value="time">修改时间</option>
                            </select>
                            <select id="sortOrder" class="oss-select" onchange="changeSort()">
                                <option value="asc">升序</option>
                                <option value="desc" selected>降序</option>
                            </select>
                        </label>
                    </div>
                    <div class="oss-toolbar-right">
                        <span id="totalInfo">共 <span id="totalFiles">0</span> 个文件</span>
                    </div>
                </div>

                <div class="oss-pagination">
                    <button class="btn btn-s oss-btn-secondary" id="prevPage" onclick="loadPage(currentPage - 1)" disabled>&laquo; 上一页</button>
                    <span id="pageInfo">
                        <input type="number" id="jumpPage" class="oss-page-input" min="1" value="1" onchange="jumpToPage()" />
                        / <span id="totalPages">1</span>
                    </span>
                    <button class="btn btn-s oss-btn-secondary" id="nextPage" onclick="loadPage(currentPage + 1)">下一页 &raquo;</button>
                </div>

                <div id="ossFilesLoading" class="typecho-list-table-wrap">
                    <div class="loading">加载中...</div>
                </div>

                <div id="ossFilesError" class="typecho-list-table-wrap" style="display: none;">
                    <div class="error message notice">
                        <p id="errorMessage"></p>
                    </div>
                </div>

                <div id="ossFilesEmpty" class="typecho-list-table-wrap" style="display: none;">
                    <div class="notice">暂无文件</div>
                </div>

                <div id="ossFilesList" class="typecho-list-table-wrap" style="display: none;">
                    <table class="typecho-list-table">
                        <thead>
                            <tr>
                                <th width="45%">文件名</th>
                                <th width="15%">大小</th>
                                <th width="20%">修改时间</th>
                                <th width="20%">操作</th>
                            </tr>
                        </thead>
                        <tbody id="ossFilesBody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.oss-layout {
    display: flex;
    gap: 24px;
    padding: 20px 0;
}
.oss-sidebar {
    width: 260px;
    flex-shrink: 0;
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.oss-main {
    flex: 1;
    min-width: 0;
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.oss-sidebar h3 {
    font-size: 14px;
    color: #1a73e8;
    margin: 0 0 16px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e8f0fe;
}
.oss-info-item {
    margin-bottom: 14px;
}
.oss-info-label {
    font-size: 11px;
    color: #5f6368;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.oss-info-value {
    font-size: 14px;
    color: #202124;
    font-weight: 500;
}
.oss-info-value a {
    color: #1a73e8;
    text-decoration: none;
}
.oss-info-value a:hover {
    text-decoration: underline;
}
.oss-bucket-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.oss-bucket-icon svg {
    width: 28px;
    height: 28px;
    fill: #fff;
}
.oss-pagination {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 0;
    border-bottom: 1px solid #e8eaed;
    margin-bottom: 0;
}
.typecho-list-table {
    width: 100%;
    border-collapse: collapse;
}
.typecho-list-table thead th {
    text-align: left;
    padding: 12px 16px;
    background: #f8f9fa;
    color: #5f6368;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e8eaed;
}
.typecho-list-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f3f4;
    vertical-align: middle;
}
.typecho-list-table tbody tr:hover {
    background: #f8f9fa;
}
.typecho-list-table tbody tr:last-child td {
    border-bottom: none;
}
.oss-file-thumb {
    max-width: 50px;
    max-height: 50px;
    object-fit: cover;
    border-radius: 6px;
    margin-right: 12px;
    border: 1px solid #e8eaed;
}
.oss-file-name {
    display: flex;
    align-items: center;
}
.oss-file-name span {
    color: #202124;
    font-weight: 500;
}
.oss-file-name small {
    color: #5f6368;
    font-size: 11px;
    margin-left: 8px;
}
.oss-file-actions {
    display: flex;
    gap: 6px;
}
.oss-file-actions .btn {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}
.oss-file-actions .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.oss-btn-primary {
    background: #1a73e8;
    color: #fff;
}
.oss-btn-primary:hover {
    background: #1557b0;
}
.oss-btn-secondary {
    background: #e8f0fe;
    color: #1a73e8;
}
.oss-btn-secondary:hover {
    background: #d2e3fc;
}
.oss-btn-warn {
    background: #fce8e6;
    color: #d93025;
}
.oss-btn-warn:hover {
    background: #fad2cf;
}
.loading, .notice, .error {
    padding: 40px;
    text-align: center;
    color: #5f6368;
}
.loading {
    color: #1a73e8;
}
.error {
    color: #d93025;
}
.oss-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #e8eaed;
    margin-bottom: 0;
}
.oss-toolbar-left {
    display: flex;
    align-items: center;
    gap: 20px;
}
.oss-toolbar-right {
    color: #5f6368;
    font-size: 13px;
}
.oss-select {
    padding: 4px 8px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    background: #fff;
    font-size: 13px;
    color: #202124;
    margin: 0 4px;
}
.oss-select:focus {
    outline: none;
    border-color: #1a73e8;
}
.oss-page-input {
    width: 50px;
    padding: 4px 8px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    text-align: center;
    font-size: 13px;
}
.oss-page-input:focus {
    outline: none;
    border-color: #1a73e8;
}
</style>

<script>
var currentPage = 1;
var isLoading = false;
var allFiles = [];
var totalFiles = 0;
var pageSize = 20;
var sortBy = 'name';
var sortOrder = 'desc';

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
    url = url.toString();

    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        isLoading = false;
        document.getElementById('ossFilesLoading').style.display = 'none';

        if (!data.success) {
            document.getElementById('ossFilesError').style.display = 'block';
            document.getElementById('errorMessage').textContent = data.message || '加载失败';
            return;
        }

        allFiles = data.files || [];
        totalFiles = allFiles.length;
        document.getElementById('totalFiles').textContent = totalFiles;

        applySortAndRender();
    })
    .catch(function(error) {
        isLoading = false;
        document.getElementById('ossFilesLoading').style.display = 'none';
        document.getElementById('ossFilesError').style.display = 'block';
        document.getElementById('errorMessage').textContent = error.message || '网络错误';
    });
}

function applySortAndRender() {
    // 排序
    allFiles.sort(function(a, b) {
        var valA, valB;
        if (sortBy === 'name') {
            valA = a.name.toLowerCase();
            valB = b.name.toLowerCase();
        } else if (sortBy === 'size') {
            valA = a.sizeRaw;
            valB = b.sizeRaw;
        } else if (sortBy === 'time') {
            valA = a.lastModified;
            valB = b.lastModified;
        }

        if (sortOrder === 'asc') {
            return valA < valB ? -1 : (valA > valB ? 1 : 0);
        } else {
            return valA > valB ? -1 : (valA < valB ? 1 : 0);
        }
    });

    // 计算总页数
    var totalPages = Math.ceil(totalFiles / pageSize) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    document.getElementById('totalPages').textContent = totalPages;
    document.getElementById('jumpPage').max = totalPages;
    document.getElementById('jumpPage').value = currentPage;

    // 分页显示
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
    pageSize = parseInt(document.getElementById('pageSize').value);
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
    var page = parseInt(document.getElementById('jumpPage').value) || 1;
    loadPage(page);
}

function renderFiles(files) {
    var tbody = document.getElementById('ossFilesBody');
    tbody.innerHTML = '';

    files.forEach(function(file) {
        var row = document.createElement('tr');

        var nameTd = document.createElement('td');
        nameTd.innerHTML = '<div class="oss-file-name">' +
            (file.isImage ? '<img src="' + file.url + '" class="oss-file-thumb" />' : '') +
            '<span title="' + file.key + '">' + file.name + '</span>' +
            '</div>';

        var sizeTd = document.createElement('td');
        sizeTd.textContent = file.size;

        var timeTd = document.createElement('td');
        timeTd.textContent = new Date(file.lastModified).toLocaleString('zh-CN');

        var actionTd = document.createElement('td');
        actionTd.className = 'oss-file-actions';
        actionTd.innerHTML =
            '<a href="' + file.url + '" target="_blank" class="btn btn-s oss-btn-secondary">查看</a>' +
            '<button class="btn btn-s oss-btn-secondary copy-btn" data-url="' + file.url + '">复制</button>' +
            '<button class="btn btn-s oss-btn-warn" onclick="deleteFile(\'' + file.key + '\')">删除</button>';

        row.appendChild(nameTd);
        row.appendChild(sizeTd);
        row.appendChild(timeTd);
        row.appendChild(actionTd);
        tbody.appendChild(row);
    });

    // 复制链接功能
    document.querySelectorAll('.copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('data-url');
            navigator.clipboard.writeText(url).then(function() {
                alert('链接已复制');
            });
        });
    });
}

function deleteFile(key) {
    if (!confirm('确认删除文件 ' + key + ' 吗？')) {
        return;
    }

    var url = new URL(window.location.href);
    url.searchParams.set('do', 'delete');
    url.searchParams.set('key', key);
    url = url.toString();

    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.json();
    })
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
    });
}
</script>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';

/**
 * 列出文件
 */
function listFiles() {
    $getAll = isset($_GET['all']) && $_GET['all'] === '1';

    $sdkPath = __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';
    if (!file_exists($sdkPath)) {
        echo json_encode([
            'success' => false,
            'message' => 'SDK文件不存在: ' . $sdkPath
        ]);
        exit;
    }

    require_once $sdkPath;

    $options = Widget_Options::alloc()->plugin('AliOssForTypecho');

    try {
        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
            $options->accessKeyId,
            $options->accessKeySecret
        ));
        $cfg->setRegion($options->region);

        $client = new \AlibabaCloud\Oss\V2\Client($cfg);
        $prefix = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/';

        $files = [];
        $marker = '';
        $maxKeys = $getAll ? 1000 : 20; // 获取更多文件用于排序

        do {
            $request = new \AlibabaCloud\Oss\V2\Models\ListObjectsRequest(
                bucket: $options->bucket,
                prefix: $prefix ?: '',
                delimiter: '/',
                maxKeys: $maxKeys,
                marker: $marker
            );

            $result = $client->listObjects($request);

            if (!empty($result->contents)) {
                foreach ($result->contents as $object) {
                    if (substr($object->key, -1) === '/') {
                        continue;
                    }

                    $fileName = basename($object->key);
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);

                    $files[] = [
                        'key' => $object->key,
                        'name' => $fileName,
                        'size' => formatSize($object->size),
                        'sizeRaw' => $object->size,
                        'lastModified' => is_object($object->lastModified) ? $object->lastModified->getTimestamp() : strtotime($object->lastModified),
                        'isImage' => $isImage,
                        'url' => getFileUrl($object->key)
                    ];
                }
            }

            $marker = $result->nextMarker ?? '';
            $isTruncated = $result->isTruncated ?? false;

        } while ($getAll && $isTruncated && count($files) < 5000);

        echo json_encode([
            'success' => true,
            'files' => $files,
            'total' => count($files)
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 删除文件
 */
function deleteFile() {
    $key = isset($_GET['key']) ? $_GET['key'] : '';

    $sdkPath = __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';
    if (!file_exists($sdkPath)) {
        echo json_encode([
            'success' => false,
            'message' => 'SDK文件不存在'
        ]);
        exit;
    }

    require_once $sdkPath;

    $options = Widget_Options::alloc()->plugin('AliOssForTypecho');

    if (empty($key)) {
        echo json_encode([
            'success' => false,
            'message' => '文件key不能为空'
        ]);
        exit;
    }

    try {
        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
            $options->accessKeyId,
            $options->accessKeySecret
        ));
        $cfg->setRegion($options->region);

        $client = new \AlibabaCloud\Oss\V2\Client($cfg);
        $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($options->bucket, $key);
        $client->deleteObject($request);

        echo json_encode([
            'success' => true,
            'message' => '删除成功'
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * 获取文件 URL
 */
function getFileUrl($key) {
    $options = Widget_Options::alloc()->plugin('AliOssForTypecho');
    $domain = $options->domain;

    if (empty($domain)) {
        $domain = 'https://' . $options->bucket . '.' . $options->region . $options->suffix;
    }

    return rtrim($domain, '/') . '/' . $key;
}

/**
 * 格式化文件大小
 */
function formatSize($size) {
    if ($size < 1024) {
        return $size . ' B';
    } elseif ($size < 1024 * 1024) {
        return round($size / 1024, 2) . ' KB';
    } elseif ($size < 1024 * 1024 * 1024) {
        return round($size / (1024 * 1024), 2) . ' MB';
    } else {
        return round($size / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
