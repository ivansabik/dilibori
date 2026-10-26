resource "aws_sns_topic" "alerts" {
  name = "dilibori-${var.environment}-alerts"

  tags = {
    Environment = var.environment
  }
}

resource "aws_sns_topic_subscription" "email" {
  topic_arn = aws_sns_topic.alerts.arn
  protocol  = "email"
  endpoint  = var.alert_email
}

resource "aws_cloudwatch_log_group" "box" {
  name              = "/dilibori/${var.environment}/box"
  retention_in_days = 30

  tags = {
    Environment = var.environment
  }
}

resource "aws_cloudwatch_log_group" "caddy" {
  name              = "/dilibori/${var.environment}/caddy"
  retention_in_days = 30

  tags = {
    Environment = var.environment
  }
}

resource "aws_cloudwatch_log_group" "app" {
  name              = "/dilibori/${var.environment}/app"
  retention_in_days = 30

  tags = {
    Environment = var.environment
  }
}

resource "aws_cloudwatch_log_group" "db" {
  name              = "/dilibori/${var.environment}/db"
  retention_in_days = 30

  tags = {
    Environment = var.environment
  }
}

resource "aws_cloudwatch_metric_alarm" "status_check" {
  alarm_name          = "dilibori-${var.environment}-status-check"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "StatusCheckFailed"
  namespace           = "AWS/EC2"
  period              = 60
  statistic           = "Maximum"
  threshold           = 0
  alarm_description   = "EC2 status check failed"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  ok_actions          = [aws_sns_topic.alerts.arn]

  dimensions = {
    InstanceId = var.instance_id
  }
}

resource "aws_cloudwatch_metric_alarm" "memory" {
  alarm_name          = "dilibori-${var.environment}-memory"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "mem_used_percent"
  namespace           = "CWAgent"
  period              = 60
  statistic           = "Average"
  threshold           = 85
  alarm_description   = "Memory above 85%"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "notBreaching"

  dimensions = {
    InstanceId = var.instance_id
  }
}

resource "aws_cloudwatch_metric_alarm" "disk_root" {
  alarm_name          = "dilibori-${var.environment}-disk-root"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "disk_used_percent"
  namespace           = "CWAgent"
  period              = 60
  statistic           = "Average"
  threshold           = 80
  alarm_description   = "Root disk above 80%"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "notBreaching"

  dimensions = {
    InstanceId = var.instance_id
    path       = "/"
    fstype     = "ext4"
    device     = "nvme0n1p1"
  }
}

resource "aws_cloudwatch_metric_alarm" "disk_data" {
  alarm_name          = "dilibori-${var.environment}-disk-data"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "disk_used_percent"
  namespace           = "CWAgent"
  period              = 60
  statistic           = "Average"
  threshold           = 80
  alarm_description   = "Data volume above 80%"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "notBreaching"

  dimensions = {
    InstanceId = var.instance_id
    path       = "/opt/dilibori"
    fstype     = "ext4"
  }
}

locals {
  services = [
    "app",
    "db",
    "caddy",
  ]
}

resource "aws_cloudwatch_metric_alarm" "service_health" {
  for_each            = toset(local.services)
  alarm_name          = "dilibori-${var.environment}-health-${each.value}"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 3
  metric_name         = each.value
  namespace           = "dilibori/health"
  period              = 60
  statistic           = "Minimum"
  threshold           = 1
  alarm_description   = "Service ${each.value} unhealthy or missing"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "breaching"

  dimensions = {
    Environment = var.environment
  }
}

resource "aws_cloudwatch_log_metric_filter" "caddy_5xx" {
  name           = "dilibori-${var.environment}-caddy-5xx"
  log_group_name = aws_cloudwatch_log_group.caddy.name
  pattern        = "{ $.status >= 500 }"

  metric_transformation {
    name          = "Caddy5xx"
    namespace     = "dilibori/caddy"
    value         = "1"
    default_value = "0"
  }
}

resource "aws_cloudwatch_metric_alarm" "caddy_5xx" {
  alarm_name          = "dilibori-${var.environment}-caddy-5xx"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  metric_name         = "Caddy5xx"
  namespace           = "dilibori/caddy"
  period              = 300
  statistic           = "Sum"
  threshold           = 20
  alarm_description   = "Caddy 5xx spike (>20 in 5 min)"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "notBreaching"
}

resource "aws_cloudwatch_log_metric_filter" "backup_ok" {
  name           = "dilibori-${var.environment}-backup-ok"
  log_group_name = aws_cloudwatch_log_group.box.name
  pattern        = "backup-ok"

  metric_transformation {
    name      = "BackupOk"
    namespace = "dilibori/backups"
    value     = "1"
  }
}

resource "aws_cloudwatch_metric_alarm" "backup_missing" {
  alarm_name          = "dilibori-${var.environment}-backup-missing"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  metric_name         = "BackupOk"
  namespace           = "dilibori/backups"
  period              = 86400
  statistic           = "Sum"
  threshold           = 1
  alarm_description   = "No successful nightly backup in the last day"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  treat_missing_data  = "breaching"
}

resource "aws_budgets_budget" "monthly" {
  name         = "dilibori-${var.environment}-monthly"
  budget_type  = "COST"
  limit_amount = tostring(var.budget_usd)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 80
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.alert_email]
  }

  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.alert_email]
  }
}
