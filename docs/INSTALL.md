# OMPS 安装说明（XAMPP 7.2.3，Windows）

[English version](INSTALL.en.md)

适用对象：负责服务器的同事。全程不需要联网，不需要安装任何额外的软件或 PHP 扩展。

## 1. 准备

1. 确认版本：打开 XAMPP 控制面板 → Shell，运行 `php -v`，应显示 PHP 7.2 或更高；运行 `mysql --version`，应显示 MariaDB 10.1 或更高。
2. 把 `OMPS` 文件夹复制到服务器，例如 `D:\OMPS`。**不要**放进 `htdocs`。
3. 在 XAMPP 控制面板启动 Apache 和 MySQL。

## 2. 建数据库和两个账号

在 Shell 中运行 `mysql -u root`（如果 root 已设密码，加 `-p`），执行（把两处密码换成自己的强密码）：

```sql
CREATE DATABASE omps CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- 日常使用的账号：只能读写数据，不能改表结构
CREATE USER 'omps_app'@'localhost' IDENTIFIED BY '换成强密码1';
CREATE USER 'omps_app'@'127.0.0.1' IDENTIFIED BY '换成强密码1';
GRANT SELECT, INSERT, UPDATE, DELETE ON omps.* TO 'omps_app'@'localhost', 'omps_app'@'127.0.0.1';
-- 升级用的账号：只在运行 php omps migrate 时使用
CREATE USER 'omps_migrate'@'localhost' IDENTIFIED BY '换成强密码2';
CREATE USER 'omps_migrate'@'127.0.0.1' IDENTIFIED BY '换成强密码2';
GRANT ALL PRIVILEGES ON omps.* TO 'omps_migrate'@'localhost', 'omps_migrate'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. 配置

1. 复制 `.env.example` 为 `.env`，填写 `DB_PASSWORD`（强密码1）和 `DB_MIGRATE_PASSWORD`（强密码2）。
2. 正式环境保持 `APP_ENV=production`、`APP_DEBUG=false`。
3. 设置 `FILES_PATH`（附件存放目录，在网站目录之外），例如 `FILES_PATH=D:/OMPS/files`；不设置时默认放在 `storage/files`。
4. 如果网址带子路径（如 `https://服务器/omps`），设置 `APP_BASE_PATH=/omps`。

## 4. 建表、基础数据、超级管理员

在 Shell 中进入 OMPS 目录（例如 `cd /d D:\OMPS`），依次运行：

```
php omps key:generate         生成加密密钥 APP_KEY（证件号、家属电话和住址用它加密）
php omps env:check            检查环境，[FAIL] 必须先解决
php omps migrate --seed       建表并写入角色、权限、菜单、语言等基础数据
php omps admin:create         创建第一个超级管理员（密码当场输入，不显示）
```

系统没有任何默认账号和默认密码。

**重要：备份 `.env`。** `APP_KEY` 丢失后，已加密的证件号码和家属联系方式无法再读出。每次备份数据库时，同时备份 `.env` 和附件目录。

## 5. 配置 Apache

Apache 只能对外提供 `public` 文件夹。在 `xampp\apache\conf\httpd.conf` 末尾加一行
`Include "conf/extra/omps.conf"`，并新建 `xampp\apache\conf\extra\omps.conf`：

```apache
Alias /omps "D:/OMPS/public"
<Directory "D:/OMPS/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

重启 Apache 后，用浏览器打开 `http://服务器/omps/` 登录。没有 mod_rewrite 时系统会自动改用 `index.php?r=` 形式的网址，功能不受影响。

## 6. 上传大小、提醒任务和定时清理

- XAMPP 自带 php.ini 只允许上传 2 MB 的文件。OMPS 在自己的 `public/.htaccess` 中把上限放宽到 50 MB，只对 OMPS 生效，不需要改 php.ini。要调整时同时改 `.htaccess` 和 `.env` 中的 `UPLOAD_MAX_MB`。
- **提醒任务（每 5 分钟）**：督促提醒、写业绩提醒、待审核提醒由 `php omps schedule:run` 发出。用管理员身份打开命令提示符，执行一次（路径按实际修改）：

  ```
  schtasks /create /tn "OMPS 提醒" /sc minute /mo 5 /ru SYSTEM /tr "D:\xampp\php\php.exe D:\OMPS\omps schedule:run"
  ```

  提醒只在工作时间发出（默认周一至周五 08:30–17:30，在“系统设置 → 工作时间”修改），同一个提醒只发一次，任务多跑几次也没关系。没有建这个任务时，只要有人打开着系统页面，页面也会每 5 分钟顺带运行一次；`php omps env:check` 会提示任务是否在运行。
- **每天清理**：在 Windows 任务计划程序中每天运行一次 `php D:\OMPS\omps cleanup`：删除过期的临时文件，彻底删除回收站中超过 30 天的文件，删除 180 天前的已读通知。

  ```
  schtasks /create /tn "OMPS 清理" /sc daily /st 02:00 /ru SYSTEM /tr "D:\xampp\php\php.exe D:\OMPS\omps cleanup"
  ```

## 6.1 设备采集客户端（可选）

需要核对电脑硬件时使用（设计文档第 14 节）。客户端是明文的 PowerShell 脚本，Windows 7 及以上自带的 PowerShell 即可运行，不需要安装其他软件。

1. 设备管理员在“使用与监测 → 采集范围”确认要采集的项目（默认：计算机名、操作系统、CPU、内存、硬盘、主板、显示器、网卡）。
2. 在“使用与监测 → 采集客户端”下载 4 个文件：`install.cmd`、`omps-collector.ps1`、`uninstall.cmd`、`readme.txt`。
3. 在设备档案中打开这台设备，“采集”标签里点“生成注册码”（7 天内有效，只能用一次）。
4. 把 4 个文件复制到这台电脑，右键 `install.cmd`，“以管理员身份运行”，输入服务器地址（如 `http://10.0.0.5/omps`）和注册码。

客户端开机 5 分钟后和每天 10:00 各上报一次；第一次上报的硬件自动登记为部件，以后和登记不一致时生成“异常记录”，通知设备管理员和单位负责人。不采集键盘、屏幕、文件、浏览记录和登录账号；`omps-collector.ps1 -Show` 可以看到本机将上报的全部内容。

## 6.2 数据备份

- 备份写到 `.env` 的 `BACKUP_PATH`（默认 `storage/backups`）。正式使用时最好放到另一块硬盘或 NAS，例如 `BACKUP_PATH=E:/OMPS-backup`。
- 每份备份是两样东西，名字相同：`omps_日期_时间_类型.zip`（数据库，可以在页面上下载）和同名文件夹（上传的文件；没有变化的文件与上一份备份共用，不重复占用空间，需要 NTFS 分区）。复制备份时两样要一起复制。
- **自动备份**：上面的“OMPS 提醒”任务每次运行时会顺带检查，每天到了设定时间（默认 02:00，在“系统管理 → 数据备份”修改）做一次备份；自动备份默认保留 14 天，手动备份一直保留。
- **升级前自动备份**：有新的升级时，`php omps migrate` 会先备份再建表；备份失败就不升级（确实要跳过时加 `--no-backup`）。
- **加密密钥**：证件号码等加密数据只能用 `.env` 中的 `APP_KEY` 读出。请把 `.env` 另外保存一份（不要和备份放在同一个地方）。用另一个 APP_KEY 做的备份不能恢复。
- **恢复**：在“数据备份”页面点恢复，输入自己的登录密码确认；或者在命令行运行 `php omps backup:restore 备份文件名.zip`。恢复前系统会先自动备份现在的数据；恢复后所有人需要重新登录。恢复使用 `.env` 中的升级账号（`DB_MIGRATE_USERNAME`）。
- **迁移到另一台服务器**：把 zip 和同名文件夹一起复制到新服务器的备份文件夹，页面上会显示为“外部文件”，点恢复即可（新服务器的 `.env` 要用同一个 APP_KEY）。

## 7. 正式使用前加固（设计文档第 03 节）

- 只开放 HTTPS（443），使用单位内部证书；`.env` 中设置 `SESSION_SECURE=true`。
- MariaDB 只监听 127.0.0.1；root 设置强密码；phpMyAdmin 停用或只允许本机访问。
- 系统只在单位内网使用，不要暴露到互联网（PHP 7.2 和 MariaDB 10.1 已停止安全更新）。

## 8. 以后升级 XAMPP 8.x

程序不用改：复制程序文件夹、`.env`、`storage` 文件夹和附件目录，导出旧数据库再导入新数据库，然后运行
`php omps migrate` 和 `php omps env:check`。也可以在旧服务器上做一份备份，把 zip 和同名文件夹复制到新服务器的备份文件夹后，在“数据备份”页面恢复。

## 9. 日常命令

| 命令 | 作用 |
|---|---|
| `php omps env:check` | 检查环境 |
| `php omps migrate` | 升级后建新表（使用升级账号） |
| `php omps migrate:status` | 查看已执行和待执行的升级 |
| `php omps seed` | 同步基础数据（不会覆盖已调整的角色权限） |
| `php omps admin:create` | 新增超级管理员 |
| `php omps key:generate` | 生成加密密钥（已存在时不会覆盖） |
| `php omps cleanup` | 清理临时文件、超过 30 天的回收站文件和 180 天前的已读通知 |
| `php omps schedule:run` | 发送到时间的提醒，到时间时做每日自动备份（由任务计划每 5 分钟运行；`--at="2026-11-30 09:05"` 可按指定时间试算） |
| `php omps backup:create` | 立即备份数据库和上传的文件 |
| `php omps backup:restore <文件名>` | 恢复备份文件夹中的一份备份（先自动备份现在的数据） |
