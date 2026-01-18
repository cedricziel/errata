# -----------------------------------------------------------------------------
# Private Network
# -----------------------------------------------------------------------------

resource "hcloud_network" "main" {
  name     = "${var.project_name}-network"
  ip_range = var.network_cidr

  labels = local.common_labels
}

# -----------------------------------------------------------------------------
# Subnet
# -----------------------------------------------------------------------------

resource "hcloud_network_subnet" "main" {
  network_id   = hcloud_network.main.id
  type         = "cloud"
  network_zone = "eu-central"
  ip_range     = var.subnet_cidr
}
