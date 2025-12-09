# DevOps Implementation (Planned/Recommended)

## CI/CD Pipeline (Textual Flow)
1) **Source**: Git repo (feature branches → PRs → main)
2) **Build**: Composer install, lint PHP/JS/CSS, validate migrations.
3) **Test**: Unit/integration (where available), smoke tests on critical flows (auth/OTP, booking, approvals).
4) **Package/Artifact**: Build deployable bundle (app + vendor) or container image.
5) **Deploy to Staging**: Sync files or deploy container; run DB migrations (safe/transactional).
6) **Staging Verification**: Smoke tests (login + OTP, booking, approval, exports).
7) **Deploy to Production**: Zero-downtime file sync or rolling container update; apply migrations.
8) **Post-Deploy Checks**: Health checks, log scan, monitoring alarms.

Pipeline triggers:
- PR: build + lint + tests.
- Main branch: full pipeline to staging; manual approval to production.

## Infrastructure as Code (IaC)
Recommended (depending on platform):
- **Terraform**: Define app host (VM/container), network, security groups, DNS (for domain/Brevo), SMTP secrets, storage (for uploads).
- **Ansible**: Provision PHP runtime, Nginx/Apache vhost, PHP-FPM config, SSL (Let’s Encrypt), deploy app artifacts, manage env vars/secrets.
- **Docker Compose** (optional): PHP + web server + DB + mailhog/mailtrap for local; mount `public/uploads`.

Logical IaC components:
- Compute: app server/container.
- Network: VPC/subnet/security groups or firewall rules.
- DNS: A/CAA; TXT for SPF/DKIM/DMARC (Brevo).
- Storage: persistent volume for `public/uploads`.
- Secrets: SMTP creds, DB creds, app secrets (env/secret manager).
- SSL: Let’s Encrypt certs via reverse proxy.

## Monitoring & Alerting (Textual)
- **App uptime/health**: HTTP health checks on `/` or a dedicated `/health` endpoint.
- **Logs**: Centralized log shipping (e.g., CloudWatch/ELK/OpenSearch) with alerts on error spikes.
- **Metrics**:
  - HTTP 5xx/4xx rates, latency (p50/p95), throughput.
  - Auth failures/lockouts, OTP send failures.
  - DB errors, slow queries.
  - Disk usage for `public/uploads`.
- **Alerts**:
  - Uptime check fails.
  - Error rate spike or sustained 5xx.
  - OTP/email send failures.
  - Disk nearing capacity.
  - DB connection errors/slow query thresholds.

Tools (suggested):
- Uptime: Pingdom/UptimeRobot/Cloud provider health checks.
- Metrics: CloudWatch/Prometheus + Grafana.
- Logs: CloudWatch Logs/ELK/OpenSearch; alerts via SNS/Email/Slack.
- Error tracking (optional): Sentry/Rollbar for PHP.


