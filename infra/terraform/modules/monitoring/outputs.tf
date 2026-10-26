output "sns_topic_arn" {
  value = aws_sns_topic.alerts.arn
}

output "platform_log_group" {
  value = aws_cloudwatch_log_group.box.name
}

output "caddy_log_group" {
  value = aws_cloudwatch_log_group.caddy.name
}
