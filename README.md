# AliOssForTypecho

阿里云 OSS 文件上传插件 for Typecho 1.3.0

## 功能特性

- 将文件上传到阿里云 OSS
- 支持自定义域名
- 支持内网/外网访问
- 支持两种文件命名格式：时间戳或保留原文件名
- 支持修改、删除文件时同步操作 OSS
- 插件停用后自动恢复本地上传

## 环境要求

- Typecho 1.3.0
- PHP 7.4+ (推荐 PHP 8.2)
- 阿里云 OSS PHP SDK v2

## 安装

1. 下载插件目录 `AliOssForTypecho`
2. 将目录上传到 Typecho 的 `usr/plugins/` 目录
3. 在后台「插件」页面激活插件
4. 在插件设置中配置阿里云 OSS 参数

## 配置说明

| 配置项 | 说明 |
|--------|------|
| AccessKey ID | 阿里云 AccessKey ID |
| AccessKey Secret | 阿里云 AccessKey Secret |
| Bucket 名称 | OSS Bucket 名称 |
| 区域 | OSS 区域，如 cn-hangzhou |
| 自定义域名 | 留空则使用默认域名，可填写 CDN 域名等 |
| 节点访问方式 | 外网/内网，内网需在阿里云 ECS 上使用 |
| 路径前缀 | 文件存储路径前缀，如 `typecho/` |
| 文件命名格式 | 时间戳（唯一性）或保留原文件名 |

## 上传后的文件 URL

访问 URL 格式：`https://{bucket}.{region}.aliyuncs.com/{pathPrefix}/{filename}`

使用自定义域名时：`{自定义域名}/{pathPrefix}/{filename}`

## 注意事项

1. 插件不会验证配置的正确性，请确保参数正确
2. 启用插件后，新上传的文件将存储到 OSS
3. 之前本地上传的文件链接不会自动迁移
4. 禁用插件后，文件将恢复存储到本地

## 许可证

MIT License
