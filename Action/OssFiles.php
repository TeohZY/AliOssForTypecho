<?php
/**
 * AliOssForTypecho - 阿里云 OSS 文件上传插件
 *
 * @package AliOssForTypecho
 * @author  TeohZY
 * @version 1.0.0
 */

namespace TypechoPlugin\AliOssForTypecho\Action;

use Typecho\Widget;
use Typecho\Widget\Exception;
use Widget\Options;
use Typecho\Common;
use Typecho\Date;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * OSS 文件管理
 *
 * @package AliOssForTypecho
 */
class OssFiles extends Widget implements ActionInterface
{
    private $ossClient = null;
    private $bucket = null;
    private $prefix = null;

    /**
     * 执行函数
     *
     * @throws Exception
     */
    public function execute()
    {
        $this->user->pass('administrator');

        // 检查是否配置了 OSS
        $options = Options::alloc()->plugin('AliOssForTypecho');
        if (empty($options->accessKeyId) || empty($options->accessKeySecret) || empty($options->bucket)) {
            throw new Exception(_t('请先配置 OSS 参数'), 404);
        }

        $this->initOssClient();
    }

    /**
     * 初始化 OSS 客户端
     */
    private function initOssClient()
    {
        require_once __DIR__ . '/../oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';

        $options = Options::alloc()->plugin('AliOssForTypecho');

        $cfg = \AlibabaCloud\Oss\V2\Config::loadDefault();
        $cfg->setCredentialsProvider(new \AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
            $options->accessKeyId,
            $options->accessKeySecret
        ));
        $cfg->setRegion($options->region);

        if (!empty($options->endpoint)) {
            $cfg->setEndpoint($options->endpoint);
        }

        if ($options->useInternalEndpoint === '1') {
            $cfg->setUseInternalEndpoint(true);
        }

        $this->ossClient = new \AlibabaCloud\Oss\V2\Client($cfg);
        $this->bucket = $options->bucket;
        $this->prefix = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/';
    }

    /**
     * 动作函数
     */
    public function action()
    {
        // 处理 AJAX 请求
        if ($this->request->isAjax()) {
            if ($this->request->is('do=delete')) {
                $this->deleteFile();
                return;
            }

            $page = $this->request->get('page', 1);
            $this->listFiles($page, 20);
            return;
        }

        // 直接访问页面时渲染模板
        $this->render();
    }

    /**
     * 渲染页面
     */
    private function render()
    {
        include __DIR__ . '/../oss-files.php';
        exit;
    }

    /**
     * 列出文件
     *
     * @param int $page
     * @param int $pageSize
     */
    private function listFiles(int $page, int $pageSize)
    {
        $marker = ($page - 1) * $pageSize;

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\ListObjectsRequest(
                bucket: $this->bucket,
                prefix: $this->prefix ?: '',
                delimiter: '/',
                marker: $marker > 0 ? $this->getMarkerByOffset($page, $pageSize) : '',
                maxKeys: $pageSize
            );

            $result = $this->ossClient->listObjects($request);

            $files = [];
            if (!empty($result->contents)) {
                foreach ($result->contents as $object) {
                    // 跳过目录
                    if (substr($object->key, -1) === '/') {
                        continue;
                    }

                    // 获取文件信息
                    $fileName = basename($object->key);
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);

                    $files[] = [
                        'key' => $object->key,
                        'name' => $fileName,
                        'size' => $this->formatSize($object->size),
                        'sizeRaw' => $object->size,
                        'lastModified' => $object->lastModified,
                        'isImage' => $isImage,
                        'url' => $this->getFileUrl($object->key)
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
            exit;
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * 获取分页 marker
     */
    private function getMarkerByOffset(int $page, int $pageSize): string
    {
        // 简单实现：通过 nextMarker 来处理
        // 实际上 OSS 的 marker 是基于 key 的，不是简单的分页
        // 这里返回空字符串，由调用方通过 nextMarker 维护
        return '';
    }

    /**
     * 删除文件
     */
    private function deleteFile()
    {
        $key = $this->request->get('key');
        if (empty($key)) {
            echo json_encode([
                'success' => false,
                'message' => _t('文件key不能为空')
            ]);
            exit;
        }

        try {
            $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($this->bucket, $key);
            $this->ossClient->deleteObject($request);

            echo json_encode([
                'success' => true,
                'message' => _t('删除成功')
            ]);
            exit;
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * 获取文件 URL
     *
     * @param string $key
     * @return string
     */
    private function getFileUrl(string $key): string
    {
        $options = Options::alloc()->plugin('AliOssForTypecho');
        $domain = $options->domain;

        if (empty($domain)) {
            $domain = 'https://' . $this->bucket . '.' . $options->region . $options->suffix;
        }

        return rtrim($domain, '/') . '/' . $key;
    }

    /**
     * 格式化文件大小
     *
     * @param int $size
     * @return string
     */
    private function formatSize(int $size): string
    {
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
}
