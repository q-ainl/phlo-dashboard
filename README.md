# Phlo Dashboard

Admin and monitoring dashboard for the Phlo stack. It bundles fleet monitoring, a notifications inbox, WhatsApp status, subscriptions and a built-in database manager for MySQL and SQLite.

The Dashboard is the operations layer of the [Phlo platform](https://phlo.tech/ecosystem): one place to oversee every app, server and domain built on the [Phlo engine](https://github.com/q-ainl/phlo). Not to be confused with the Phlo Control Center, the per-app dev panel built into the engine itself.

## Features

- **Fleet overview**: real time status of every host and app across the fleet (uptime, CPU, memory, disk, errors, visitors).
- **Database admin** (`dbadmin`): browse and edit rows, a full structure editor with column reordering, indexes, foreign keys, privileges, an SQL console, import and export, foreign key navigation, database wide search and server status. Works with MySQL and SQLite.
- **Notifications**: a server scoped inbox; apps push notifications via a secret protected endpoint.
- **Subscriptions**: users subscribe to (server, event) pairs and get proactive WhatsApp or dashboard delivery.
- **WhatsApp**: overview of all [phloWA](https://github.com/q-ainl/phlo-whatsapp) instances across the fleet.
- **Visitors, domains, sites**: visitor analytics and host/domain management.
- **Multi user**: roles plus per server and per module permissions.

## Requirements

- PHP 8.3 or newer with `ext-pdo` (`ext-pdo_mysql` and/or `ext-pdo_sqlite` for the database admin)
- FrankenPHP
- [phlo/tech](https://github.com/q-ainl/phlo) and [phlo/cms](https://github.com/q-ainl/phlo-cms), pulled in via Composer

## Install

```
composer create-project phlo/dashboard mydashboard
```

Then set up the node local config:

1. Copy `www/app.php.example` to `www/app.php` and set your host and paths.
2. Copy `data/app.example.json` to `data/app.json`.
3. Create `data/creds.ini` (MySQL connection, fleet peers, alert and notify endpoints) and, for HTTP basic login, `data/auth.ini`.
4. Build the app: `php www/app.php build::run`
5. Serve with FrankenPHP.

## License

MIT. See [LICENSE](LICENSE).
