resource "random_password" "mariadb_root" {
  length  = 32
  special = false
}

resource "random_password" "db" {
  length  = 32
  special = false
}

resource "random_password" "ses_smtp_password" {
  count   = var.enable_ses_smtp ? 1 : 0
  length  = 32
  special = false
}

locals {
  secret_value = merge(
    {
      MARIADB_ROOT_PASSWORD = random_password.mariadb_root.result
      DB_PASSWORD           = random_password.db.result
    },
    var.enable_ses_smtp ? {
      MAIL_MAILER     = "smtp"
      MAIL_HOST       = "email-smtp.us-east-1.amazonaws.com"
      MAIL_PORT       = "587"
      MAIL_USERNAME   = "REPLACE_AFTER_SES_SETUP"
      MAIL_PASSWORD   = random_password.ses_smtp_password[0].result
      MAIL_ENCRYPTION = "tls"
    } : {}
  )
}

resource "aws_secretsmanager_secret" "platform" {
  name                    = "dilibori/${var.environment}/platform"
  recovery_window_in_days = var.environment == "prod" ? 30 : 0

  tags = {
    Name        = "dilibori-${var.environment}-platform"
    Environment = var.environment
  }
}

resource "aws_secretsmanager_secret_version" "platform" {
  secret_id     = aws_secretsmanager_secret.platform.id
  secret_string = jsonencode(local.secret_value)
}
