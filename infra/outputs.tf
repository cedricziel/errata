# -----------------------------------------------------------------------------
# Server IP Addresses
# -----------------------------------------------------------------------------

output "infra_server_ip" {
  description = "Public IP of the infrastructure server"
  value       = hcloud_server.infra.ipv4_address
}

output "infra_server_private_ip" {
  description = "Private IP of the infrastructure server"
  value       = "10.0.1.10"
}

output "web_server_ips" {
  description = "Public IPs of web servers"
  value       = hcloud_server.web[*].ipv4_address
}

output "web_server_private_ips" {
  description = "Private IPs of web servers"
  value       = [for i in range(var.web_count) : "10.0.1.${20 + i}"]
}

output "worker_server_ips" {
  description = "Public IPs of worker servers"
  value       = hcloud_server.worker[*].ipv4_address
}

output "worker_server_private_ips" {
  description = "Private IPs of worker servers"
  value       = [for i in range(var.worker_count) : "10.0.1.${30 + i}"]
}

# -----------------------------------------------------------------------------
# Load Balancer
# -----------------------------------------------------------------------------

output "load_balancer_ip" {
  description = "Public IP of the load balancer"
  value       = hcloud_load_balancer.main.ipv4
}

output "load_balancer_ipv6" {
  description = "IPv6 address of the load balancer"
  value       = hcloud_load_balancer.main.ipv6
}

# -----------------------------------------------------------------------------
# DNS Configuration
# -----------------------------------------------------------------------------

output "dns_records" {
  description = "DNS records to configure"
  value       = <<-EOT
Add the following DNS records:

A Record:
  ${var.domain} -> ${hcloud_load_balancer.main.ipv4}

AAAA Record (optional):
  ${var.domain} -> ${hcloud_load_balancer.main.ipv6}
EOT
}

# -----------------------------------------------------------------------------
# Deployer Configuration
# -----------------------------------------------------------------------------

output "deployer_hosts_config" {
  description = "Deployer host configuration to add to deploy.php"
  value       = <<-EOT

// ============================================================================
// Auto-generated from Terraform - paste this into deploy.php
// ============================================================================

%{for i, ip in hcloud_server.web[*].ipv4_address~}
host('web-${i + 1}')
    ->setHostname('${ip}')
    ->set('labels', ['stage' => 'production', 'role' => 'web'])
    ->set('deploy_path', '/var/www/errata')
    ->set('remote_user', 'deploy');

%{endfor~}
%{for i, ip in hcloud_server.worker[*].ipv4_address~}
host('worker-${i + 1}')
    ->setHostname('${ip}')
    ->set('labels', ['stage' => 'production', 'role' => 'worker'])
    ->set('deploy_path', '/var/www/errata')
    ->set('remote_user', 'deploy');

%{endfor~}
EOT
}

# -----------------------------------------------------------------------------
# SSH Configuration
# -----------------------------------------------------------------------------

output "ssh_config" {
  description = "SSH config entries for easy access"
  value       = <<-EOT
# Add to ~/.ssh/config

Host errata-infra
    HostName ${hcloud_server.infra.ipv4_address}
    User deploy
    IdentityFile ~/.ssh/errata-deploy

%{for i, ip in hcloud_server.web[*].ipv4_address~}
Host errata-web-${i + 1}
    HostName ${ip}
    User deploy
    IdentityFile ~/.ssh/errata-deploy

%{endfor~}
%{for i, ip in hcloud_server.worker[*].ipv4_address~}
Host errata-worker-${i + 1}
    HostName ${ip}
    User deploy
    IdentityFile ~/.ssh/errata-deploy

%{endfor~}
EOT
}

# -----------------------------------------------------------------------------
# Environment Variables for Application
# -----------------------------------------------------------------------------

output "app_env_vars" {
  description = "Environment variables for the application .env.local"
  sensitive   = true
  value       = <<-EOT
# Database (PostgreSQL on infra server)
DATABASE_URL="postgresql://errata:${var.postgres_password}@10.0.1.10:5432/errata?serverVersion=16&charset=utf8"

# Redis (on infra server)
REDIS_URL="redis://${var.redis_password != "" ? ":${var.redis_password}@" : ""}10.0.1.10:6379"

# Messenger transport
MESSENGER_TRANSPORT_DSN="redis://${var.redis_password != "" ? ":${var.redis_password}@" : ""}10.0.1.10:6379/messages"

# Application
APP_ENV=prod
APP_SECRET=${var.app_secret}

# Storage (update with actual bucket credentials)
STORAGE_BUCKET=${var.storage_bucket_name}
STORAGE_ENDPOINT=https://fsn1.your-objectstorage.com
# STORAGE_ACCESS_KEY=your-access-key
# STORAGE_SECRET_KEY=your-secret-key
EOT
}

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------

output "summary" {
  description = "Infrastructure summary"
  value       = <<-EOT
================================================================================
Errata Infrastructure Summary
================================================================================

Location: ${var.location}
Environment: ${var.environment}

Servers:
  - Infrastructure: ${hcloud_server.infra.ipv4_address} (PostgreSQL 16 + Redis)
  - Web Servers: ${var.web_count} instance(s)
  - Workers: ${var.worker_count} instance(s)

Load Balancer: ${hcloud_load_balancer.main.ipv4}

Next Steps:
1. Configure DNS: ${var.domain} -> ${hcloud_load_balancer.main.ipv4}
2. Add SSL certificate to load balancer in Hetzner Console
3. Copy deployer_hosts_config output to deploy.php
4. Create .env.local on shared directory with app_env_vars output
5. Run: vendor/bin/dep deploy production

================================================================================
EOT
}
