variable "environment" {
  type = string
}

variable "domain" {
  type        = string
  description = "Apex or env domain (e.g. example.com or dev.example.com)."
}

variable "eip_public_ip" {
  type = string
}

variable "zone_id" {
  type        = string
  description = "Optional existing Route53 zone ID. Empty looks up var.domain."
  default     = ""
}
