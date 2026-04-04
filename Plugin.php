<?php
/**
 * AliOssForTypecho - 阿里云 OSS 文件上传插件
 *
 * @package AliOssForTypecho
 * @author  TeohZY
 * @version 1.0.0
 * @link    https://github.com/yourname/AliOssForTypecho
 */

namespace TypechoPlugin\AliOssForTypecho;

use Typecho\Plugin\PluginInterface;
use Typecho\Common;
use Typecho\Date;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Widget\Options;
use Widget\Upload as WidgetUpload;
use Widget\Menu;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件主类
 *
 * @package AliOssForTypecho
 */
class Plugin implements PluginInterface
{
    // 上传文件目录
    const UPLOAD_DIR = '/usr/uploads';

    /**
     * 激活插件
     *
     * @return string
     */
    public static function activate(): string
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = __CLASS__ . '::uploadHandle';
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle = __CLASS__ . '::modifyHandle';
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle = __CLASS__ . '::deleteHandle';
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = __CLASS__ . '::attachmentHandle';
        \Typecho\Plugin::factory('Widget_Upload')->attachmentDataHandle = __CLASS__ . '::attachmentDataHandle';

        // 注册 OSS 文件管理路由
        \Typecho\Plugin::factory('Widget_Action')->filter = __CLASS__ . '::addActionRoute';

        // 添加管理菜单（通过 header 注入）
        \Typecho\Plugin::factory('admin/header.php')->filter = __CLASS__ . '::injectAdminHeader';

        return '阿里云 OSS 文件上传插件已激活';
    }

    /**
     * 添加路由
     *
     * @param array $routes
     * @return array
     */
    public static function addActionRoute(array $routes): array
    {
        $routes['ossFiles'] = [
            'widget' => '\\TypechoPlugin\\AliOssForTypecho\\Action\\OssFiles',
            'action' => 'ossFiles'
        ];
        return $routes;
    }

    /**
     * 注入管理后台头部脚本
     *
     * @param string $header
     * @return string
     */
    public static function injectAdminHeader(string $header): string
    {
        $url =\Common::url('action/ossFiles', \Widget\Options::alloc()->siteUrl);
        $menuUrl = Common::url('oss-files.php', \Widget\Options::alloc()->adminUrl);

        $script = <<<HTML
<script>
(function() {
    // 等待 DOM 加载完成
    document.addEventListener('DOMContentLoaded', function() {
        // 添加菜单项
        var manageMenu = document.querySelector('.menu-children li a[href*="manage-medias"]');
        if (manageMenu) {
            var li = document.createElement('li');
            li.innerHTML = '<a href="{$menuUrl}">OSS 文件管理</a>';
            manageMenu.parentElement.parentElement.appendChild(li);
        }

        // 添加按钮到管理文件页面
        var uploadForm = document.querySelector('.typecho-list-operate');
        if (uploadForm && !document.querySelector('.oss-files-btn')) {
            var btn = document.createElement('a');
            btn.href = '{$menuUrl}';
            btn.className = 'btn btn-s oss-files-btn';
            btn.textContent = 'OSS 文件管理';
            btn.style.marginLeft = '10px';
            uploadForm.appendChild(btn);
        }
    });
})();
</script>
HTML;
        return $header . $script;
    }

    /**
     * 禁用插件
     *
     * @return void
     */
    public static function deactivate(): void
    {
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form
     * @return void
     */
    public static function config(Form $form): void
    {
        $accessKeyId = new Text('accessKeyId', null, '', _t('AccessKey ID'), _t('阿里云 AccessKey ID'));
        $form->addInput($accessKeyId);

        $accessKeySecret = new Password('accessKeySecret', null, '', _t('AccessKey Secret'), _t('阿里云 AccessKey Secret'));
        $form->addInput($accessKeySecret);

        $bucket = new Text('bucket', null, '', _t('Bucket 名称'), _t('OSS Bucket 名称'));
        $form->addInput($bucket);

        $region = new Text('region', null, 'cn-hangzhou', _t('区域'), _t('OSS 区域，例如: cn-hangzhou'));
        $form->addInput($region);

        $domain = new Text('domain', null, '', _t('自定义域名'), _t('留空则使用默认域名，例如: https://oss.example.com'));
        $form->addInput($domain);

        $suffix = new Radio('suffix', ['.aliyuncs.com' => _t('外网'), '-internal.aliyuncs.com' => _t('内网')], '.aliyuncs.com', _t('节点访问方式'));
        $form->addInput($suffix);

        $pathPrefix = new Text('pathPrefix', null, 'typecho/', _t('路径前缀'), _t('文件存储路径前缀，例如: typecho/'));
        $form->addInput($pathPrefix);

        $renameFormat = new Select('renameFormat', [
            'timestamp' => _t('时间戳'),
            'original' => _t('保留原文件名')
        ], 'timestamp', _t('文件命名格式'));
        $form->addInput($renameFormat);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 上传文件处理
     *
     * @param array $file 上传的文件
     * @return mixed
     */
    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        // 获取安全扩展名
        $ext = self::getSafeName($file['name']);
        if (!WidgetUpload::checkFileType($ext)) {
            return false;
        }

        // 获取设置参数
        $options = Options::alloc()->plugin('AliOssForTypecho');

        // 获取上传文件
        $uploadFile = self::getUploadFile($file);
        if (empty($uploadFile)) {
            return false;
        }

        // 根据命名格式生成文件名
        if ($options->renameFormat === 'original') {
            // 保留原文件名
            $originalName = $file['name'];
            // 移除扩展名获取基础名
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            // 清理非法字符
            $baseName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $baseName);
            if (empty($baseName)) {
                $baseName = 'file';
            }
            $fileName = $baseName . '.' . $ext;
        } else {
            // 时间戳格式 - 使用微秒时间戳确保唯一性
            $fileName = sprintf('%u', crc32(microtime(true))) . '.' . $ext;
        }

        // 构建本地路径（用于数据库记录）
        $path = self::getUploadDir() . '/' . $fileName;

        // 上传到 OSS
        try {
            $ossClient = self::OssInit();
            $ossPath = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/' . $fileName;

            // 获取文件内容
            $content = file_get_contents($uploadFile);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $uploadFile);
            finfo_close($finfo);

            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest(
                bucket: $options->bucket,
                key: $ossPath,
                body: \AlibabaCloud\Oss\V2\Utils::streamFor($content),
                contentType: $mimeType
            );
            $ossClient->putObject($request);
        } catch (\Exception $e) {
            error_log('AliOssForTypecho upload error: ' . $e->getMessage());
            return false;
        }

        // 返回文件信息
        if (!isset($file['size'])) {
            $file['size'] = filesize($uploadFile);
        }

        return [
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => $mimeType
        ];
    }

    /**
     * 修改文件处理
     *
     * @param array $content 老文件
     * @param array $file 新上传的文件
     * @return mixed
     */
    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $options = Options::alloc()->plugin('AliOssForTypecho');
        $path = $content['attachment']->path;
        $uploadFile = self::getUploadFile($file);

        if (empty($uploadFile)) {
            return false;
        }

        try {
            $ossClient = self::OssInit();
            // 从本地路径提取文件名
            $fileName = basename($path);
            $ossPath = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/' . $fileName;

            $content = file_get_contents($uploadFile);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $uploadFile);
            finfo_close($finfo);

            $request = new \AlibabaCloud\Oss\V2\Models\PutObjectRequest(
                bucket: $options->bucket,
                key: $ossPath,
                body: \AlibabaCloud\Oss\V2\Utils::streamFor($content),
                contentType: $mimeType
            );
            $ossClient->putObject($request);
        } catch (\Exception $e) {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($uploadFile);
        }

        return [
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }

    /**
     * 删除文件
     *
     * @param array $content 文件相关信息
     * @return bool
     */
    public static function deleteHandle(array $content): bool
    {
        $options = Options::alloc()->plugin('AliOssForTypecho');

        try {
            $ossClient = self::OssInit();
            // 从本地路径提取文件名
            $fileName = basename($content['attachment']->path);
            $ossPath = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/' . $fileName;

            $request = new \AlibabaCloud\Oss\V2\Models\DeleteObjectRequest($options->bucket, $ossPath);
            $ossClient->deleteObject($request);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * 获取实际文件绝对访问路径
     *
     * @param \Typecho\Config $attachment 附件配置
     * @return string
     */
    public static function attachmentHandle(\Typecho\Config $attachment): string
    {
        $options = Options::alloc()->plugin('AliOssForTypecho');
        $domain = $options->domain;

        if (empty($domain)) {
            $domain = 'https://' . $options->bucket . '.' . $options->region . $options->suffix;
        }

        // 从本地路径提取文件名
        $fileName = basename($attachment->path);
        $ossPath = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/' . $fileName;
        return rtrim($domain, '/') . '/' . $ossPath;
    }

    /**
     * 获取实际文件数据
     *
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle(array $content): string
    {
        $options = Options::alloc()->plugin('AliOssForTypecho');

        try {
            $ossClient = self::OssInit();
            // 从本地路径提取文件名
            $fileName = basename($content['attachment']->path);
            $ossPath = rtrim(ltrim($options->pathPrefix, '/'), '/') . '/' . $fileName;

            $request = new \AlibabaCloud\Oss\V2\Models\GetObjectRequest($options->bucket, $ossPath);
            $result = $ossClient->getObject($request);
            return $result;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * OSS 初始化
     *
     * @return \AlibabaCloud\Oss\V2\Client
     */
    private static function OssInit(): \AlibabaCloud\Oss\V2\Client
    {
        static $client = null;

        if ($client !== null) {
            return $client;
        }

        // 按需加载 SDK
        require_once __DIR__ . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';

        $options = Options::alloc()->plugin('AliOssForTypecho');

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
     * 获取上传目录
     *
     * @return string
     */
    private static function getUploadDir(): string
    {
        if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        }
        return self::UPLOAD_DIR;
    }

    /**
     * 获取上传文件
     *
     * @param array $file
     * @return string
     */
    private static function getUploadFile(array $file): string
    {
        if (isset($file['tmp_name'])) {
            return $file['tmp_name'];
        }
        if (isset($file['bytes'])) {
            // 写入临时文件
            $tmpFile = tempnam(sys_get_temp_dir(), 'oss_');
            file_put_contents($tmpFile, $file['bytes']);
            return $tmpFile;
        }
        if (isset($file['bits'])) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'oss_');
            file_put_contents($tmpFile, $file['bits']);
            return $tmpFile;
        }
        return '';
    }

    /**
     * 获取安全的文件名
     *
     * @param string $name
     * @return string
     */
    private static function getSafeName(string &$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }
}
