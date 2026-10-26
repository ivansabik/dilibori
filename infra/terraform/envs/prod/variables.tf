variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "domain" {
  type        = string
  description = "Prod apex domain (e.g. example.com)."
}

variable "zone_id" {
  type    = string
  default = ""
}

variable "alert_email" {
  type = string
}

variable "budget_usd" {
  type    = number
  default = 100
}

variable "instance_type" {
  type    = string
  default = "t3a.medium"
}
