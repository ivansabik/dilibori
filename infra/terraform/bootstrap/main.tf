data "aws_caller_identity" "current" {}

locals {
  name_prefix = "dilibori-${var.environment}"
  # Dev: PR runs and the GitHub Environment named "dev".
  # Prod: only the GitHub Environment named "prod" (manual approval).
  oidc_subs = var.environment == "dev" ? [
    "repo:${var.github_org}/${var.github_repo}:pull_request",
    "repo:${var.github_org}/${var.github_repo}:environment:dev",
    ] : [
    "repo:${var.github_org}/${var.github_repo}:environment:prod",
  ]
}

resource "aws_s3_bucket" "tf_state" {
  bucket = "${local.name_prefix}-tfstate-${data.aws_caller_identity.current.account_id}"

  tags = {
    Name        = "${local.name_prefix}-tfstate"
    Environment = var.environment
    ManagedBy   = "terraform-bootstrap"
  }
}

resource "aws_s3_bucket_versioning" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "tf_state" {
  bucket                  = aws_s3_bucket.tf_state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_dynamodb_table" "tf_lock" {
  name         = "${local.name_prefix}-tf-lock"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "LockID"

  attribute {
    name = "LockID"
    type = "S"
  }

  tags = {
    Name        = "${local.name_prefix}-tf-lock"
    Environment = var.environment
    ManagedBy   = "terraform-bootstrap"
  }
}

resource "aws_iam_openid_connect_provider" "github" {
  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = ["ffffffffffffffffffffffffffffffffffffffff"]

  tags = {
    Name        = "${local.name_prefix}-github-oidc"
    Environment = var.environment
    ManagedBy   = "terraform-bootstrap"
  }
}

data "aws_iam_policy_document" "github_assume" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    condition {
      test     = "StringLike"
      variable = "token.actions.githubusercontent.com:sub"
      values   = local.oidc_subs
    }
  }
}

resource "aws_iam_role" "github_deploy" {
  name               = "${local.name_prefix}-github-deploy"
  assume_role_policy = data.aws_iam_policy_document.github_assume.json

  tags = {
    Name        = "${local.name_prefix}-github-deploy"
    Environment = var.environment
    ManagedBy   = "terraform-bootstrap"
  }
}

# ponytail: AdministratorAccess for the first cut; scope down after the
# first successful PR deploy (upgrade path: least-privilege deploy policy).
resource "aws_iam_role_policy_attachment" "github_deploy_admin" {
  role       = aws_iam_role.github_deploy.name
  policy_arn = "arn:aws:iam::aws:policy/AdministratorAccess"
}
