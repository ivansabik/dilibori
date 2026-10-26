data "aws_caller_identity" "current" {}

module "network" {
  source      = "../../modules/network"
  environment = "prod"
}

module "storage" {
  source      = "../../modules/storage"
  environment = "prod"
  account_id  = data.aws_caller_identity.current.account_id
}

module "secrets" {
  source          = "../../modules/secrets"
  environment     = "prod"
  enable_ses_smtp = true
}

module "ecr" {
  source      = "../../modules/ecr"
  environment = "prod"
}

module "box" {
  source               = "../../modules/box"
  environment          = "prod"
  instance_type        = var.instance_type
  subnet_id            = module.network.public_subnet_id
  security_group_id    = module.network.box_security_group_id
  availability_zone    = module.network.availability_zone
  secret_arn           = module.secrets.secret_arn
  artifacts_bucket     = module.storage.artifacts_bucket
  artifacts_bucket_arn = module.storage.artifacts_bucket_arn
  backups_bucket       = module.storage.backups_bucket
  backups_bucket_arn   = module.storage.backups_bucket_arn
  media_bucket         = module.storage.media_bucket
  media_bucket_arn     = module.storage.media_bucket_arn
  aws_region           = var.aws_region
  domain               = var.domain
}

module "dns" {
  source        = "../../modules/dns"
  environment   = "prod"
  domain        = var.domain
  zone_id       = var.zone_id
  eip_public_ip = module.box.public_ip
}

module "monitoring" {
  source         = "../../modules/monitoring"
  environment    = "prod"
  alert_email    = var.alert_email
  instance_id    = module.box.instance_id
  budget_usd     = var.budget_usd
  aws_account_id = data.aws_caller_identity.current.account_id
}

module "security_baseline" {
  source      = "../../modules/security-baseline"
  environment = "prod"
  account_id  = data.aws_caller_identity.current.account_id
  aws_region  = var.aws_region
}
