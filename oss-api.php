<?php
/**
 * AliOssForTypecho - OSS AJAX API
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 处理 AJAX 请求
 *
 * @return void
 */
function ossHandleAjaxRequest() {
    error_reporting(0);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $user = Widget_User::alloc();
        $action = isset($_GET['do']) ? $_GET['do'] : 'list';
        if (in_array($action, ['editorList', 'attachExisting'], true)) {
            if (!$user || !$user->pass('contributor', true)) {
                ossJsonResponse([
                    'success' => false,
                    'message' => '权限不足，需要投稿权限'
                ]);
            }
        } else {
            if (!$user || !isset($user->group) || $user->group !== 'administrator') {
                ossJsonResponse([
                    'success' => false,
                    'message' => '权限不足，需要管理员权限'
                ]);
            }
        }

        if ($action === 'deleteAttachment') {
            deleteAttachmentAjax();
        }
        if ($action === 'delete') {
            deleteFileAjax();
        }
        if ($action === 'editorList') {
            listFilesAjax(true);
        }
        if ($action === 'attachExisting') {
            attachExistingAjax();
        }

        listFilesAjax(false);
    } catch (Exception $e) {
        error_log('AliOssForTypecho API bootstrap error: ' . $e->getMessage());
        ossJsonResponse([
            'success' => false,
            'message' => '接口初始化失败'
        ]);
    }
}

/**
 * 列出文件
 *
 * @param bool $getAll
 * @return void
 */
function listFilesAjax($getAll = false) {
    $getAll = $getAll || (isset($_GET['all']) && $_GET['all'] === '1');
    try {
        $options = Widget_Options::alloc()->plugin('AliOssForTypecho');
        $client = createOssClient();
        $prefix = getOssPrefix($options->pathPrefix);
        $managedPaths = getManagedAttachmentPaths();

        $files = [];
        $marker = '';
        $maxKeys = $getAll ? 1000 : 20;

        do {
            $request = new \AlibabaCloud\Oss\V2\Models\ListObjectsRequest(
                bucket: $options->bucket,
                prefix: $prefix,
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
                        'lastModified' => (is_object($object->lastModified) ? $object->lastModified->getTimestamp() : strtotime($object->lastModified)) * 1000,
                        'isImage' => $isImage,
                        'url' => getFileUrl($object->key),
                        'managedByTypecho' => isset($managedPaths[buildAttachmentPath($fileName)]),
                        'attachmentCid' => $managedPaths[buildAttachmentPath($fileName)] ?? null
                    ];
                }
            }

            $marker = $result->nextMarker ?? '';
            $isTruncated = $result->isTruncated ?? false;
        } while ($getAll && $isTruncated && count($files) < 5000);

        ossJsonResponse([
            'success' => true,
            'files' => $files,
            'total' => count($files)
        ]);
    } catch (\Exception $e) {
        error_log('AliOssForTypecho list files error: ' . $e->getMessage());
        ossJsonResponse([
            'success' => false,
            'message' => '文件列表加载失败，请检查 OSS 配置和连接状态'
        ]);
    }
}

/**
 * 删除文件
 *
 * @return void
 */
function deleteFileAjax() {
    $key = isset($_GET['key']) ? $_GET['key'] : '';

    if (empty($key)) {
        ossJsonResponse([
            'success' => false,
            'message' => '文件key不能为空'
        ]);
    }

    try {
        $options = Widget_Options::alloc()->plugin('AliOssForTypecho');
        $client = createOssClient();
        $key = validateObjectKey($key, $options->pathPrefix);
        $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($options->bucket, $key);
        $client->deleteObject($request);

        ossJsonResponse([
            'success' => true,
            'message' => '删除成功'
        ]);
    } catch (\Exception $e) {
        error_log('AliOssForTypecho delete file error: ' . $e->getMessage());
        ossJsonResponse([
            'success' => false,
            'message' => '删除失败，请确认文件路径和 OSS 配置是否正确'
        ]);
    }
}

/**
 * 删除 Typecho 附件记录并同步删除 OSS 文件
 *
 * @return void
 */
function deleteAttachmentAjax() {
    $key = isset($_GET['key']) ? $_GET['key'] : '';

    if (empty($key)) {
        ossJsonResponse([
            'success' => false,
            'message' => '文件key不能为空'
        ]);
    }

    try {
        $options = Widget_Options::alloc()->plugin('AliOssForTypecho');
        $client = createOssClient();
        $key = validateObjectKey($key, $options->pathPrefix);
        $fileName = basename($key);
        $attachments = findAttachmentsByFileName($fileName);

        if (empty($attachments)) {
            ossJsonResponse([
                'success' => false,
                'message' => '未找到对应的 Typecho 附件记录'
            ]);
        }

        $db = Typecho_Db::get();
        foreach ($attachments as $attachment) {
            $db->query(
                $db->delete('table.contents')
                    ->where('cid = ?', $attachment['cid'])
                    ->where('type = ?', 'attachment')
            );
        }

        $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($options->bucket, $key);
        $client->deleteObject($request);

        ossJsonResponse([
            'success' => true,
            'message' => 'Typecho 附件记录和 OSS 文件已删除'
        ]);
    } catch (\Exception $e) {
        error_log('AliOssForTypecho delete attachment error: ' . $e->getMessage());
        ossJsonResponse([
            'success' => false,
            'message' => '删除附件失败，请检查附件记录和 OSS 配置'
        ]);
    }
}

/**
 * 通过现有 OSS 文件创建或复用附件记录，供编辑器选择
 *
 * @return void
 */
function attachExistingAjax() {
    $key = isset($_POST['key']) ? $_POST['key'] : '';

    if (empty($key)) {
        ossJsonResponse([
            'success' => false,
            'message' => '文件key不能为空'
        ]);
    }

    try {
        $options = Widget_Options::alloc()->plugin('AliOssForTypecho');
        $key = validateObjectKey($key, $options->pathPrefix);
        $cid = isset($_POST['cid']) ? (int) $_POST['cid'] : 0;
        $fileName = basename($key);
        $path = buildAttachmentPath($fileName);
        $existingAttachment = $cid > 0 ? findAttachmentForParent($fileName, $cid) : null;

        if (!empty($existingAttachment['cid'])) {
            $attachment = buildEditorAttachmentResponse($existingAttachment);
            $attachment['reused'] = true;
            ossJsonResponse([
                'success' => true,
                'attachment' => $attachment
            ]);
        }

        $timestamp = time();
        $size = isset($_POST['size']) ? max(0, (int) $_POST['size']) : 0;
        $mime = guessMimeTypeByFileName($fileName);
        $type = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $content = [
            'name' => $fileName,
            'path' => $path,
            'size' => $size,
            'type' => $type,
            'mime' => $mime
        ];

        $db = Typecho_Db::get();
        $insertId = $db->query(
            $db->insert('table.contents')->rows([
                'title' => $fileName,
                'slug' => buildUniqueAttachmentSlug($fileName),
                'created' => $timestamp,
                'modified' => $timestamp,
                'text' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'order' => 0,
                'authorId' => (int) Widget_User::alloc()->uid,
                'type' => 'attachment',
                'status' => 'publish',
                'allowComment' => 1,
                'allowPing' => 0,
                'allowFeed' => 1,
                'parent' => $cid > 0 ? $cid : 0
            ])
        );

        $attachment = [
            'cid' => (int) $insertId,
            'title' => $fileName,
            'text' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parent' => $cid > 0 ? $cid : 0
        ];

        ossJsonResponse([
            'success' => true,
            'attachment' => buildEditorAttachmentResponse($attachment)
        ]);
    } catch (\Throwable $e) {
        error_log('AliOssForTypecho attach existing error: ' . $e->getMessage());
        ossJsonResponse([
            'success' => false,
            'message' => '添加 OSS 文件失败，请检查附件记录写入权限'
        ]);
    }
}


/**
 * 输出 JSON 并结束请求
 *
 * @param array $payload
 * @return void
 */
function ossJsonResponse(array $payload) {
    echo json_encode($payload);
    exit;
}

/**
 * 初始化 OSS 客户端
 *
 * @return \AlibabaCloud\Oss\V2\Client
 */
function createOssClient() {
    static $client = null;

    if ($client !== null) {
        return $client;
    }

    $sdkPath = __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';
    if (!file_exists($sdkPath)) {
        throw new RuntimeException('SDK文件不存在: ' . $sdkPath);
    }

    require_once $sdkPath;

    $options = Widget_Options::alloc()->plugin('AliOssForTypecho');

    $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
    $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
        $options->accessKeyId,
        $options->accessKeySecret
    ));
    $cfg->setRegion($options->region);

    $client = new \AlibabaCloud\Oss\V2\Client($cfg);
    return $client;
}

/**
 * 获取规范化前缀
 *
 * @param string $pathPrefix
 * @return string
 */
function getOssPrefix($pathPrefix) {
    $pathPrefix = trim((string) $pathPrefix, '/');
    return $pathPrefix === '' ? '' : $pathPrefix . '/';
}

/**
 * 校验对象 key，避免删除前缀外对象
 *
 * @param string $key
 * @param string $pathPrefix
 * @return string
 */
function validateObjectKey($key, $pathPrefix) {
    $key = ltrim((string) $key, '/');
    if ($key === '' || strpos($key, '..') !== false) {
        throw new InvalidArgumentException('非法文件 key');
    }

    $prefix = getOssPrefix($pathPrefix);
    if ($prefix !== '' && strpos($key, $prefix) !== 0) {
        throw new InvalidArgumentException('文件不在当前配置的路径前缀内');
    }

    return $key;
}

/**
 * 获取文件 URL
 *
 * @param string $key
 * @return string
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
 *
 * @param int|float $size
 * @return string
 */
function formatSize($size) {
    if ($size < 1024) {
        return $size . ' B';
    }
    if ($size < 1024 * 1024) {
        return round($size / 1024, 2) . ' KB';
    }
    if ($size < 1024 * 1024 * 1024) {
        return round($size / (1024 * 1024), 2) . ' MB';
    }

    return round($size / (1024 * 1024 * 1024), 2) . ' GB';
}

/**
 * 读取插件管理的 Typecho 附件路径集合
 *
 * @return array<string, int>
 */
function getManagedAttachmentPaths() {
    static $managedPaths = null;

    if ($managedPaths !== null) {
        return $managedPaths;
    }

    $managedPaths = [];

    try {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll(
            $db->select()
                ->from('table.contents')
                ->where('type = ?', 'attachment')
        );

        foreach ($rows as $row) {
            $attachmentPath = extractAttachmentPath($row);
            if (empty($attachmentPath) || empty($row['cid']) || strpos($attachmentPath, '/usr/uploads/oss/') !== 0) {
                continue;
            }
            $managedPaths[$attachmentPath] = (int) $row['cid'];
        }
    } catch (\Throwable $e) {
        error_log('AliOssForTypecho attachment preload error: ' . $e->getMessage());
    }

    return $managedPaths;
}

/**
 * 根据文件名查找插件管理的附件记录
 *
 * @param string $fileName
 * @return array<string, mixed>|null
 */
function findAttachmentByFileName($fileName) {
    $attachments = findAttachmentsByFileName($fileName);
    return empty($attachments) ? null : $attachments[0];
}

/**
 * 根据文件名查找所有插件管理的附件记录
 *
 * @param string $fileName
 * @return array<int, array<string, mixed>>
 */
function findAttachmentsByFileName($fileName) {
    try {
        $db = Typecho_Db::get();
        $path = buildAttachmentPath($fileName);
        $rows = $db->fetchAll(
            $db->select()
                ->from('table.contents')
                ->where('type = ?', 'attachment')
        );
        $attachments = [];

        foreach ($rows as $row) {
            if (extractAttachmentPath($row) === $path) {
                $attachments[] = $row;
            }
        }

        return $attachments;
    } catch (\Throwable $e) {
        error_log('AliOssForTypecho attachment find error: ' . $e->getMessage());
        return [];
    }
}

/**
 * 查找当前内容已关联的附件记录
 *
 * @param string $fileName
 * @param int $parentId
 * @return array<string, mixed>|null
 */
function findAttachmentForParent($fileName, $parentId) {
    $attachments = findAttachmentsByFileName($fileName);
    foreach ($attachments as $attachment) {
        if ((int) ($attachment['parent'] ?? 0) === $parentId) {
            return $attachment;
        }
    }

    return null;
}


/**
 * 从附件记录中提取附件路径
 *
 * @param array<string, mixed> $row
 * @return string
 */
function extractAttachmentPath(array $row) {
    if (!empty($row['path'])) {
        return (string) $row['path'];
    }

    if (empty($row['text']) || !is_string($row['text'])) {
        return '';
    }

    $data = json_decode($row['text'], true);
    if (is_array($data) && !empty($data['path'])) {
        return (string) $data['path'];
    }

    return '';
}

/**
 * 构建插件写入 Typecho 的附件路径
 *
 * @param string $fileName
 * @return string
 */
function buildAttachmentPath($fileName) {
    return '/usr/uploads/oss/' . ltrim($fileName, '/');
}

/**
 * 根据文件名猜测 MIME
 *
 * @param string $fileName
 * @return string
 */
function guessMimeTypeByFileName($fileName) {
    static $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg'
    ];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * 生成唯一附件 slug
 *
 * @param string $fileName
 * @return string
 */
function buildUniqueAttachmentSlug($fileName) {
    $db = Typecho_Db::get();
    $nameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $baseSlug = \Typecho\Common::slugName($nameWithoutExtension);
    if ($baseSlug === '') {
        $baseSlug = $nameWithoutExtension !== '' ? $nameWithoutExtension : 'oss-file';
    }
    $slug = $extension !== '' ? $baseSlug . '.' . $extension : $baseSlug;
    $suffix = 1;

    while (true) {
        $exists = $db->fetchRow(
            $db->select('cid')
                ->from('table.contents')
                ->where('type = ?', 'attachment')
                ->where('slug = ?', $slug)
                ->limit(1)
        );

        if (empty($exists)) {
            return $slug;
        }

        $slug = $extension !== ''
            ? $baseSlug . '-' . $suffix . '.' . $extension
            : $baseSlug . '-' . $suffix;
        $suffix++;
    }
}

/**
 * 构建编辑器需要的附件响应
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function buildEditorAttachmentResponse(array $row) {
    $data = [];
    if (!empty($row['text']) && is_string($row['text'])) {
        $decoded = json_decode($row['text'], true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $fileName = !empty($row['title']) ? (string) $row['title'] : basename((string) ($data['path'] ?? ''));
    $mime = !empty($data['mime']) ? (string) $data['mime'] : guessMimeTypeByFileName($fileName);
    $size = isset($data['size']) ? (int) $data['size'] : 0;
    $type = !empty($data['type']) ? (string) $data['type'] : strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isImage = strpos($mime, 'image/') === 0;

    return [
        'cid' => (int) ($row['cid'] ?? 0),
        'title' => $fileName,
        'type' => $type,
        'size' => $size,
        'bytes' => number_format((int) ceil($size / 1024)) . ' Kb',
        'isImage' => $isImage,
        'url' => getFileUrl(getOssPrefix(Widget_Options::alloc()->plugin('AliOssForTypecho')->pathPrefix) . $fileName)
    ];
}
