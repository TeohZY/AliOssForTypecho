<?php
/**
 * AliOssForTypecho - OSS 文件管理页面
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 获取插件目录的 URL
define('AliOssForTypecho_URL', rtrim(Helper::options()->pluginUrl, '/') . '/AliOssForTypecho/');

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
    include 'common.php';
    include __DIR__ . '/oss-api.php';
    ossHandleAjaxRequest();
}

include 'common.php';
include 'header.php';
include 'menu.php';

$options = Widget_Options::alloc()->plugin('AliOssForTypecho');
$assetVersion = filemtime(__DIR__ . '/assets/oss-files.css');

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

<link rel="stylesheet" href="<?php echo AliOssForTypecho_URL; ?>assets/oss-files.css?v=<?php echo $assetVersion; ?>" />

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
                        <button class="btn oss-btn-secondary oss-sidebar-toggle" onclick="toggleSidebar()">☰ 存储桶信息</button>
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
                                <option value="time" selected>修改时间</option>
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

                <div id="ossFilesEmpty" class="oss-empty" style="display: none;">
                    <div class="notice">暂无文件</div>
                </div>

                <div id="ossFilesList" class="oss-files-list" style="display: none;">
                    <!-- 桌面端表格 -->
                    <table class="typecho-list-table oss-table-desktop">
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
                    <!-- 移动端卡片列表 -->
                    <div id="ossFilesCards" class="oss-cards-mobile"></div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
window.AliOssForTypechoConfig = {
    pluginUrl: '<?php echo AliOssForTypecho_URL; ?>'
};
</script>
<script src="<?php echo AliOssForTypecho_URL; ?>assets/oss-files.js?v=<?php echo filemtime(__DIR__ . '/assets/oss-files.js'); ?>"></script>

<?php
include 'copyright.php';
include 'footer.php';
