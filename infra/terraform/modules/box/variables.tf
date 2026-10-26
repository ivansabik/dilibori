variable "environment" {
  type = string
}

variable "instance_type" {
  type    = string
  default = "t3a.medium"
}

variable "subnet_id" {
  type = string
}

variable "security_group_id" {
  type = string
}

variable "availability_zone" {
  type = string
}

variable "secret_arn" {
  type = string
}

variable "artifacts_bucket" {
  type = string
}

variable "artifacts_bucket_arn" {
  type = string
}

variable "backups_bucket" {
  type = string
}

variable "backups_bucket_arn" {
  type = string
}

variable "media_bucket" {
  type = string
}

variable "media_bucket_arn" {
  type = string
}

variable "aws_region" {
  type = string
}

variable "domain" {
  type = string
}

variable "data_volume_size_gb" {
  type    = number
  default = 40
}
