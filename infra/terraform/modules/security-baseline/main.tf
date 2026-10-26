resource "aws_s3_bucket" "cloudtrail" {
  bucket = "dilibori-cloudtrail-${var.environment}-${var.account_id}"

  tags = {
    Name        = "dilibori-${var.environment}-cloudtrail"
    Environment = var.environment
  }
}

resource "aws_s3_bucket_public_access_block" "cloudtrail" {
  bucket                  = aws_s3_bucket.cloudtrail.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "cloudtrail" {
  bucket = aws_s3_bucket.cloudtrail.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

data "aws_iam_policy_document" "cloudtrail_bucket" {
  statement {
    sid    = "AWSCloudTrailAclCheck"
    effect = "Allow"
    principals {
      type        = "Service"
      identifiers = ["cloudtrail.amazonaws.com"]
    }
    actions   = ["s3:GetBucketAcl"]
    resources = [aws_s3_bucket.cloudtrail.arn]
  }

  statement {
    sid    = "AWSCloudTrailWrite"
    effect = "Allow"
    principals {
      type        = "Service"
      identifiers = ["cloudtrail.amazonaws.com"]
    }
    actions   = ["s3:PutObject"]
    resources = ["${aws_s3_bucket.cloudtrail.arn}/AWSLogs/${var.account_id}/*"]
    condition {
      test     = "StringEquals"
      variable = "s3:x-amz-acl"
      values   = ["bucket-owner-full-control"]
    }
  }
}

resource "aws_s3_bucket_policy" "cloudtrail" {
  bucket = aws_s3_bucket.cloudtrail.id
  policy = data.aws_iam_policy_document.cloudtrail_bucket.json
}

resource "aws_cloudtrail" "this" {
  name                          = "dilibori-${var.environment}"
  s3_bucket_name                = aws_s3_bucket.cloudtrail.id
  include_global_service_events = true
  is_multi_region_trail         = true
  enable_log_file_validation    = true

  event_selector {
    read_write_type           = "All"
    include_management_events = true
  }

  depends_on = [aws_s3_bucket_policy.cloudtrail]

  tags = {
    Environment = var.environment
  }
}

resource "aws_guardduty_detector" "this" {
  enable = true

  tags = {
    Environment = var.environment
  }
}

resource "aws_s3_bucket" "ssm_sessions" {
  bucket = "dilibori-ssm-sessions-${var.environment}-${var.account_id}"

  tags = {
    Name        = "dilibori-${var.environment}-ssm-sessions"
    Environment = var.environment
  }
}

resource "aws_s3_bucket_public_access_block" "ssm_sessions" {
  bucket                  = aws_s3_bucket.ssm_sessions.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "ssm_sessions" {
  bucket = aws_s3_bucket.ssm_sessions.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_ssm_document" "session_prefs" {
  name            = "dilibori-${var.environment}-session-prefs"
  document_type   = "Session"
  document_format = "JSON"

  content = jsonencode({
    schemaVersion = "1.0"
    description   = "SSM session logging for dilibori ${var.environment}"
    sessionType   = "Standard_Stream"
    inputs = {
      s3BucketName                = aws_s3_bucket.ssm_sessions.id
      s3KeyPrefix                 = "sessions"
      s3EncryptionEnabled         = true
      cloudWatchLogGroupName      = "/dilibori/${var.environment}/ssm-sessions"
      cloudWatchEncryptionEnabled = true
      cloudWatchStreamingEnabled  = true
      idleSessionTimeout          = "20"
      maxSessionDuration          = "60"
    }
  })
}

resource "aws_cloudwatch_log_group" "ssm_sessions" {
  name              = "/dilibori/${var.environment}/ssm-sessions"
  retention_in_days = 30
}
