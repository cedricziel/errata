# -----------------------------------------------------------------------------
# Hetzner Cloud Provider Configuration
# -----------------------------------------------------------------------------

provider "hcloud" {
  token = var.hcloud_token
}

# -----------------------------------------------------------------------------
# Local Values
# -----------------------------------------------------------------------------

locals {
  common_labels = {
    project     = var.project_name
    environment = var.environment
    managed_by  = "terraform"
  }
}
