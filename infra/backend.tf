# -----------------------------------------------------------------------------
# Terraform State Backend: Hetzner Object Storage (S3-compatible)
# -----------------------------------------------------------------------------
# Before using this backend:
# 1. Create a bucket in Hetzner Cloud Console (Object Storage)
# 2. Create access credentials (Access Key + Secret Key)
# 3. Copy backend.hcl.example to backend.hcl and fill in credentials
# 4. Run: terraform init -backend-config=backend.hcl
# -----------------------------------------------------------------------------

terraform {
  backend "s3" {
    # All backend config is provided via backend.hcl
    key    = "production/terraform.tfstate"
    region = "eu-central-1"  # Required but ignored by Hetzner

    # Disable AWS-specific features
    skip_credentials_validation = true
    skip_requesting_account_id  = true
    skip_metadata_api_check     = true
    skip_region_validation      = true
    use_path_style              = true
  }
}
