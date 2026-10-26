variable "environment" {
  type = string
}

variable "enable_ses_smtp" {
  type        = bool
  description = "Include placeholder SES SMTP fields in the app secret (prod)."
  default     = false
}
