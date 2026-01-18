# -----------------------------------------------------------------------------
# Provider Configuration
# -----------------------------------------------------------------------------

variable "hcloud_token" {
  description = "Hetzner Cloud API token"
  type        = string
  sensitive   = true
}

# -----------------------------------------------------------------------------
# Location & Naming
# -----------------------------------------------------------------------------

variable "location" {
  description = "Hetzner Cloud datacenter location"
  type        = string
  default     = "nbg1"
}

variable "environment" {
  description = "Environment name (e.g., production, staging)"
  type        = string
  default     = "production"
}

variable "project_name" {
  description = "Project name used for resource naming"
  type        = string
  default     = "errata"
}

# -----------------------------------------------------------------------------
# Server Configuration
# -----------------------------------------------------------------------------

variable "server_type" {
  description = "Hetzner Cloud server type for web and worker instances"
  type        = string
  default     = "cx23"
}

variable "infra_server_type" {
  description = "Hetzner Cloud server type for infrastructure (PostgreSQL + Redis)"
  type        = string
  default     = "cx23"
}

variable "server_image" {
  description = "Server OS image"
  type        = string
  default     = "ubuntu-24.04"
}

# -----------------------------------------------------------------------------
# Scaling Configuration
# -----------------------------------------------------------------------------

variable "web_count" {
  description = "Number of web server instances"
  type        = number
  default     = 1

  validation {
    condition     = var.web_count >= 1
    error_message = "At least one web server is required."
  }
}

variable "worker_count" {
  description = "Number of worker instances"
  type        = number
  default     = 1

  validation {
    condition     = var.worker_count >= 1
    error_message = "At least one worker is required."
  }
}

# -----------------------------------------------------------------------------
# Network Configuration
# -----------------------------------------------------------------------------

variable "network_cidr" {
  description = "CIDR block for the private network"
  type        = string
  default     = "10.0.0.0/16"
}

variable "subnet_cidr" {
  description = "CIDR block for the subnet"
  type        = string
  default     = "10.0.1.0/24"
}

# -----------------------------------------------------------------------------
# SSH Configuration
# -----------------------------------------------------------------------------

variable "ssh_public_key" {
  description = "SSH public key for server access"
  type        = string
}

variable "ssh_key_name" {
  description = "Name for the SSH key resource"
  type        = string
  default     = "errata-deploy"
}

# -----------------------------------------------------------------------------
# Load Balancer Configuration
# -----------------------------------------------------------------------------

variable "load_balancer_type" {
  description = "Hetzner Cloud load balancer type"
  type        = string
  default     = "lb11"
}

variable "domain" {
  description = "Primary domain for the application"
  type        = string
}

# -----------------------------------------------------------------------------
# Object Storage Configuration
# -----------------------------------------------------------------------------

variable "storage_bucket_name" {
  description = "Name for the S3-compatible object storage bucket"
  type        = string
  default     = "errata-parquet"
}

# -----------------------------------------------------------------------------
# Database Configuration
# -----------------------------------------------------------------------------

variable "postgres_password" {
  description = "PostgreSQL password for the errata database user"
  type        = string
  sensitive   = true
}

variable "redis_password" {
  description = "Redis password (optional, leave empty for no auth)"
  type        = string
  sensitive   = true
  default     = ""
}

# -----------------------------------------------------------------------------
# Application Configuration
# -----------------------------------------------------------------------------

variable "app_secret" {
  description = "Symfony APP_SECRET for encryption"
  type        = string
  sensitive   = true
}
