<?php
/**
 * AliOssForTypecho - OSS 文件管理页面
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

include 'common.php';
include 'header.php';
include 'menu.php';

$options = Options::alloc()->plugin('AliOssForTypecho');

// 检查是否配置了 OSS
if (empty($options->accessKeyId) || empty($options->accessKeySecret) || empty($options->bucket)) {
    echo '<div class="typecho-message notice"><p>请先在插件设置中配置 OSS 参数</p></div>';
    include 'copyright.php';
    include 'common-js.php';
    include 'footer.php';
    exit;
}

// 处理 AJAX 请求
if ($request->isAjax()) {
    if ($request->is('do=delete')) {
        deleteFile();
    } else {
        listFiles();
    }
    exit;
}

$page = $request->get('page', 1);
?>

<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <div class="oss-files-header">
                    <div class="oss-bucket-info">
                        <strong>Bucket:</strong> <?php echo htmlspecialchars($options->bucket); ?>
                        &nbsp;&nbsp;
                        <strong>Region:</strong> <?php echo htmlspecialchars($options->region); ?>
                        &nbsp;&nbsp;
                        <strong>路径:</strong> <?php echo htmlspecialchars($options->pathPrefix ?: '/'); ?>
                    </div>
                    <div class="oss-pagination">
                        <button class="btn btn-s" id="prevPage" onclick="loadPage(currentPage - 1)" disabled>&laquo; 上一页</button>
                        <span id="pageInfo">第 <span id="currentPage">1</span> 页</span>
                        <button class="btn btn-s" id="nextPage" onclick="loadPage(currentPage + 1)">下一页 &raquo;</button>
                    </div>
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
                                <th width="50%">文件名</th>
                                <th width="15%">大小</th>
                                <th width="20%">修改时间</th>
                                <th width="15%">操作</th>
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
.oss-files-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    margin-bottom: 20px;
}
.oss-bucket-info {
    color: #666;
}
.oss-pagination {
    display: flex;
    align-items: center;
    gap: 10px;
}
.typecho-list-table td {
    vertical-align: middle;
}
.oss-file-thumb {
    max-width: 80px;
    max-height: 60px;
    object-fit: contain;
    margin-right: 10px;
}
.oss-file-name {
    display: flex;
    align-items: center;
}
.oss-file-actions {
    display: flex;
    gap: 5px;
}
.oss-file-actions .btn {
    padding: 3px 8px;
    font-size: 12px;
}
</style>

<script>
var currentPage = 1;
var isLoading = false;

document.addEventListener('DOMContentLoaded', function() {
    loadPage(1);
});

function loadPage(page) {
    if (isLoading) return;
    isLoading = true;

    currentPage = page;
    document.getElementById('currentPage').textContent = page;

    document.getElementById('ossFilesLoading').style.display = 'block';
    document.getElementById('ossFilesError').style.display = 'none';
    document.getElementById('ossFilesList').style.display = 'none';
    document.getElementById('ossFilesEmpty').style.display = 'none';

    document.getElementById('prevPage').disabled = page <= 1;

    var url = '?do=list&page=' + page;

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

        if (data.files.length === 0) {
            document.getElementById('ossFilesEmpty').style.display = 'block';
            return;
        }

        renderFiles(data.files);
        document.getElementById('ossFilesList').style.display = 'block';

        document.getElementById('nextPage').disabled = !data.isTruncated;
    })
    .catch(function(error) {
        isLoading = false;
        document.getElementById('ossFilesLoading').style.display = 'none';
        document.getElementById('ossFilesError').style.display = 'block';
        document.getElementById('errorMessage').textContent = error.message || '网络错误';
    });
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
            '<a href="' + file.url + '" target="_blank" class="btn btn-s">查看</a>' +
            '<button class="btn btn-s copy-btn" data-url="' + file.url + '">复制</button>' +
            '<button class="btn btn-s btn-warn" onclick="deleteFile(\'' + file.key + '\')">删除</button>';

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

    var url = '?do=delete&key=' + encodeURIComponent(key);

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
            loadPage(currentPage);
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
    require_once __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';

    $options = Options::alloc()->plugin('AliOssForTypecho');
    $page = $request->get('page', 1);
    $pageSize = 20;

    $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
    $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
        $options->accessKeyId,
        $options->accessKeySecret
    ));
    $cfg->setRegion($options->region);

    $client = new \AlibabaCloud\Oss\V2\Client($cfg);
    $prefix = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/';

    try {
        $request = new \AlibabaCloud\Oss\V2\Models\ListObjectsRequest(
            bucket: $options->bucket,
            prefix: $prefix ?: '',
            delimiter: '/',
            maxKeys: $pageSize
        );

        $result = $client->listObjects($request);

        $files = [];
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
                    'lastModified' => $object->lastModified,
                    'isImage' => $isImage,
                    'url' => getFileUrl($object->key)
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'files' => $files,
            'page' => $page,
            'pageSize' => $pageSize,
            'isTruncated' => $result->isTruncated ?? false,
            'nextMarker' => $result->nextMarker ?? ''
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
    require_once __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';

    $options = Options::alloc()->plugin('AliOssForTypecho');
    $key = $request->get('key');

    if (empty($key)) {
        echo json_encode([
            'success' => false,
            'message' => '文件key不能为空'
        ]);
        exit;
    }

    $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
    $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
        $options->accessKeyId,
        $options->accessKeySecret
    ));
    $cfg->setRegion($options->region);

    $client = new \AlibabaCloud\Oss\V2\Client($cfg);

    try {
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
    $options = Options::alloc()->plugin('AliOssForTypecho');
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
