# -----------------------------------------------------------------------------
# Firewall: Infrastructure Server (PostgreSQL + Redis)
# -----------------------------------------------------------------------------

resource "hcloud_firewall" "infra" {
  name = "${var.project_name}-firewall-infra"

  labels = local.common_labels

  # SSH access (restrict to known IPs in production)
  rule {
    description = "SSH access"
    direction   = "in"
    protocol    = "tcp"
    port        = "22"
    source_ips  = ["0.0.0.0/0", "::/0"]
  }

  # PostgreSQL from private network only
  rule {
    description = "PostgreSQL from private network"
    direction   = "in"
    protocol    = "tcp"
    port        = "5432"
    source_ips  = [var.network_cidr]
  }

  # Redis from private network only
  rule {
    description = "Redis from private network"
    direction   = "in"
    protocol    = "tcp"
    port        = "6379"
    source_ips  = [var.network_cidr]
  }

  # ICMP (ping) from private network
  rule {
    description = "ICMP from private network"
    direction   = "in"
    protocol    = "icmp"
    source_ips  = [var.network_cidr]
  }
}

# -----------------------------------------------------------------------------
# Firewall: Web Servers
# -----------------------------------------------------------------------------

resource "hcloud_firewall" "web" {
  name = "${var.project_name}-firewall-web"

  labels = local.common_labels

  # SSH access
  rule {
    description = "SSH access"
    direction   = "in"
    protocol    = "tcp"
    port        = "22"
    source_ips  = ["0.0.0.0/0", "::/0"]
  }

  # HTTP (for health checks and Let's Encrypt)
  rule {
    description = "HTTP"
    direction   = "in"
    protocol    = "tcp"
    port        = "80"
    source_ips  = ["0.0.0.0/0", "::/0"]
  }

  # HTTPS (direct access if needed)
  rule {
    description = "HTTPS"
    direction   = "in"
    protocol    = "tcp"
    port        = "443"
    source_ips  = ["0.0.0.0/0", "::/0"]
  }

  # ICMP from private network
  rule {
    description = "ICMP from private network"
    direction   = "in"
    protocol    = "icmp"
    source_ips  = [var.network_cidr]
  }
}

# -----------------------------------------------------------------------------
# Firewall: Worker Servers
# -----------------------------------------------------------------------------

resource "hcloud_firewall" "worker" {
  name = "${var.project_name}-firewall-worker"

  labels = local.common_labels

  # SSH access
  rule {
    description = "SSH access"
    direction   = "in"
    protocol    = "tcp"
    port        = "22"
    source_ips  = ["0.0.0.0/0", "::/0"]
  }

  # ICMP from private network
  rule {
    description = "ICMP from private network"
    direction   = "in"
    protocol    = "icmp"
    source_ips  = [var.network_cidr]
  }
}
