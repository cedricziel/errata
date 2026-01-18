# -----------------------------------------------------------------------------
# SSH Key for Server Access
# -----------------------------------------------------------------------------

resource "hcloud_ssh_key" "deploy" {
  name       = var.ssh_key_name
  public_key = var.ssh_public_key

  labels = local.common_labels
}
