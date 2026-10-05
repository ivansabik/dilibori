variable "environment" {
  type = string
}

variable "prod_account_id" {
  type        = string
  description = "When set on the dev env, grants this account pull access for image promotion."
  default     = ""
}
