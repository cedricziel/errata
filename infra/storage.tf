# -----------------------------------------------------------------------------
# S3-Compatible Object Storage for Parquet Files
# -----------------------------------------------------------------------------
# Note: Hetzner Object Storage is managed via the Hetzner Cloud Console or API.
# This resource creates a placeholder for documentation purposes.
#
# To create a bucket:
# 1. Go to Hetzner Cloud Console > Object Storage
# 2. Create a new bucket named: ${var.storage_bucket_name}
# 3. Create access credentials and store them securely
#
# Alternatively, use the hcloud CLI:
#   hcloud object-storage bucket create ${var.storage_bucket_name}
# -----------------------------------------------------------------------------

# Output storage configuration for the application
locals {
  storage_config = {
    bucket_name = var.storage_bucket_name
    endpoint    = "https://fsn1.your-objectstorage.com"
    region      = "fsn1"
  }
}

# -----------------------------------------------------------------------------
# Note: Hetzner Object Storage is not yet fully supported in Terraform.
# The bucket must be created manually or via the Hetzner API.
#
# For a fully Terraform-managed solution, consider:
# - Using local parquet storage on the infra server
# - Using a third-party S3 provider (AWS S3, MinIO, etc.)
# -----------------------------------------------------------------------------
