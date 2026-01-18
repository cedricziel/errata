# -----------------------------------------------------------------------------
# Load Balancer
# -----------------------------------------------------------------------------

resource "hcloud_load_balancer" "main" {
  name               = "${var.project_name}-lb"
  load_balancer_type = var.load_balancer_type
  location           = var.location

  labels = local.common_labels
}

# -----------------------------------------------------------------------------
# Load Balancer Network Attachment
# -----------------------------------------------------------------------------

resource "hcloud_load_balancer_network" "main" {
  load_balancer_id = hcloud_load_balancer.main.id
  network_id       = hcloud_network.main.id
  ip               = "10.0.1.2"

  depends_on = [hcloud_network_subnet.main]
}

# -----------------------------------------------------------------------------
# Load Balancer Service: HTTPS
# -----------------------------------------------------------------------------

resource "hcloud_load_balancer_service" "https" {
  load_balancer_id = hcloud_load_balancer.main.id
  protocol         = "https"
  listen_port      = 443
  destination_port = 80

  http {
    sticky_sessions = true
    cookie_name     = "ERRATA_LB"
    cookie_lifetime = 300
  }

  health_check {
    protocol = "http"
    port     = 80
    interval = 10
    timeout  = 5
    retries  = 3

    http {
      path         = "/health"
      status_codes = ["200"]
    }
  }
}

# -----------------------------------------------------------------------------
# Load Balancer Service: HTTP (Redirect to HTTPS)
# -----------------------------------------------------------------------------

resource "hcloud_load_balancer_service" "http" {
  load_balancer_id = hcloud_load_balancer.main.id
  protocol         = "http"
  listen_port      = 80
  destination_port = 80

  health_check {
    protocol = "http"
    port     = 80
    interval = 10
    timeout  = 5
    retries  = 3

    http {
      path         = "/health"
      status_codes = ["200"]
    }
  }
}

# -----------------------------------------------------------------------------
# Load Balancer Targets: Web Servers (Dynamic)
# -----------------------------------------------------------------------------

resource "hcloud_load_balancer_target" "web" {
  count            = var.web_count
  type             = "server"
  load_balancer_id = hcloud_load_balancer.main.id
  server_id        = hcloud_server.web[count.index].id
  use_private_ip   = true

  depends_on = [hcloud_load_balancer_network.main]
}
