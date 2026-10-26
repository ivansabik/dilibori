variable "environment" {
  type = string
}

variable "alert_email" {
  type = string
}

variable "instance_id" {
  type = string
}

variable "budget_usd" {
  type    = number
  default = 80
}

variable "aws_account_id" {
  type = string
}
