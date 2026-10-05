output "state_bucket" {
  description = "S3 bucket for Terraform state. Put this in the env backend.tf."
  value       = aws_s3_bucket.tf_state.bucket
}

output "lock_table" {
  description = "DynamoDB table for Terraform state locking."
  value       = aws_dynamodb_table.tf_lock.name
}

output "github_deploy_role_arn" {
  description = "OIDC role ARN for GitHub Actions. Set as a repo variable per environment."
  value       = aws_iam_role.github_deploy.arn
}

output "aws_account_id" {
  value = data.aws_caller_identity.current.account_id
}

output "aws_region" {
  value = var.aws_region
}
