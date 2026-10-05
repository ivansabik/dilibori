variable "aws_region" {
  type        = string
  description = "Region for the state bucket, lock table, and OIDC role."
  default     = "us-east-1"
}

variable "environment" {
  type        = string
  description = "Environment name (dev or prod). Used in resource names and OIDC trust."

  validation {
    condition     = contains(["dev", "prod"], var.environment)
    error_message = "environment must be dev or prod."
  }
}

variable "github_org" {
  type        = string
  description = "GitHub org or user that owns the repo."
  default     = "ivansabik"
}

variable "github_repo" {
  type        = string
  description = "GitHub repository name."
  default     = "dilibori"
}
