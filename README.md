# DSSC CampusNet

A web-based campus bandwidth monitoring and network usage analytics system for **Davao del Sur State College**, built for the ICT Office. It watches routers and links, records traffic, raises alerts, produces reports, and shows office-level network activity, without inventing data it cannot measure.

> **Honesty rule:** CampusNet never shows fake numbers as real ones. Demo data is clearly labelled, a failing real driver never falls back to mock data, and anything that needs telemetry the network does not provide is shown as an empty state that says what is missing.

## Contents

- [Features](#features)
- [Technology stack](#technology-stack)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [Roles and permissions](#roles-and-permissions)
- [Office Network Activity module](#office-network-activity-module)
- [Network telemetry: supported and unsupported](#network-telemetry-supported-and-unsupported)
- [Privacy, security and data retention](#privacy-security-and-data-retention)
- [Scheduled jobs and commands](#scheduled-jobs-and-commands)
- [Testing](#testing)
- [Project layout](#project-layout)
- [Roadmap](#roadmap)

## Features

**Monitoring**

- Dashboard with WAN utilization, device status, alerts and incident history
- Network device management (routers, switches, access points, servers) with encrypted SNMP credentials
- Interface monitoring and WAN role assignment
- Bandwidth analytics and traffic history with data-quality reporting
- Alerts (device unreachable, high utilization, missing measurements) with acknowledge and resolve
- Reports with CSV export (traffic, alerts, availability)
- Online Devices page: real ping results for the router and the internet, plus a network scan with online counts per office
- TV Display: a read-only NOC wallboard for a Smart TV, paired with a one-time code and refreshed every few seconds

**Administration**

- Role-based access control with three roles
- User management, audit logs and system settings
- Public registration is disabled; accounts are created by a Super Administrator

**Office Network Activity**

- Office Connections: devices per office, online and offline counts
- Connected Members: which registered members have devices on the network, with unidentified devices clearly separated
- Website Activity: domain-level activity from DNS, proxy or firewall telemetry, labelled by the kind of observation
- Data sources: each source is off until enabled, reads from the router with read-only access, and has its own retention period

## Technology stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, PHP 8.5+ |
| Frontend | React 19, TypeScript, Inertia.js, Tailwind CSS 4, shadcn/ui, Lucide, Recharts |
| Database | MySQL (SQLite for the test suite) |
| Build | Vite |
| Monitoring | SNMP (PHP `snmp` extension), ICMP ping, optional MikroTik RouterOS API |
| Auth | Laravel starter kit authentication, queues and scheduler |

## Getting started

### Requirements

- PHP 8.3 or newer with the `snmp` extension (only needed for the real SNMP driver)
- Composer
- Node.js 22 and npm
- MySQL

The project is developed on Windows 11 with [Laragon](https://laragon.org/).

### Installation

```bash
git clone https://github.com/jerick12nd/campusnet.git
cd campusnet

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create an empty MySQL database, set `DB_*` in `.env`, then:

```bash
php artisan migrate
php artisan db:seed --class=ActivityDataSourceSeeder
npm run build
```

For development with hot reload run `npm run dev` and `php artisan serve` (or use Laragon's virtual host).

### First account

Public registration is disabled. Accounts are created by a Super Administrator in **User Management**. The three role values are `super_admin`, `network_admin` and `viewer`.

### Trying it without a network

Set the driver to `mock`, then create clearly simulated data:

```bash
php artisan campusnet:demo-data
php artisan campusnet:poll --force --sync
```

Demo devices and samples are flagged as demo everywhere, are hidden as soon as a real driver is selected, and can be purged without touching real data.

## Configuration

Main environment variables:

| Variable | Purpose |
| --- | --- |
| `BANDWIDTH_MONITORING_DRIVER` | Monitoring driver: `mock` (demo), `snmp`, or `mikrotik` |
| `OFFICE_NETWORK_ONLINE_THRESHOLD` | Seconds after which an unseen device counts as offline (default `180`) |
| `OFFICE_NETWORK_RETENTION_DAYS` | Default retention for collected records (default `90`) |
| `OFFICE_NETWORK_DOMAIN_BUCKET` | Domain activity is stored per client, domain and this many seconds (default `300`) |
| `OFFICE_NETWORK_MAX_REPORT_DAYS` | Longest range a Website Activity report or export may cover (default `90`) |

Verify the exact driver names in `config/campusnet.php`. The campus name, time zone (initially `Asia/Manila`), thresholds and polling interval are managed in **System Settings**.

## Roles and permissions

Every route is protected by a Laravel gate (`can:` middleware). Hiding a sidebar item is a convenience, never the security boundary. Permissions are defined in `app/Enums/UserRole.php`.

| Capability | Read-Only Viewer | ICT / Network Administrator | Super Administrator |
| --- | :---: | :---: | :---: |
| Dashboard, devices, interfaces, traffic, alerts, reports (view) | yes | yes | yes |
| Manage devices, interfaces and alerts | no | yes | yes |
| Export reports | no | yes | yes |
| Office Connections (totals per office) | yes | yes | yes |
| Connected Members, members and registered devices (view) | no | yes | yes |
| Website Activity (domain level, no member names) | no | yes | yes |
| Export connection and website reports | no | yes | yes |
| Manage offices, members, devices and assignments | no | no | yes |
| See **who** visited a domain (member, device, IP) | no | no | yes |
| Manage data sources and retention | no | no | yes |
| Users, audit logs, system settings, TV Display pairing | no | no | yes |

## Office Network Activity module

| Page | Route | Purpose |
| --- | --- | --- |
| Office Connections | `/office-connections` | Devices per office, online and offline counts, last update |
| Connected Members | `/connected-members` | Registered members with connected devices, unidentified devices, CSV export |
| Members | `/members` | The people of each office and their connection history |
| Registered devices | `/registered-devices` | Devices registered by MAC address and assigned to members |
| Website Activity | `/website-activity` | Domain-level activity, top domains, per-office view, daily trend, CSV export |
| Data sources | `/activity-sources` | Enable or disable each source, choose the router, set retention |

### How people are identified

An IP address is never treated as a person. A device belongs to a member only because an administrator registered its MAC address and assigned it to that member. A device that is not registered is shown as **Unidentified**, and its traffic is stored without a member or office. When a device is reassigned, only its open connections change: past connections keep who was assigned at the time.

Phones often use a private (randomized) Wi-Fi address that changes per network, so a registered phone may stop matching. The interface warns about such addresses.

### Database tables

`offices`, `members`, `registered_devices`, `member_device_assignments`, `activity_data_sources`, `device_connection_sessions`, `domain_activity_records`. Migrations only add tables and columns. Offices, members and devices are archived with soft deletes, never hard-deleted.

## Network telemetry: supported and unsupported

CampusNet only shows what the network really records. SNMP alone cannot identify people or list websites.

| Source | Status | What it provides | Limits |
| --- | --- | --- | --- |
| SNMP interface counters | **Supported** | Interface traffic, link status, utilization | Per interface, not per user or website |
| ICMP ping and subnet scan | **Supported** | Router and internet reachability and latency, online device counts per office subnet | Counts only; no identity |
| Mock driver | **Supported (demo)** | Clearly labelled simulated data | Never mixed with real data |
| Ingestion pipeline for domain records | **Supported** | Cleans domain names, attributes records to a registered device only when a connection session proves it, aggregates per bucket | Needs a collector to feed it |
| MikroTik DHCP lease collector | **Planned** | MAC, IP and host name of connected devices, which identifies registered devices | Identifies devices only; a device with a static IP will not appear |
| MikroTik DNS query log collector | **Planned** | Domain names asked by each client IP | A DNS lookup does not prove a site was visited; needs the router to be the clients' DNS server |
| Proxy logs | Ingestion supports the type; no collector yet | Domain names and, where logged, bytes | Only for traffic that goes through the proxy |
| Firewall logs | Ingestion supports the type; no collector yet | Connection metadata | Firewall logs contain IP addresses, not domain names |
| RADIUS accounting, captive portal | Not implemented | Authenticated identity | Out of scope for now |

**What CampusNet will not do**

- Decrypt or intercept HTTPS (no TLS interception)
- Capture messages, passwords, form submissions or page contents
- Install monitoring software on devices
- Claim that a website was visited when only a DNS lookup or connection attempt was recorded
- Guess which member owns a device
- Fabricate activity when no supported data source exists

Encrypted DNS (DNS over HTTPS) and some apps hide lookups from the router, so domain counts are a lower bound. When no source is collecting, Website Activity shows an empty state that names the telemetry it needs.

## Privacy, security and data retention

This module handles network metadata about identifiable people. It is meant for network administration and must be covered by school policy and the Data Privacy Act of 2012 (Republic Act No. 10173).

- Transparent notice on the pages that the network may be monitored under school policy
- Every source is disabled until a Super Administrator enables it, and reads from the router with read-only access
- Collect only what administration needs; every source documents its purpose and limits
- Member-level data (names, MAC and IP addresses, who visited which domain) is restricted by permission and is never sent to the browser for roles that may not see it
- Viewing and exporting member-level data, and every administrative change, are written to the audit log
- CSV exports are permission-checked, rate-limited, audited, and neutralise spreadsheet formulas
- Retention is configured per source. `php artisan office-network:prune` deletes records older than their source's retention period, including for sources that are switched off, and never closes or removes a connection that is still open
- Secrets are encrypted at rest and never exposed in the browser, logs or exports

## Scheduled jobs and commands

Run the scheduler in production (`php artisan schedule:work`, or a cron entry for `schedule:run`).

| Command | Purpose |
| --- | --- |
| `php artisan campusnet:poll` | Poll monitored devices through the active driver |
| `php artisan campusnet:demo-data` | Create clearly simulated demo data (refused when a real driver is active) |
| `php artisan network:ping` | Ping the router and the internet |
| `php artisan network:scan` | Scan office subnets for online devices |
| `php artisan office-network:prune` | Delete office network records past their retention period (`--dry-run` to preview) |

Schedule the retention job by adding this to `routes/console.php`:

```php
\Illuminate\Support\Facades\Schedule::command('office-network:prune')->dailyAt('02:30');
```

## Testing

```bash
php artisan test
npm run build
```

The suite covers access control (including direct URL and API access), device and office management, member-device assignments, unidentified device handling, connection status accuracy, domain ingestion and source attribution, exports, retention rules, and empty or unavailable data states.

## Project layout

```text
app/
  Enums/UserRole.php            Roles and the permission matrix
  Services/                     Monitoring, dashboard, office network and audit services
  Http/Controllers/             Inertia controllers
  Console/Commands/             Poll, scan, demo-data and prune commands
config/office_network.php       Office Network Activity settings
routes/campusnet.php            CampusNet routes and their permission gates
resources/js/pages/             React pages (one folder per module)
resources/js/components/        Shared UI (sidebar, status badges, empty states)
tests/Feature/                  Feature tests per module
```

## Roadmap

- DHCP lease collector (connected devices)
- DNS query log collector (website activity)
- Screenshots and a short demo in this README

## Author

Built by Jerick Vicente for the DSSC ICT Office.
