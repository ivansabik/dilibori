output "artifacts_bucket" {
  value = aws_s3_bucket.this["artifacts"].id
}

output "backups_bucket" {
  value = aws_s3_bucket.this["backups"].id
}

output "media_bucket" {
  value = aws_s3_bucket.this["media"].id
}

output "artifacts_bucket_arn" {
  value = aws_s3_bucket.this["artifacts"].arn
}

output "backups_bucket_arn" {
  value = aws_s3_bucket.this["backups"].arn
}

output "media_bucket_arn" {
  value = aws_s3_bucket.this["media"].arn
}
