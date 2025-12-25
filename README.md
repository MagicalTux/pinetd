# Portable Inet Daemon (pinetd)

> **⚠️ ARCHIVAL CODE - NOT FOR PRODUCTION USE**
> This repository is preserved for historical/archeological purposes only. The code is incompatible with PHP 7+ and contains deprecated patterns and security vulnerabilities.

Second generation of the portable inet daemon (the first generation has been lost in the flow of time). Call this pinetd 1, and call the older one version zero.

## Overview

pinetd is a complete mail server infrastructure written in PHP (~4,500 lines), implementing multiple Internet services as forking daemons. It was developed circa 2006-2008 and represents early-2000s PHP daemon development practices.

## What's Here

### Service Daemons (`daemon/`)

| Port | Service | Description |
|------|---------|-------------|
| 21   | FTP     | Full FTP server (codename "KASUMI") with user/anonymous auth, PASV/PORT modes, quota system, chroot support |
| 25   | SMTP    | ESMTP with STARTTLS, DNSBL/RBL spam checking, ClamAV/SpamAssassin integration, email aliasing |
| 110  | POP3    | RFC 1939 compliant with SASL PLAIN authentication |
| 143  | IMAP4   | RFC 3501 (IMAP4rev1) with multi-folder support |
| 990  | FTPS    | SSL wrapper for FTP |
| 995  | POP3S   | SSL wrapper for POP3 |

### Core Components

- **`start.php`** - Master daemon launcher and process orchestrator
- **`subprocess/pmaild.php`** - Mail Transfer Agent for outgoing mail delivery with MX resolution and retry queue
- **`funcs/`** - Shared libraries:
  - `base/cfork.php` - Process forking with signal handling
  - `base/mysql.php` - Database connection wrapper
  - `serv/socket.php` - Network I/O abstraction with SSL/TLS support
  - `sysv_shared.php` - Inter-process communication via SysV shared memory

### Database

MySQL-backed storage for accounts, mail, folders, queue, and spam checking caches. Schema files in `sql/`.

## Historical Patterns

This codebase is a time capsule of PHP practices from 2006-2008:

- **Deprecated MySQL extension** (`mysql_*` functions, removed in PHP 7.0)
- **`list()/each()` loops** (deprecated PHP 7.2, removed PHP 8.0)
- **Curly brace string access** (`$str{0}` instead of `$str[0]`)
- **Process-per-connection model** (fork for each client)
- **Pure procedural code** - no classes, namespaces, or OOP
- **SysV IPC** - shared memory and semaphores for daemon communication
- **Manual `pcntl_signal()` handling**

## Security Notes

This code contains known vulnerabilities and should never be deployed:

- SQL injection risks (no prepared statements)
- Deprecated cryptographic practices
- Unmaintained dependencies

## Author

MagicalTux <magicaltux@gmail.com>

## Project Structure

```
pinetd/
├── daemon/           # Port-based service implementations
├── subprocess/       # Background processes (MTA)
├── funcs/            # Shared function libraries
│   ├── base/         # Core system functions
│   └── serv/         # Protocol helpers
├── sql/              # Database schemas
├── ssl/              # Certificate generation scripts
├── start.php         # Main daemon launcher
├── daemonctl         # Control script
└── config.php        # Configuration template
```
