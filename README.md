# SillyLi 表白墙 V2

2019 年 V1.6 的完全重构版：PHP 8 + PDO 预处理 + Bootstrap 5.3（无 jQuery），数据库 utf8mb4 全新结构。

## 功能（与原版等价）

- 前台：首页列表（分页）、表白详情 + 评论、发布表白（弹窗表单）、点赞、搜索、关于页
- 后台：登录、仪表盘统计、告白列表（改/删/恢复）、评论列表（删/恢复）、网站配置（含音乐播放器 JS 代码框、改管理员密码）
- 安装向导：`/install/`（环境检测 → 数据库与管理员配置 → 自动建表建号 → 生成 config.php + install.lock）
- 数据迁移：`migrate/migrate.php`（V1 三表 → V2，见文件头用法）

## 目录

```
index.php          前台控制器（全部路由）
router.php         PHP 内置服务器用（开发/测试）
config.php         安装向导生成（不入库）
install.lock       安装向导生成
app/               bootstrap / helpers / views（禁止 Web 直访）
assets/            bootstrap.min.css / bootstrap.bundle.min.js（发行包自带）+ app.css / app.js
install/           安装向导
sql/schema.sql     建表 SQL（安装向导读取）
migrate/           V1→V2 迁移脚本（CLI）
```

## 部署要点

- PHP ≥ 8.1 + pdo_mysql + mbstring；MariaDB / MySQL（utf8mb4）
- nginx 伪静态：`try_files $uri $uri/ /index.php?$query_string;`，并禁止访问 `/app/`、`/sql/`、`/migrate/`、`/config.php`
- 首次访问自动跳转安装向导；装完建议删除 `install/` 目录
- 点赞防刷：IP+UA 哈希唯一约束（likes 表），不再用 cookie

## 与 V1 的主要差异

- 全部 SQL 预处理；输出统一转义；POST 全量 CSRF token
- 管理员密码 `password_hash` 存储（V1 为明文，迁移时强制重设）
- 点赞独立成表；评论数冗余计数；软删除（可恢复）
- 表情 emoji 可存（utf8mb4）；分页 bug 已修（V1 每页条数随页码膨胀）

## 开源协议

MIT License，见 [LICENSE](LICENSE)。
