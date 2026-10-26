locals {
  buckets = {
    artifacts = {
      name        = "dilibori-artifacts-${var.environment}-${var.account_id}"
      versioning  = false
      expiry_days = 30
    }
    backups = {
      name        = "dilibori-backups-${var.environment}-${var.account_id}"
      versioning  = true
      expiry_days = 30
    }
    media = {
      name        = "dilibori-media-${var.environment}-${var.account_id}"
      versioning  = true
      expiry_days = 0
    }
  }
}

resource "aws_s3_bucket" "this" {
  for_each = local.buckets
  bucket   = each.value.name

  tags = {
    Name        = each.value.name
    Environment = var.environment
    Purpose     = each.key
  }
}

resource "aws_s3_bucket_versioning" "this" {
  for_each = local.buckets
  bucket   = aws_s3_bucket.this[each.key].id

  versioning_configuration {
    status = each.value.versioning ? "Enabled" : "Suspended"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "this" {
  for_each = local.buckets
  bucket   = aws_s3_bucket.this[each.key].id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "this" {
  for_each = local.buckets
  bucket   = aws_s3_bucket.this[each.key].id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "expiry" {
  for_each = { for k, v in local.buckets : k => v if v.expiry_days > 0 }
  bucket   = aws_s3_bucket.this[each.key].id

  rule {
    id     = "expire-objects"
    status = "Enabled"

    filter {}

    expiration {
      days = each.value.expiry_days
    }

    noncurrent_version_expiration {
      noncurrent_days = each.value.expiry_days
    }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "media_versions" {
  bucket = aws_s3_bucket.this["media"].id

  rule {
    id     = "expire-old-versions"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = 30
    }
  }
}
