# Errata Infrastructure

Terraform configuration for deploying the Errata platform on Hetzner Cloud.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Hetzner Cloud (nbg1)                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│    ┌──────────────┐                                                         │
│    │ Load Balancer│ ◄─── HTTPS (443) ◄─── Internet                         │
│    │    (LB11)    │                                                         │
│    └──────┬───────┘                                                         │
│           │                                                                 │
│           │ HTTP (80)                                                       │
│           ▼                                                                 │
│    ┌──────────────────────────────────────┐                                │
│    │         Web Servers (scalable)        │                                │
│    │  ┌─────────┐  ┌─────────┐            │                                │
│    │  │  web-1  │  │  web-2  │  ...       │  Nginx + PHP 8.5 FPM           │
│    │  │  CX23   │  │  CX23   │            │                                │
│    │  └────┬────┘  └────┬────┘            │                                │
│    └───────┼────────────┼─────────────────┘                                │
│            │            │                                                   │
│            └─────┬──────┘                                                   │
│                  │ Private Network (10.0.0.0/16)                           │
│                  │                                                          │
│    ┌─────────────┼──────────────────────────────────────┐                  │
│    │             ▼                                       │                  │
│    │    ┌──────────────┐      ┌──────────────────────┐  │                  │
│    │    │    Infra     │      │   Workers (scalable)  │  │                  │
│    │    │ PostgreSQL 16│      │  ┌────────┐ ┌────────┐│  │                  │
│    │    │    Redis     │◄────►│  │worker-1│ │worker-2││  │                  │
│    │    │    CX23      │      │  │  CX23  │ │  CX23  ││  │                  │
│    │    │  10.0.1.10   │      │  └────────┘ └────────┘│  │                  │
│    │    └──────────────┘      └──────────────────────┘  │                  │
│    │                           PHP 8.5 CLI + Supervisor  │                  │
│    └─────────────────────────────────────────────────────┘                  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Components

| Component | Type | Count | Purpose |
|-----------|------|-------|---------|
| Infra Host | CX23 | 1 | PostgreSQL 16 + Redis |
| Web Servers | CX23 | 1+ | Nginx + PHP-FPM (scalable via `web_count`) |
| Workers | CX23 | 1+ | Symfony Messenger consumers (scalable via `worker_count`) |
| Load Balancer | LB11 | 1 | HTTPS termination, health checks |

## Quick Start

### 1. Set Up State Backend (Hetzner Object Storage)

Create a bucket for Terraform state:

1. Go to [Hetzner Cloud Console](https://console.hetzner.cloud/) → Object Storage
2. Create a bucket named `errata-terraform-state`
3. Go to Security → S3 Credentials → Generate new credentials

Configure the backend:

```bash
cp backend.hcl.example backend.hcl
```

Edit `backend.hcl` with your Object Storage credentials:

```hcl
access_key = "your-access-key"
secret_key = "your-secret-key"
```

Also update the endpoint in `backend.tf` to match your bucket's region (fsn1, nbg1, or hel1).

### 2. Configure Variables

```bash
cp terraform.tfvars.example terraform.tfvars
```

Edit `terraform.tfvars` with your values:

```hcl
hcloud_token      = "your-api-token"
ssh_public_key    = "ssh-ed25519 AAAA..."
domain            = "errata.example.com"
postgres_password = "strong-password-here"
app_secret        = "random-hex-string"
```

### 3. Initialize and Apply

```bash
terraform init -backend-config=backend.hcl
terraform plan
terraform apply
```

### 4. Configure DNS

After apply, configure your DNS:

```bash
terraform output dns_records
```

Point your domain A record to the load balancer IP.

### 5. Add SSL Certificate

In Hetzner Cloud Console:
1. Go to Load Balancers → errata-lb
2. Add a managed certificate for your domain

### 6. Deploy Application

```bash
# Get deployer host configuration
terraform output -raw deployer_hosts_config

# Update apps/server/deploy.php with the IPs
# Then deploy:
cd ../apps/server
composer require deployer/deployer --dev
vendor/bin/dep deploy production
```

## Scaling

### Scale Web Servers

```bash
terraform apply -var="web_count=3"
```

Then update `deploy.php` with new host IPs from:
```bash
terraform output -raw deployer_hosts_config
```

### Scale Workers

```bash
terraform apply -var="worker_count=2"
```

## Outputs

| Output | Description |
|--------|-------------|
| `infra_server_ip` | Public IP of PostgreSQL/Redis server |
| `web_server_ips` | List of web server public IPs |
| `worker_server_ips` | List of worker server public IPs |
| `load_balancer_ip` | Load balancer public IP |
| `deployer_hosts_config` | Copy-paste config for deploy.php |
| `ssh_config` | SSH config entries for easy access |
| `app_env_vars` | Environment variables for .env.local |
| `dns_records` | DNS configuration instructions |

## SSH Access

After deployment, add the SSH config to `~/.ssh/config`:

```bash
terraform output -raw ssh_config >> ~/.ssh/config
```

Then connect:

```bash
ssh errata-infra      # Infrastructure server
ssh errata-web-1      # First web server
ssh errata-worker-1   # First worker
```

## Network Layout

| Server | Private IP | Ports |
|--------|------------|-------|
| Infra | 10.0.1.10 | 5432 (PostgreSQL), 6379 (Redis) |
| Web-1 | 10.0.1.20 | 80 (HTTP) |
| Web-2 | 10.0.1.21 | 80 (HTTP) |
| Worker-1 | 10.0.1.30 | - |
| Worker-2 | 10.0.1.31 | - |
| Load Balancer | 10.0.1.2 | 80, 443 |

## Files

```
infra/
├── main.tf                 # Provider configuration
├── variables.tf            # Input variables
├── versions.tf             # Terraform/provider versions
├── backend.tf              # S3 backend for remote state
├── backend.hcl.example     # Backend credentials template
├── network.tf              # Private network and subnet
├── ssh_keys.tf             # SSH key resource
├── firewall.tf             # Firewall rules per role
├── servers.tf              # Server instances (scalable)
├── load_balancer.tf        # Load balancer + targets
├── storage.tf              # Object storage (documentation)
├── outputs.tf              # Output values
├── terraform.tfvars.example
└── cloud-init/
    ├── infra.yaml          # PostgreSQL + Redis setup
    ├── web.yaml            # Nginx + PHP-FPM setup
    └── worker.yaml         # Supervisor + PHP CLI setup
```

## Deployment with Deployer

The application uses [Deployer](https://deployer.org/) for zero-downtime deployments.

### Directory Structure on Servers

```
/var/www/errata/
├── current -> releases/5   # Symlink to active release
├── releases/
│   ├── 1/
│   ├── 2/
│   └── 5/                  # Latest release
└── shared/
    ├── .env.local          # Environment configuration
    ├── var/
    │   ├── log/            # Application logs
    │   └── data/           # SQLite/local data
    └── storage/            # Parquet files
```

### Common Commands

```bash
# Deploy
vendor/bin/dep deploy production

# Rollback
vendor/bin/dep rollback production

# List releases
vendor/bin/dep releases production

# SSH to a server
vendor/bin/dep ssh production

# View logs
vendor/bin/dep logs production

# Worker status
vendor/bin/dep messenger:status production
```

## Costs (Estimated)

| Resource | Monthly Cost |
|----------|-------------|
| CX23 × 3 (infra + web + worker) | ~€16 |
| LB11 Load Balancer | ~€6 |
| **Total (minimum)** | **~€22/month** |

Scaling adds ~€5.50/month per additional CX23 instance.

## Troubleshooting

### Check server cloud-init logs

```bash
ssh errata-web-1
sudo cat /var/log/cloud-init-output.log
```

### Verify services are running

```bash
# Web server
ssh errata-web-1
systemctl status nginx php8.5-fpm

# Worker
ssh errata-worker-1
sudo supervisorctl status

# Infrastructure
ssh errata-infra
systemctl status postgresql redis-server
```

### Test database connection

```bash
ssh errata-web-1
psql -h 10.0.1.10 -U errata -d errata
```

### View Terraform state

```bash
terraform state list
terraform state show hcloud_server.web[0]
```
