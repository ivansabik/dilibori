variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "domain" {
  type        = string
  description = "Dev env domain (e.g. dev.example.com). Zone must exist in this account."
}

variable "zone_id" {
  type        = string
  description = "Optional Route53 zone ID for var.domain."
  default     = ""
}

variable "alert_email" {
  type = string
}

variable "budget_usd" {
  type    = number
  default = 80
}

variable "instance_type" {
  type    = string
  default = "t3a.medium"
}

variable "prod_account_id" {
  type        = string
  description = "Prod AWS account ID for ECR cross-account pull."
  default     = ""
}
