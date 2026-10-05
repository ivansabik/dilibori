resource "random_password" "mariadb_root" {
  length  = 32
  special = false
}

resource "random_password" "proxysql_admin" {
  length  = 32
  special = false
}

resource "random_password" "postgres" {
  length  = 32
  special = false
}

resource "random_password" "jobsdb" {
  length  = 32
  special = false
}

resource "random_password" "runtime_agent_token" {
  length  = 48
  special = false
}

resource "random_password" "app_secret_key" {
  length  = 48
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
      MARIADB_ROOT_PASSWORD   = random_password.mariadb_root.result
      PROXYSQL_ADMIN_PASSWORD = random_password.proxysql_admin.result
      POSTGRES_PASSWORD       = random_password.postgres.result
      JOBS_DB_PASSWORD        = random_password.jobsdb.result
      RUNTIME_AGENT_TOKEN     = random_password.runtime_agent_token.result
      APP_SECRET_KEY          = random_password.app_secret_key.result
    },
    var.enable_ses_smtp ? {
      TENANT_MAIL_HOST       = "email-smtp.us-east-1.amazonaws.com"
      TENANT_MAIL_PORT       = "587"
      TENANT_MAIL_USERNAME   = "REPLACE_AFTER_SES_SETUP"
      TENANT_MAIL_PASSWORD   = random_password.ses_smtp_password[0].result
      TENANT_MAIL_ENCRYPTION = "tls"
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
