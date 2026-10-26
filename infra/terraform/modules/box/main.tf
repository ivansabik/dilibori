data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "box" {
  name               = "dilibori-${var.environment}-box"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json

  tags = {
    Name        = "dilibori-${var.environment}-box"
    Environment = var.environment
  }
}

resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.box.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_role_policy_attachment" "cloudwatch_agent" {
  role       = aws_iam_role.box.name
  policy_arn = "arn:aws:iam::aws:policy/CloudWatchAgentServerPolicy"
}

data "aws_iam_policy_document" "box" {
  statement {
    sid = "Secrets"
    actions = [
      "secretsmanager:GetSecretValue",
      "secretsmanager:DescribeSecret",
    ]
    resources = [var.secret_arn]
  }

  statement {
    sid = "ArtifactsRead"
    actions = [
      "s3:GetObject",
      "s3:ListBucket",
    ]
    resources = [
      var.artifacts_bucket_arn,
      "${var.artifacts_bucket_arn}/*",
    ]
  }

  statement {
    sid = "BackupsWrite"
    actions = [
      "s3:PutObject",
      "s3:GetObject",
      "s3:ListBucket",
      "s3:DeleteObject",
    ]
    resources = [
      var.backups_bucket_arn,
      "${var.backups_bucket_arn}/*",
    ]
  }

  statement {
    sid = "MediaFull"
    actions = [
      "s3:*",
    ]
    resources = [
      var.media_bucket_arn,
      "${var.media_bucket_arn}/*",
    ]
  }

  statement {
    sid = "EcrPull"
    actions = [
      "ecr:GetAuthorizationToken",
      "ecr:BatchCheckLayerAvailability",
      "ecr:GetDownloadUrlForLayer",
      "ecr:BatchGetImage",
    ]
    resources = ["*"]
  }

  statement {
    sid = "SsmParams"
    actions = [
      "ssm:GetParameter",
      "ssm:PutParameter",
      "ssm:GetParameters",
    ]
    resources = [
      "arn:aws:ssm:${var.aws_region}:*:parameter/dilibori/${var.environment}/*",
    ]
  }

  statement {
    sid = "AppLogs"
    actions = [
      "logs:CreateLogStream",
      "logs:PutLogEvents",
      "logs:DescribeLogStreams",
    ]
    resources = [
      "arn:aws:logs:${var.aws_region}:*:log-group:/dilibori/${var.environment}/*",
      "arn:aws:logs:${var.aws_region}:*:log-group:/dilibori/${var.environment}/*:log-stream:*",
    ]
  }

  statement {
    sid       = "CloudWatchPut"
    actions   = ["cloudwatch:PutMetricData"]
    resources = ["*"]
    condition {
      test     = "StringEquals"
      variable = "cloudwatch:namespace"
      values   = ["dilibori/health", "dilibori/backups"]
    }
  }
}

resource "aws_iam_role_policy" "box" {
  name   = "dilibori-${var.environment}-box"
  role   = aws_iam_role.box.id
  policy = data.aws_iam_policy_document.box.json
}

resource "aws_iam_instance_profile" "box" {
  name = "dilibori-${var.environment}-box"
  role = aws_iam_role.box.name
}

resource "aws_eip" "box" {
  domain = "vpc"

  tags = {
    Name        = "dilibori-${var.environment}-box"
    Environment = var.environment
  }
}

resource "aws_ebs_volume" "data" {
  availability_zone = var.availability_zone
  size              = var.data_volume_size_gb
  type              = "gp3"
  encrypted         = true

  tags = {
    Name        = "dilibori-${var.environment}-data"
    Environment = var.environment
  }
}

resource "aws_ssm_parameter" "release_sha" {
  name  = "/dilibori/${var.environment}/release_sha"
  type  = "String"
  value = "bootstrap"

  lifecycle {
    ignore_changes = [value]
  }

  tags = {
    Environment = var.environment
  }
}

locals {
  user_data = templatefile("${path.module}/../../../box/user-data.sh", {
    environment      = var.environment
    aws_region       = var.aws_region
    secret_name      = "dilibori/${var.environment}/platform"
    artifacts_bucket = var.artifacts_bucket
    backups_bucket   = var.backups_bucket
    media_bucket     = var.media_bucket
    domain           = var.domain
    release_param    = aws_ssm_parameter.release_sha.name
    data_device_name = "/dev/nvme1n1"
  })
}

# ponytail: single instance, single AZ, in-place deploys. Replacement is a
# runbook (taint instance; data volume reattaches; user-data redeploys the
# recorded tag). Upgrade path: EFS data dir + ASG.
resource "aws_instance" "box" {
  ami                         = data.aws_ami.ubuntu.id
  instance_type               = var.instance_type
  subnet_id                   = var.subnet_id
  vpc_security_group_ids      = [var.security_group_id]
  iam_instance_profile        = aws_iam_instance_profile.box.name
  user_data_base64            = base64encode(local.user_data)
  user_data_replace_on_change = false

  root_block_device {
    volume_type = "gp3"
    volume_size = 30
    encrypted   = true
  }

  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 2
  }

  tags = {
    Name        = "dilibori-${var.environment}-box"
    Environment = var.environment
    Role        = "platform-box"
  }
}

resource "aws_eip_association" "box" {
  instance_id   = aws_instance.box.id
  allocation_id = aws_eip.box.id
}

resource "aws_volume_attachment" "data" {
  device_name = "/dev/sdf"
  volume_id   = aws_ebs_volume.data.id
  instance_id = aws_instance.box.id
}

resource "aws_dlm_lifecycle_policy" "data" {
  description        = "Daily snapshots of dilibori-${var.environment} data volume"
  execution_role_arn = aws_iam_role.dlm.arn
  state              = "ENABLED"

  policy_details {
    resource_types = ["VOLUME"]

    target_tags = {
      Name = "dilibori-${var.environment}-data"
    }

    schedule {
      name = "daily"

      create_rule {
        interval      = 24
        interval_unit = "HOURS"
        times         = ["05:00"]
      }

      retain_rule {
        count = 7
      }

      copy_tags = true
    }
  }

  tags = {
    Environment = var.environment
  }
}

data "aws_iam_policy_document" "dlm_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["dlm.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "dlm" {
  name               = "dilibori-${var.environment}-dlm"
  assume_role_policy = data.aws_iam_policy_document.dlm_assume.json
}

resource "aws_iam_role_policy_attachment" "dlm" {
  role       = aws_iam_role.dlm.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSDataLifecycleManagerServiceRole"
}
