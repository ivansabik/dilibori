output "cloudtrail_bucket" {
  value = aws_s3_bucket.cloudtrail.id
}

output "ssm_sessions_bucket" {
  value = aws_s3_bucket.ssm_sessions.id
}
