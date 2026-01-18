# -----------------------------------------------------------------------------
# Infrastructure Server (PostgreSQL + Redis)
# -----------------------------------------------------------------------------

resource "hcloud_server" "infra" {
  name        = "${var.project_name}-infra"
  server_type = var.infra_server_type
  image       = var.server_image
  location    = var.location
  ssh_keys    = [hcloud_ssh_key.deploy.id]

  labels = merge(local.common_labels, {
    role = "infra"
  })

  user_data = templatefile("${path.module}/cloud-init/infra.yaml", {
    ssh_public_key    = var.ssh_public_key
    postgres_password = var.postgres_password
    redis_password    = var.redis_password
  })

  firewall_ids = [hcloud_firewall.infra.id]

  network {
    network_id = hcloud_network.main.id
    ip         = "10.0.1.10"
  }

  depends_on = [hcloud_network_subnet.main]
}

# -----------------------------------------------------------------------------
# Web Servers (Scalable)
# -----------------------------------------------------------------------------

resource "hcloud_server" "web" {
  count       = var.web_count
  name        = "${var.project_name}-web-${count.index + 1}"
  server_type = var.server_type
  image       = var.server_image
  location    = var.location
  ssh_keys    = [hcloud_ssh_key.deploy.id]

  labels = merge(local.common_labels, {
    role  = "web"
    index = tostring(count.index + 1)
  })

  user_data = templatefile("${path.module}/cloud-init/web.yaml", {
    ssh_public_key = var.ssh_public_key
    domain         = var.domain
  })

  firewall_ids = [hcloud_firewall.web.id]

  network {
    network_id = hcloud_network.main.id
    ip         = "10.0.1.${20 + count.index}"
  }

  depends_on = [hcloud_network_subnet.main]
}

# -----------------------------------------------------------------------------
# Worker Servers (Scalable)
# -----------------------------------------------------------------------------

resource "hcloud_server" "worker" {
  count       = var.worker_count
  name        = "${var.project_name}-worker-${count.index + 1}"
  server_type = var.server_type
  image       = var.server_image
  location    = var.location
  ssh_keys    = [hcloud_ssh_key.deploy.id]

  labels = merge(local.common_labels, {
    role  = "worker"
    index = tostring(count.index + 1)
  })

  user_data = templatefile("${path.module}/cloud-init/worker.yaml", {
    ssh_public_key = var.ssh_public_key
  })

  firewall_ids = [hcloud_firewall.worker.id]

  network {
    network_id = hcloud_network.main.id
    ip         = "10.0.1.${30 + count.index}"
  }

  depends_on = [hcloud_network_subnet.main]
}
